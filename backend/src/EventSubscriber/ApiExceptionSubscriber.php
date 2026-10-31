<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Exception\InvalidCredentials;
use App\Exception\TaskNotFound;
use App\Exception\UsernameTaken;
use App\Exception\ValidationFailed;
use App\Http\ApiErrorResponseFactory;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class ApiExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly ApiErrorResponseFactory $responses, private readonly LoggerInterface $logger)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => [['onApplicationException', 16], ['onException', -64]]];
    }

    public function onApplicationException(ExceptionEvent $event): void
    {
        $error = $event->getThrowable();
        if ($error instanceof ValidationFailed || $error instanceof InvalidCredentials || $error instanceof UsernameTaken || $error instanceof TaskNotFound) {
            $this->onException($event);
        }
    }

    public function onException(ExceptionEvent $event): void
    {
        if (!preg_match('#^/api(?:/|$)#', $event->getRequest()->getPathInfo())) {
            return;
        }

        $exception = $event->getThrowable();
        if ($exception instanceof TaskNotFound) {
            $event->setResponse($this->responses->create(404, 'task_not_found', 'Tarefa não encontrada.'));

            return;
        }
        if ($exception instanceof ValidationFailed) {
            $event->setResponse($this->responses->create(422, 'validation_failed', 'Revise os campos informados.', $exception->details));

            return;
        }
        if ($exception instanceof InvalidCredentials) {
            $event->setResponse($this->responses->create(401, 'invalid_credentials', 'Usuário ou senha inválidos.'));

            return;
        }
        if ($exception instanceof UsernameTaken) {
            $event->setResponse($this->responses->create(409, 'username_taken', 'Nome de usuário indisponível.'));

            return;
        }
        $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;
        $headers = $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : [];
        [$code, $message] = match ($status) {
            400 => ['invalid_json', 'O corpo deve conter JSON válido.'],
            404 => ['not_found', 'Recurso não encontrado.'],
            415 => ['unsupported_media_type', 'Use Content-Type application/json.'],
            405 => ['method_not_allowed', 'Método não permitido.'],
            default => ['internal_error', 'Não foi possível concluir a operação.'],
        };

        if ($status >= 500) {
            $this->logger->error('API request failed.', [
                'exception' => $exception,
                'requestId' => $event->getRequest()->attributes->get('_request_id'),
            ]);
        }

        $event->setResponse($this->responses->create($status, $code, $message, headers: $headers));
    }
}
