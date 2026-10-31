<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\DTO\Auth\Credentials;
use App\DTO\Auth\LoginView;
use App\DTO\Auth\UserView;
use App\Exception\InvalidCredentials;
use App\Repository\UserRepository;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AuthenticateUser
{
    public function __construct(private readonly UserRepository $users, private readonly UserPasswordHasherInterface $hasher, private readonly JWTTokenManagerInterface $tokens, #[Autowire('%env(int:JWT_TOKEN_TTL)%')] private readonly int $tokenTtl)
    {
    }

    public function execute(Credentials $credentials): LoginView
    {
        $user = $this->users->findOneBy(['username' => strtolower($credentials->username)]);
        if (null === $user || !$this->hasher->isPasswordValid($user, $credentials->password)) {
            throw new InvalidCredentials();
        }

        return new LoginView($this->tokens->create($user), $this->tokenTtl, UserView::fromUser($user));
    }
}
