<?php

declare(strict_types=1);

namespace App\Service\Task;

use App\DTO\Task\TaskInput;
use App\DTO\Task\TaskView;
use App\Entity\Task;
use Doctrine\ORM\EntityManagerInterface;

final class UpdateTask
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function execute(Task $task, TaskInput $input): TaskView
    {
        $task->setTitle($input->title)->setDescription($input->description);
        $task->getUser()->advanceTaskCacheVersion();
        $this->em->flush();

        return TaskView::fromTask($task);
    }
}
