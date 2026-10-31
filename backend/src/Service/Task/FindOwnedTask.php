<?php

declare(strict_types=1);

namespace App\Service\Task;

use App\Entity\Task;
use App\Entity\User;
use App\Exception\TaskNotFound;
use App\Repository\TaskRepository;

final class FindOwnedTask
{
    public function __construct(private readonly TaskRepository $tasks)
    {
    }

    public function execute(User $user, string $id): Task
    {
        if (!preg_match('/^[0-9]+$/D', $id) || (float) $id < 1 || (float) $id > 2147483647) {
            throw new TaskNotFound();
        }

        return $this->tasks->findOwned((int) $id, $user) ?? throw new TaskNotFound();
    }
}
