<?php

declare(strict_types=1);

namespace App\Exception;

final class ValidationFailed extends \RuntimeException
{
    /** @param list<array{field: string, message: string}> $details */
    public function __construct(public readonly array $details)
    {
        parent::__construct('Invalid input.');
    }
}
