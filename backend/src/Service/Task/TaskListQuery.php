<?php

declare(strict_types=1);

namespace App\Service\Task;

use App\DTO\Task\PageQuery;
use App\DTO\Task\TaskPage;
use App\Entity\User;

interface TaskListQuery
{
    public function execute(User $user, PageQuery $query): TaskPage;
}
