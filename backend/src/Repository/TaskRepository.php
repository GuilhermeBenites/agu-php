<?php

declare(strict_types=1);

namespace App\Repository;

use App\DTO\Task\PageQuery;
use App\Entity\Task;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Task> */
class TaskRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Task::class);
    }

    public function findOwned(int $id, User $user): ?Task
    {
        return $this->findOneBy(['id' => $id, 'user' => $user]);
    }

    /** @return list<Task> */
    public function pageFor(User $user, PageQuery $query): array
    {
        return $this->createQueryBuilder('task')
            ->where('task.user = :user')->setParameter('user', $user)
            ->orderBy('task.createdAt', 'DESC')->addOrderBy('task.id', 'DESC')
            ->setFirstResult($query->offset())->setMaxResults($query->limit)
            ->getQuery()->getResult();
    }
}
