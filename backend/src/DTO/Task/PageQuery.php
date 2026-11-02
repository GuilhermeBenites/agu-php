<?php

declare(strict_types=1);

namespace App\DTO\Task;

final class PageQuery
{
    public function __construct(public readonly int $page = 1, public readonly int $limit = 20)
    {
        if ($page < 1 || $page > 2147483647 || $limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('Invalid pagination bounds.');
        }
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->limit;
    }
}
