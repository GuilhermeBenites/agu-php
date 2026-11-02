<?php

declare(strict_types=1);

namespace App\DTO\Task;

final class TaskInput
{
    public function __construct(public readonly string $title, public readonly ?string $description)
    {
    }
}
