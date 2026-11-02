<?php

declare(strict_types=1);

namespace App\DTO\Auth;

use App\Entity\User;

final class UserView implements \JsonSerializable
{
    public function __construct(public readonly int $id, public readonly string $username)
    {
    }

    public static function fromUser(User $user): self
    {
        $id = $user->getId();
        if (null === $id) {
            throw new \LogicException('Cannot expose an unpersisted user.');
        }

        return new self($id, $user->getUsername());
    }

    /** @return array{id: int, username: string} */
    public function jsonSerialize(): array
    {
        return ['id' => $this->id, 'username' => $this->username];
    }
}
