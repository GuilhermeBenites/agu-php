<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;

final class ApiErrorResponseFactory
{
    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    /**
     * @param list<array{field: string, message: string}> $details
     * @param array<string, string>                       $headers
     */
    public function create(int $status, string $code, string $message, array $details = [], array $headers = []): JsonResponse
    {
        $requestId = $this->requestStack->getCurrentRequest()?->attributes->get('_request_id');

        return new JsonResponse([
            'error' => ['code' => $code, 'message' => $message, 'details' => $details],
            'requestId' => is_string($requestId) ? $requestId : bin2hex(random_bytes(16)),
        ], $status, $headers);
    }
}
