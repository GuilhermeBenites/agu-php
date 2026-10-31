<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class ApiResponseSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRequest', 2048],
            KernelEvents::RESPONSE => ['onResponse', -16],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest() && preg_match('#^/api(?:/|$)#', $event->getRequest()->getPathInfo())) {
            $event->getRequest()->attributes->set('_request_id', bin2hex(random_bytes(16)));
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        $requestId = $event->getRequest()->attributes->get('_request_id');
        if (!$event->isMainRequest() || !is_string($requestId)) {
            return;
        }

        $event->getResponse()->headers->set('X-Request-ID', $requestId);
        $event->getResponse()->headers->set('Cache-Control', 'no-store');
    }
}
