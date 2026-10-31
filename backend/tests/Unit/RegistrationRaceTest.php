<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\DTO\Auth\Credentials;
use App\Exception\UsernameTaken;
use App\Repository\UserRepository;
use App\Service\Auth\RegisterUser;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class RegistrationRaceTest extends TestCase
{
    public function testUniqueConstraintRaceIsTranslatedToAnApplicationConflict(): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->method('findOneBy')->willReturn(null);
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturn('hashed-password');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('flush')->willThrowException($this->createMock(UniqueConstraintViolationException::class));
        $this->expectException(UsernameTaken::class);
        (new RegisterUser($users, $entityManager, $hasher))->execute(new Credentials('ana', 'password-example'));
    }
}
