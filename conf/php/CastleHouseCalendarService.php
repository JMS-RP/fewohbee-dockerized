<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\AppartmentRepository;
use App\Service\OnlineBooking\OnlineBookingConfigService;
use App\Service\OnlineBooking\PublicBookingCalendarService;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Read-only monthly occupancy; contains no reservation or guest information. */
final class CastleHouseCalendarService
{
    public function __construct(
        private readonly AppartmentRepository $rooms,
        private readonly AvailabilityService $availability,
        private readonly OnlineBookingConfigService $config,
        private readonly PublicBookingCalendarService $calendar,
    ) {}

    public function month(string $month): array
    {
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $month.'-01');
        $today = new \DateTimeImmutable('today');
        $first = $today->modify('first day of this month');
        if (!preg_match('/^\d{4}-\d{2}$/D', $month) || !$start || $start->format('Y-m') !== $month
            || $start < $first || $start > $first->modify('+24 months')) {
            throw new BadRequestHttpException('Choose a month within the booking window.');
        }
        if (!$this->config->getConfig()->isEnabled()) {
            throw new ConflictHttpException('Online booking is not enabled.');
        }
        $end = $start->modify('+1 month');
        $horizon = $this->calendar->getHorizonEnd();
        $rows = [];
        foreach ($this->rooms->findForPublicBooking($this->config->getAllowedRoomIds(), $this->config->getAllowedSubsidiaryIds()) as $room) {
            if ($room->isMultipleOccupancy()) {
                continue; // A shared bed calendar must not advertise the whole room as free.
            }
            // Use the same canonical occupancy rules as FewohBee's public calendar.
            // This includes room blocks and blocking reservations, with exclusive checkout.
            $occupied = $this->availability->getOccupiedNightsForRoom($room, $start, $end);
            $nights = [];
            for ($night = $start; $night < $end; $night = $night->modify('+1 day')) {
                $key = $night->format('Y-m-d');
                $nights[$key] = $room->isActive() && $night >= $today && $night < $horizon && !isset($occupied[$key]);
            }
            $rows[] = ['label' => $room->getRoomCategory()?->getName() ?: $room->getDescription(), 'nights' => $nights];
        }
        return ['month' => $month, 'rooms' => $rows];
    }
}
