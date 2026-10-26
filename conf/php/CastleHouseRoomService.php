<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Appartment;
use App\Entity\OnlineBookingConfig;
use App\Entity\Price;
use App\Entity\Reservation;
use App\Entity\RoomCategory;
use App\Repository\AppartmentRepository;
use App\Service\OnlineBooking\OnlineBookingConfigService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Add one room to the website's existing property; never modify existing stays. */
final class CastleHouseRoomService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AppartmentRepository $rooms,
        private readonly OnlineBookingConfigService $config,
    ) {}

    public function ensure(array $input): array
    {
        $key = (string) ($input['key'] ?? '');
        $label = trim((string) ($input['label'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $anchors = json_decode((string) ($input['anchors'] ?? ''), true);
        $nightly = filter_var($input['nightlyPence'] ?? null, FILTER_VALIDATE_INT);
        $weekend = ($input['weekendPence'] ?? '') === '' ? $nightly : filter_var($input['weekendPence'], FILTER_VALIDATE_INT);
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,39}$/D', $key) || mb_strlen($label) < 4 || mb_strlen($label) > 80
            || mb_strlen($description) > 800 || preg_match('/[\x00-\x1f]/', $label)
            || !is_array($anchors) || !$anchors || count($anchors) > 50
            || !is_int($nightly) || $nightly < 100 || $nightly > 999900
            || !is_int($weekend) || $weekend < 100 || $weekend > 999900) {
            throw new BadRequestHttpException('invalid_room');
        }
        foreach ($anchors as $anchor) {
            if (!is_string($anchor) || mb_strlen($anchor) < 4 || mb_strlen($anchor) > 80) {
                throw new BadRequestHttpException('invalid_room');
            }
        }
        $config = $this->config->getConfig();
        if (!$config->isEnabled()) {
            throw new ConflictHttpException('booking_disabled');
        }
        return $this->em->wrapInTransaction(function () use ($key, $label, $description, $anchors, $nightly, $weekend, $config, $input): array {
            // Refresh after taking the shared config lock: concurrent requests must
            // see the first request's room and selected-room list before creating.
            $this->em->refresh($config, LockMode::PESSIMISTIC_WRITE);
            $matched = $this->anchors($anchors, $config);
            $property = $matched[0]->getObject();
            foreach ($matched as $anchor) {
                if ($anchor->getObject()->getId() !== $property->getId()) {
                    throw new ConflictHttpException('ambiguous_property');
                }
            }
            $number = 'CH'.substr(hash('sha256', $key), 0, 8);
            $existing = $this->rooms->findBy(['object' => $property]); // Includes inactive rooms.
            $sameKey = array_values(array_filter($existing, fn (Appartment $room): bool => $room->getNumber() === $number));
            $sameName = array_values(array_filter($existing, fn (Appartment $room): bool => self::label($room) === self::normalise($label)));
            if (count($sameKey) > 1 || count($sameName) > 1 || ($sameKey && $sameName && $sameKey[0] !== $sameName[0])) {
                throw new ConflictHttpException('ambiguous_room');
            }
            $room = $sameKey[0] ?? $sameName[0] ?? null;
            if ($room !== null) {
                $expectedId = (string)($input['roomId'] ?? '');
                if (self::label($room) !== self::normalise($label) || $room->isMultipleOccupancy()
                    || ($expectedId !== '' && $expectedId !== (string)$room->getId())
                    || (!$room->isActive() && !$sameKey && $expectedId !== (string)$room->getId())
                    || (int) $room->getBedsMax() !== 2) {
                    throw new ConflictHttpException('room_changed');
                }
                $room->setActive(true); // Explicitly publishing a restored draft reopens its inventory.
            } else {
                // Do not merge this new inventory into a category belonging to
                // another property or a hidden room with the same label.
                $categories = array_values(array_filter($this->em->getRepository(RoomCategory::class)->findAll(),
                    fn (RoomCategory $category): bool => self::normalise((string) $category->getName()) === self::normalise($label)));
                if (count($categories) > 1 || ($categories && !$categories[0]->getApartments()->isEmpty())) {
                    throw new ConflictHttpException('ambiguous_room');
                }
                $category = $categories[0] ?? (new RoomCategory())->setName($label);
                if (!$category->getPrices()->isEmpty()) {
                    throw new ConflictHttpException('room_changed');
                }
                $origin = $this->config->getReservationOrigin($config);
                $template = null;
                foreach ($matched as $anchor) {
                    foreach ($anchor->getRoomCategory()?->getPrices() ?? [] as $price) {
                        if ((int) $price->getType() === 2 && $price->getActive() && $price->getVat() !== null) {
                            $template = $price;
                            break 2;
                        }
                    }
                }
                if ($origin === null || $template === null) {
                    throw new ConflictHttpException('pricing_unavailable');
                }
                $category->setDetails($description);
                $this->em->persist($category);
                $room = new Appartment();
                $room->setNumber($number);
                $room->setDescription($label);
                $room->setObject($property);
                $room->setBedsMax(2);
                $room->setRoomCategory($category)->setActive(true)->setMultipleOccupancy(false);
                $this->em->persist($room);
                // Explicit owner-entered prices, per room rather than per guest.
                // Copy only the property's tax/account treatment, never its amounts.
                foreach ([1, 2] as $occupancy) {
                    foreach ($nightly === $weekend ? ['all' => $nightly] : ['usual' => $nightly, 'weekend' => $weekend] as $kind => $amount) {
                        $price = new Price();
                        $price->setDescription(mb_substr($label.' '.$kind, 0, 100));
                        $price->setPrice(number_format($amount / 100, 2, '.', ''));
                        $price->setVat((float) $template->getVat());
                        $price->setIncludesVat((bool) $template->getIncludesVat());
                        $price->setRevenueAccount($template->getRevenueAccount());
                        $price->setType(2);
                        $price->setActive(true);
                        $price->setNumberOfPersons($occupancy);
                        $price->setMinStay(1);
                        $price->setIsPerRoom(true);
                        $price->setAllPeriods(true);
                        $price->setAllDays($kind === 'all');
                        foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'] as $day) {
                            $price->{'set'.$day}($kind === 'all' || (($kind === 'weekend') === in_array($day, ['Friday', 'Saturday'], true)));
                        }
                        $price->addReservationOrigin($origin)->addRoomCategory($category);
                        $this->em->persist($price);
                    }
                }
                $this->em->flush(); // Assign the room ID before extending a selected-room list.
            }
            if ($config->getRoomsMode() === OnlineBookingConfig::ROOMS_MODE_SELECTED) {
                $config->setSelectedRoomIds([...$config->getSelectedRoomIds(), (int) $room->getId()]);
            }
            return ['state' => 'ready', 'roomId' => (int) $room->getId(), 'label' => $room->getRoomCategory()?->getName() ?: $room->getDescription()];
        });
    }

    /** Deactivate a room, retaining reservations, invoices, prices and photographs. */
    public function remove(array $input): array
    {
        $key = (string)($input['key'] ?? '');
        $label = trim((string)($input['label'] ?? ''));
        $anchors = json_decode((string)($input['anchors'] ?? ''), true);
        $identifier = (string)($input['roomId'] ?? '');
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,39}$/D', $key) || mb_strlen($label) < 4 || mb_strlen($label) > 80
            || !is_array($anchors) || !$anchors || count($anchors) > 50
            || ($identifier !== '' && !preg_match('/^[1-9][0-9]{0,9}$/D', $identifier))) {
            throw new BadRequestHttpException('invalid_room');
        }
        $config = $this->config->getConfig();
        return $this->em->wrapInTransaction(function () use ($key, $label, $anchors, $identifier, $config): array {
            $this->em->refresh($config, LockMode::PESSIMISTIC_WRITE);
            $parents = $this->anchors($anchors, $config);
            $property = $parents[0]->getObject();
            foreach ($parents as $parent) {
                if ($parent->getObject()->getId() !== $property->getId()) {
                    throw new ConflictHttpException('ambiguous_property');
                }
            }
            $number = 'CH'.substr(hash('sha256', $key), 0, 8);
            $matches = array_values(array_filter($this->rooms->findBy(['object' => $property]), fn (Appartment $room): bool =>
                $identifier !== '' ? (string)$room->getId() === $identifier
                : ($room->getNumber() === $number || self::label($room) === self::normalise($label))));
            if (!$matches) {
                // An absent known ID is safe only when it does not identify another property.
                if ($identifier !== '' && $this->em->find(Appartment::class,(int)$identifier) !== null) {
                    throw new ConflictHttpException('ambiguous_room');
                }
                return ['state'=>'absent', 'roomId'=>$identifier === '' ? null : (int)$identifier];
            }
            if (count($matches) !== 1 || self::label($matches[0]) !== self::normalise($label)) {
                throw new ConflictHttpException('ambiguous_room');
            }
            $room = $matches[0];
            $this->em->refresh($room, LockMode::PESSIMISTIC_WRITE);
            $today = new \DateTimeImmutable('today');
            foreach ($this->em->getRepository(Reservation::class)->findBy(['appartment'=>$room]) as $stay) {
                if ($stay->getEndDate() > $today && $stay->getReservationStatus()?->isBlocking() !== false) {
                    throw new ConflictHttpException('room_has_bookings');
                }
            }
            $room->setActive(false);
            if ($config->getRoomsMode() === OnlineBookingConfig::ROOMS_MODE_SELECTED) {
                $config->setSelectedRoomIds(array_values(array_diff($config->getSelectedRoomIds(),[(int)$room->getId()])));
            }
            return ['state'=>'inactive', 'roomId'=>(int)$room->getId()];
        });
    }

    private function anchors(array $labels, OnlineBookingConfig $config): array
    {
        $properties = $this->config->getAllowedSubsidiaryIds($config);
        $released = $this->rooms->findForPublicBooking($this->config->getAllowedRoomIds($config), $properties);
        $inactive = array_values(array_filter($this->rooms->findBy([]), fn (Appartment $room): bool =>
            !$room->isActive() && in_array((int)$room->getObject()->getId(), $properties, true)));
        $matched=[];
        foreach ($labels as $label) {
            if (!is_string($label) || mb_strlen($label) < 4 || mb_strlen($label) > 80) {
                throw new BadRequestHttpException('invalid_room');
            }
            $found=array_values(array_filter($released, fn (Appartment $room): bool => self::label($room) === self::normalise($label)));
            if (!$found) {
                $found=array_values(array_filter($inactive, fn (Appartment $room): bool => self::label($room) === self::normalise($label)));
            }
            if (count($found) !== 1) throw new ConflictHttpException('ambiguous_property');
            $matched[]=$found[0];
        }
        return $matched;
    }

    private static function normalise(string $label): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $label)));
    }

    private static function label(Appartment $room): string
    {
        return self::normalise((string) ($room->getRoomCategory()?->getName() ?: $room->getDescription()));
    }
}
