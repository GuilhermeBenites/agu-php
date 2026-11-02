<?php

declare(strict_types=1);

namespace App\DTO\Task;

final class TaskPage implements \JsonSerializable
{
    /** @param list<TaskView> $items */
    public function __construct(public readonly array $items, public readonly PageQuery $query, public readonly int $total)
    {
    }

    /** @return array{items: list<TaskView>, pagination: array{page: int, limit: int, total: int, totalPages: int}} */
    public function jsonSerialize(): array
    {
        return ['items' => $this->items, 'pagination' => [
            'page' => $this->query->page,
            'limit' => $this->query->limit,
            'total' => $this->total,
            'totalPages' => (int) ceil($this->total / $this->query->limit),
        ]];
    }
}
