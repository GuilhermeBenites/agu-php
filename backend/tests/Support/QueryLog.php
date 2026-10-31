<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Psr\Log\AbstractLogger;

final class QueryLog extends AbstractLogger
{
    public bool $enabled = true;
    public array $queries = [];
    public array $warnings = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if ($this->enabled && isset($context['sql'])) {
            $this->queries[] = $context;
        }
        if (in_array($level, ['warning', 'error'], true)) {
            $this->warnings[] = (string) $message;
        }
    }
}
