<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\CastleHouseRoomService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_RESERVATIONS')]
#[Route('/reservation/castle-house/rooms')]
final class CastleHouseRoomController extends AbstractController
{
    private const TOKEN_ID = 'castle_house_room_setup';

    #[Route('/form', name: 'castle_house.rooms.form', methods: ['GET'])]
    public function form(CsrfTokenManagerInterface $tokens): JsonResponse
    {
        return new JsonResponse(['token' => $tokens->getToken(self::TOKEN_ID)->getValue(), 'canDelete' => true], headers: ['Cache-Control' => 'no-store']);
    }

    #[Route('', name: 'castle_house.rooms.ensure', methods: ['POST'])]
    public function ensure(Request $request, CastleHouseRoomService $service): JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::TOKEN_ID, $request->request->getString('_token'))) {
            return new JsonResponse(['error' => 'invalid_token'], 403, ['Cache-Control' => 'no-store']);
        }
        try {
            return new JsonResponse($service->ensure($request->request->all()), headers: ['Cache-Control' => 'no-store']);
        } catch (HttpExceptionInterface $error) {
            return new JsonResponse(['error' => $error->getMessage()], $error->getStatusCode(), ['Cache-Control' => 'no-store']);
        }
    }

    #[Route('/remove', name: 'castle_house.rooms.remove', methods: ['POST'])]
    public function remove(Request $request, CastleHouseRoomService $service): JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::TOKEN_ID, $request->request->getString('_token'))) {
            return new JsonResponse(['error' => 'invalid_token'], 403, ['Cache-Control' => 'no-store']);
        }
        try {
            return new JsonResponse($service->remove($request->request->all()), headers: ['Cache-Control' => 'no-store']);
        } catch (HttpExceptionInterface $error) {
            return new JsonResponse(['error' => $error->getMessage()], $error->getStatusCode(), ['Cache-Control' => 'no-store']);
        }
    }
}
