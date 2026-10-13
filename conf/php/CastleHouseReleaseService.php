<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Reservation;
use App\Entity\ReservationStatus;
use App\Repository\AppartmentRepository;
use App\Repository\ReservationRepository;
use App\Service\OnlineBooking\OnlineBookingConfigService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Cancel one website room request without deleting its guest or invoice history. */
final class CastleHouseReleaseService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AppartmentRepository $rooms,
        private readonly ReservationRepository $reservations,
        private readonly OnlineBookingConfigService $config,
        private readonly ReservationService $reservationService,
    ) {}

    /** @param array<string, mixed> $input */
    public function find(array $input): ?Reservation
    {
        $arrival = (string) ($input['arrival'] ?? '');
        $departure = (string) ($input['departure'] ?? '');
        foreach ([$arrival, $departure] as $date) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (!$parsed || $parsed->format('Y-m-d') !== $date) {
                throw new BadRequestHttpException('Invalid stay dates.');
            }
        }
        $name = self::normalize((string) ($input['name'] ?? ''));
        $email = self::normalize((string) ($input['email'] ?? ''));
        $roomName = self::normalize((string) ($input['room'] ?? ''));
        $marker = (string) ($input['marker'] ?? '');
        if ($departure <= $arrival || !$name || !filter_var($email, FILTER_VALIDATE_EMAIL) || !$roomName
            || ($marker !== '' && !preg_match('/^\[CHB:[0-9a-f]{12}\]$/D', $marker))) {
            throw new BadRequestHttpException('Invalid booking identity.');
        }

        $rooms = $this->rooms->findForPublicBooking($this->config->getAllowedRoomIds(), $this->config->getAllowedSubsidiaryIds());
        $matchingRooms = array_values(array_filter($rooms, static function ($room) use ($roomName): bool {
            foreach ([$room->getRoomCategory()?->getName(), $room->getDescription()] as $label) {
                if (self::normalize((string) $label) === $roomName) {
                    return true;
                }
            }
            return false;
        }));
        if (count($matchingRooms) !== 1) {
            throw new ConflictHttpException('The website room does not match one released FewohBee room. Check the room configuration.');
        }
        $origin = $this->config->getReservationOrigin();
        if ($origin === null) {
            throw new ConflictHttpException('The website booking origin is not configured.');
        }
        $candidates = $this->reservations->findBy([
            'appartment' => $matchingRooms[0], 'startDate' => new \DateTime($arrival),
            'endDate' => new \DateTime($departure), 'reservationOrigin' => $origin,
        ]);
        if ($candidates === [] && $marker !== '') {
            // A booking moved in the PMS is not a deleted booking. References
            // are private and checked only by reservation-management accounts.
            foreach ($this->reservations->findBy([]) as $changed) {
                if (str_contains((string) $changed->getRemark(), $marker)
                    && $changed->getReservationStatus()?->isBlocking() !== false) {
                    throw new ConflictHttpException('The referenced booking has changed room, dates or origin. Check it in FewohBee first.');
                }
            }
        }
        $matches = [];
        foreach ($candidates as $reservation) {
            $booker = $reservation->getBooker();
            // Public bookings reuse the existing CRM customer by email and do
            // not overwrite its curated name. A website reference therefore
            // identifies the request even when its submitted name differs.
            // Older requests without a reference still require the full name.
            if ($booker === null || ($marker === '' && self::normalize($booker->getFirstname().' '.$booker->getLastname()) !== $name)) {
                continue;
            }
            $emails = [];
            foreach ($booker->getCustomerAddresses() as $contact) {
                $emails[] = self::normalize((string) $contact->getEmail());
            }
            if (!in_array($email, $emails, true) || ($marker !== '' && !str_contains((string) $reservation->getRemark(), $marker))) {
                continue;
            }
            $matches[] = $reservation;
        }
        if ($matches === [] && array_filter($candidates, static fn (Reservation $r): bool => $r->getReservationStatus()?->isBlocking() !== false)) {
            throw new ConflictHttpException('The room has a booking with different guest details or reference. Check it in FewohBee before freeing it.');
        }
        // Already-cancelled duplicates need no mutation. Never choose between
        // ambiguous active records or mistake a replacement for an old request.
        if ($matches !== [] && !array_filter($matches, static fn (Reservation $r): bool => $r->getReservationStatus()?->isBlocking() !== false)) {
            return null;
        }
        if (count($matches) > 1) {
            throw new ConflictHttpException('Several matching FewohBee bookings exist. Resolve the duplicates in FewohBee first.');
        }
        return $matches[0] ?? null;
    }

    public function cancel(Reservation $reservation): void
    {
        $status = $this->em->getRepository(ReservationStatus::class)->findOneBy(['code' => ReservationStatus::CODE_CANCELED_NOSHOW]);
        if (!$status instanceof ReservationStatus || $status->isBlocking()) {
            throw new ConflictHttpException('FewohBee needs its non-blocking cancellation status configured.');
        }
        $this->reservationService->changeStatus($reservation, $status);
    }

    private static function normalize(string $text): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }
}
