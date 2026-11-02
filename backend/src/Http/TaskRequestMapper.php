<?php

declare(strict_types=1);

namespace App\Http;

use App\DTO\Task\PageQuery;
use App\DTO\Task\TaskInput;
use App\Exception\ValidationFailed;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;

final class TaskRequestMapper
{
    public function payload(Request $request, bool $replacement = false): TaskInput
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
            if (!in_array($name, ['title', 'description'], true)) {
                $details[] = ['field' => (string) $name, 'message' => 'Campo não permitido.'];
            }
        }
        $title = $fields['title'] ?? null;
        $description = $fields['description'] ?? null;
        if (!is_string($title)) {
            $details[] = ['field' => 'title', 'message' => 'Informe o título como texto.'];
        } elseif (mb_strlen($title) > 255 || '' === self::trim($title)) {
            $details[] = ['field' => 'title', 'message' => 'Informe um título de 1 a 255 caracteres, com conteúdo não branco.'];
        }
        if ($replacement && !array_key_exists('description', $fields)) {
            $details[] = ['field' => 'description', 'message' => 'Campo obrigatório; use null para remover a descrição.'];
        } elseif (null !== $description && !is_string($description)) {
            $details[] = ['field' => 'description', 'message' => 'Informe um texto ou null.'];
        } elseif (is_string($description) && mb_strlen($description) > 10000) {
            $details[] = ['field' => 'description', 'message' => 'Use até 10000 caracteres.'];
        }
        if ([] !== $details) {
            throw new ValidationFailed($details);
        }
        if (!is_string($title) || (null !== $description && !is_string($description))) {
            throw new \LogicException('Input type validation failed.');
        }
        $description = null === $description ? null : self::trim($description);

        return new TaskInput(self::trim($title), '' === $description ? null : $description);
    }

    public function pagination(Request $request): PageQuery
    {
        $fields = $request->query->all();
        $details = [];
        foreach ($fields as $name => $value) {
            if (!in_array($name, ['page', 'limit'], true)) {
                $details[] = ['field' => (string) $name, 'message' => 'Parâmetro não permitido.'];
            }
        }
        $values = ['page' => 1, 'limit' => 20];
        foreach (['page' => 2147483647, 'limit' => 100] as $name => $max) {
            if (!array_key_exists($name, $fields)) {
                continue;
            }
            $value = $fields[$name];
            if (!is_string($value) || !preg_match('/^[0-9]+$/D', $value) || (float) $value < 1 || (float) $value > $max) {
                $details[] = ['field' => $name, 'message' => 'Informe um inteiro entre 1 e '.$max.'.'];
            } else {
                $values[$name] = (int) $value;
            }
        }
        if ([] !== $details) {
            throw new ValidationFailed($details);
        }

        return new PageQuery($values['page'], $values['limit']);
    }

    private static function trim(string $value): string
    {
        return preg_replace('/^\s+|\s+$/u', '', $value) ?? trim($value);
    }
}
