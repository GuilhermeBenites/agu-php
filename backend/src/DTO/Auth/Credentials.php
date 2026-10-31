<?php

declare(strict_types=1);

namespace App\DTO\Auth;

use Symfony\Component\Validator\Constraints as Assert;

final class Credentials
{
    public function __construct(
        #[Assert\NotBlank(message: 'Informe seu nome de usuário.')]
        #[Assert\Length(min: 3, max: 50, minMessage: 'Use de 3 a 50 caracteres no nome de usuário.', maxMessage: 'Use de 3 a 50 caracteres no nome de usuário.')]
        #[Assert\Regex(pattern: '/^[A-Za-z0-9][A-Za-z0-9._-]{2,49}$/D', message: 'Use letras, números, ponto, hífen ou underscore, começando por letra ou número.')]
        public readonly string $username,
        #[Assert\NotBlank(message: 'Informe sua senha.')]
        #[Assert\Length(min: 8, max: 72, minMessage: 'Use pelo menos 8 caracteres na senha.', maxMessage: 'A senha é muito longa.')]
        public readonly string $password,
    ) {
    }
}
