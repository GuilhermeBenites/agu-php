<?php

declare(strict_types=1);

namespace App\Service\Task;

use App\Entity\Task;
use Doctrine\ORM\EntityManagerInterface;

final class DeleteTask
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function execute(Task $task): void
    {
        $task->getUser()->advanceTaskCacheVersion();
        $this->em->remove($task);
        $this->em->flush();
    }
}
