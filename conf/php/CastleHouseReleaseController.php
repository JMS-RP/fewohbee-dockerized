<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\CastleHouseReleaseService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Private session-authenticated connection; no guest data in URLs or responses. */
#[IsGranted('ROLE_RESERVATIONS')]
#[Route('/reservation/castle-house/release')]
final class CastleHouseReleaseController extends AbstractController
{
    private const TOKEN_ID = 'castle_house_release';

    #[Route('/form', name: 'castle_house.release.form', methods: ['GET'])]
    public function form(CsrfTokenManagerInterface $tokens): JsonResponse
    {
        return new JsonResponse(['token' => $tokens->getToken(self::TOKEN_ID)->getValue()], headers: ['Cache-Control' => 'no-store']);
    }

    #[Route('/check', name: 'castle_house.release.check', methods: ['POST'])]
    #[Route('', name: 'castle_house.release.cancel', methods: ['POST'])]
    public function release(Request $request, CastleHouseReleaseService $service): JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::TOKEN_ID, $request->request->getString('_token'))) {
            return new JsonResponse(['error' => 'The booking connection token was rejected.'], 403, ['Cache-Control' => 'no-store']);
        }
        try {
            $input = $request->request->all();
            $reservation = $service->find($input);
            if ($request->attributes->get('_route') === 'castle_house.release.cancel' && $reservation !== null) {
                if ((string) $reservation->getId() !== (string) ($input['reservation_id'] ?? '')) {
                    return new JsonResponse(['error' => 'The matching booking changed. Check FewohBee before freeing it.'], 409, ['Cache-Control' => 'no-store']);
                }
                $service->cancel($reservation);
                // Verify the updated non-blocking state, including status hooks.
                $reservation = $service->find($input);
            }
            return new JsonResponse(['state' => $reservation === null ? 'free' : 'held',
                'reservationId' => $reservation?->getId()], headers: ['Cache-Control' => 'no-store']);
        } catch (HttpExceptionInterface $error) {
            return new JsonResponse(['error' => $error->getMessage()], $error->getStatusCode(), ['Cache-Control' => 'no-store']);
        }
    }
}
