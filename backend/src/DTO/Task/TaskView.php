<?php

declare(strict_types=1);

namespace App\DTO\Task;

use App\Entity\Task;

final class TaskView implements \JsonSerializable
{
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly ?string $description,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {
    }

    public static function fromTask(Task $task): self
    {
        $id = $task->getId();
        if (null === $id) {
            throw new \LogicException('Cannot expose an unpersisted task.');
        }
        $utc = new \DateTimeZone('UTC');

        return new self($id, $task->getTitle(), $task->getDescription(),
            $task->getCreatedAt()->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'),
            $task->getUpdatedAt()->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'));
    }

    /** @return array{id: int, title: string, description: ?string, createdAt: string, updatedAt: string} */
    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
