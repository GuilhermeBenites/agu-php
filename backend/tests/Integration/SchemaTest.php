<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Task;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\NotNullConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SchemaTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = $this->entityManager->getConnection();
        self::assertStringEndsWith('_test', $this->connection->getDatabase());
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        parent::tearDown();
    }

    public function testEntitiesRoundTripThroughMysqlWithImmutableDatesAndNullableDescription(): void
    {
        $user = (new User())->setUsername('schema-user')->setPassword('test-hash');
        $task = new Task($user, 'Verificar schema');
        $this->entityManager->persist($user);
        $this->entityManager->persist($task);
        $this->entityManager->flush();
        $id = $task->getId();
        $this->entityManager->clear();
        $loaded = $this->entityManager->find(Task::class, $id);

        self::assertInstanceOf(Task::class, $loaded);
        self::assertSame('schema-user', $loaded->getUser()->getUsername());
        self::assertNull($loaded->getDescription());
        self::assertInstanceOf(\DateTimeImmutable::class, $loaded->getCreatedAt());
        self::assertSame('UTC', $loaded->getCreatedAt()->getTimezone()->getName());
        $createdAt = $loaded->getCreatedAt();
        $loaded->setTitle('Schema validado');
        $this->entityManager->flush();
        self::assertSame($createdAt, $loaded->getCreatedAt());
        self::assertGreaterThanOrEqual($createdAt, $loaded->getUpdatedAt());
    }

    public function testMysqlEnforcesUsernameUniqueness(): void
    {
        $this->connection->insert('users', ['username' => 'duplicate', 'password' => 'hash']);
        $this->expectException(UniqueConstraintViolationException::class);
        $this->connection->insert('users', ['username' => 'duplicate', 'password' => 'other-hash']);
    }

    public function testTaskOwnerCannotBeNull(): void
    {
        $this->expectException(NotNullConstraintViolationException::class);
        $this->connection->insert('tasks', ['user_id' => null, 'title' => 'Invalid', 'created_at' => '2026-10-30 14:00:00', 'updated_at' => '2026-10-30 14:00:00']);
    }

    public function testTaskOwnerMustExist(): void
    {
        $this->expectException(ForeignKeyConstraintViolationException::class);
        $this->connection->insert('tasks', ['user_id' => 2147483647, 'title' => 'Invalid', 'created_at' => '2026-10-30 14:00:00', 'updated_at' => '2026-10-30 14:00:00']);
    }

    public function testDeletingAnOwnerCascadesToTheirTasks(): void
    {
        $user = (new User())->setUsername('cascade')->setPassword('hash');
        $task = new Task($user, 'Cascade');
        $this->entityManager->persist($user);
        $this->entityManager->persist($task);
        $this->entityManager->flush();
        $this->connection->delete('users', ['id' => $user->getId()]);

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tasks WHERE id = ?', [$task->getId()]));
    }
}
