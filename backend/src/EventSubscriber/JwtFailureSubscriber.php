<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Http\ApiErrorResponseFactory;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationFailureEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class JwtFailureSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly ApiErrorResponseFactory $responses)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [Events::JWT_INVALID => 'onInvalid', Events::JWT_EXPIRED => 'onExpired', Events::JWT_NOT_FOUND => 'onMissing', Events::AUTHENTICATION_FAILURE => 'onInvalid'];
    }

    public function onInvalid(AuthenticationFailureEvent $event): void
    {
        $event->setResponse($this->responses->create(401, 'token_invalid', 'Token inválido.', headers: ['WWW-Authenticate' => 'Bearer']));
    }

    public function onExpired(AuthenticationFailureEvent $event): void
    {
        $event->setResponse($this->responses->create(401, 'token_expired', 'Sessão expirada. Entre novamente.', headers: ['WWW-Authenticate' => 'Bearer']));
    }

    public function onMissing(AuthenticationFailureEvent $event): void
    {
        $event->setResponse($this->responses->create(401, 'token_missing', 'Autenticação necessária.', headers: ['WWW-Authenticate' => 'Bearer']));
    }
}
