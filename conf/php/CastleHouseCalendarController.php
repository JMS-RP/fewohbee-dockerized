<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\CastleHouseCalendarService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_RESERVATIONS_RO')]
final class CastleHouseCalendarController extends AbstractController
{
    #[Route('/reservation/castle-house/calendar', name: 'castle_house.calendar', methods: ['GET'])]
    public function month(Request $request, CastleHouseCalendarService $calendar): JsonResponse
    {
        try {
            return new JsonResponse($calendar->month($request->query->getString('month')), headers: ['Cache-Control' => 'private, no-store']);
        } catch (HttpExceptionInterface $error) {
            return new JsonResponse(['error' => $error->getMessage()], $error->getStatusCode(), ['Cache-Control' => 'private, no-store']);
        }
    }
}
