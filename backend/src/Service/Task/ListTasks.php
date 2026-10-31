<?php

declare(strict_types=1);

namespace App\Service\Task;

use App\DTO\Task\PageQuery;
use App\DTO\Task\TaskPage;
use App\DTO\Task\TaskView;
use App\Entity\User;
use App\Repository\TaskRepository;

final class ListTasks implements TaskListQuery
{
    public function __construct(private readonly TaskRepository $tasks)
    {
    }

    public function execute(User $user, PageQuery $query): TaskPage
    {
        return new TaskPage(array_map(TaskView::fromTask(...), $this->tasks->pageFor($user, $query)), $query, $this->tasks->count(['user' => $user]));
    }
}
