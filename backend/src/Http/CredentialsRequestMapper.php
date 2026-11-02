<?php

declare(strict_types=1);

namespace App\Http;

use App\DTO\Auth\Credentials;
use App\Exception\ValidationFailed;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class CredentialsRequestMapper
{
    public function __construct(private readonly ValidatorInterface $validator)
    {
    }

    public function map(Request $request): Credentials
    {
        $mediaType = strtolower(trim(explode(';', $request->headers->get('Content-Type', ''))[0]));
        if ('application/json' !== $mediaType) {
            throw new UnsupportedMediaTypeHttpException();
        }
        try {
            $input = json_decode($request->getContent(), false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new BadRequestHttpException('Invalid JSON.', $error);
        }
        if (!$input instanceof \stdClass) {
            throw new ValidationFailed([['field' => '$', 'message' => 'Informe um objeto JSON.']]);
        }
        $fields = get_object_vars($input);
        $details = [];
        foreach ($fields as $name => $value) {
            if (!in_array($name, ['username', 'password'], true)) {
                $details[] = ['field' => (string) $name, 'message' => 'Campo não permitido.'];
            }
        }
        foreach (['username', 'password'] as $name) {
            if (!array_key_exists($name, $fields)) {
                $details[] = ['field' => $name, 'message' => 'Campo obrigatório.'];
            } elseif (!is_string($fields[$name])) {
                $details[] = ['field' => $name, 'message' => 'Informe um texto.'];
            }
        }
        if ([] !== $details) {
            throw new ValidationFailed($details);
        }
        // Types are checked before constructing the typed DTO.
        $username = $fields['username'];
        $password = $fields['password'];
        if (!is_string($username) || !is_string($password)) {
            throw new \LogicException('Input type validation failed.');
        }
        $credentials = new Credentials($username, $password);
        foreach ($this->validator->validate($credentials) as $violation) {
            $details[] = ['field' => $violation->getPropertyPath(), 'message' => (string) $violation->getMessage()];
        }
        if (strlen($password) > 72) {
            $details[] = ['field' => 'password', 'message' => 'A senha é muito longa.'];
        }
        if ([] !== $details) {
            throw new ValidationFailed($details);
        }

        return $credentials;
    }
}
