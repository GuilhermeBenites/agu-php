<?php

declare(strict_types=1);

namespace App\DTO\Auth;

final class LoginView implements \JsonSerializable
{
    public function __construct(public readonly string $token, public readonly int $expiresIn, public readonly UserView $user)
    {
    }

    /** @return array{token: string, tokenType: string, expiresIn: int, user: UserView} */
    public function jsonSerialize(): array
    {
        return ['token' => $this->token, 'tokenType' => 'Bearer', 'expiresIn' => $this->expiresIn, 'user' => $this->user];
    }
}
