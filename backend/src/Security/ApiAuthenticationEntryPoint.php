<?php

declare(strict_types=1);

namespace App\Security;

use App\Http\ApiErrorResponseFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

final class ApiAuthenticationEntryPoint implements AuthenticationEntryPointInterface
{
    public function __construct(private readonly ApiErrorResponseFactory $responses)
    {
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return $this->responses->create(401, 'token_missing', 'Autenticação necessária.', headers: ['WWW-Authenticate' => 'Bearer']);
    }
}
