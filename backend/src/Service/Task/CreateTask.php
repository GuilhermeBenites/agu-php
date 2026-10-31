<?php

declare(strict_types=1);

namespace App\Service\Task;

use App\DTO\Task\TaskInput;
use App\DTO\Task\TaskView;
use App\Entity\Task;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

final class CreateTask
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function execute(User $user, TaskInput $input): TaskView
    {
        $task = new Task($user, $input->title, $input->description);
        $this->em->persist($task);
        $user->advanceTaskCacheVersion();
        $this->em->flush();

        return TaskView::fromTask($task);
    }
}
