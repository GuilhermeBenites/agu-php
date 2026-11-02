<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\DTO\Auth\Credentials;
use App\DTO\Auth\UserView;
use App\Entity\User;
use App\Exception\UsernameTaken;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class RegisterUser
{
    public function __construct(private readonly UserRepository $users, private readonly EntityManagerInterface $entityManager, private readonly UserPasswordHasherInterface $hasher)
    {
    }

    public function execute(Credentials $credentials): UserView
    {
        $username = strtolower($credentials->username);
        if (null !== $this->users->findOneBy(['username' => $username])) {
            throw new UsernameTaken();
        }
        $user = (new User())->setUsername($username);
        $user->setPassword($this->hasher->hashPassword($user, $credentials->password));
        try {
            $this->entityManager->persist($user);
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $error) {
            throw new UsernameTaken('Username already exists.', 0, $error);
        }

        return UserView::fromUser($user);
    }
}
