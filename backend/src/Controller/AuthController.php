<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\Auth\UserView;
use App\Entity\User;
use App\Http\CredentialsRequestMapper;
use App\Service\Auth\AuthenticateUser;
use App\Service\Auth\RegisterUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[AsController]
final class AuthController
{
    #[Route('/api/login', name: 'api_login', methods: ['POST'])]
    public function login(Request $request, CredentialsRequestMapper $input, AuthenticateUser $authenticate): JsonResponse
    {
        return new JsonResponse($authenticate->execute($input->map($request)));
    }

    #[Route('/api/register', name: 'api_register', methods: ['POST'])]
    public function register(Request $request, CredentialsRequestMapper $input, RegisterUser $register): JsonResponse
    {
        return new JsonResponse($register->execute($input->map($request)), 201);
    }

    #[Route('/api/me', name: 'api_me', methods: ['GET'])]
    public function me(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse(UserView::fromUser($user));
    }
}
