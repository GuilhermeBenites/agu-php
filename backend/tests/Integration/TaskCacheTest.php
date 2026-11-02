<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\DTO\Task\PageQuery;
use App\DTO\Task\TaskInput;
use App\Entity\Task;
use App\Entity\User;
use App\Repository\TaskRepository;
use App\Service\Task\CachedTaskList;
use App\Service\Task\CreateTask;
use App\Service\Task\DeleteTask;
use App\Service\Task\ListTasks;
use App\Service\Task\TaskListQuery;
use App\Service\Task\UpdateTask;
use App\Tests\Support\QueryLog;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\RedisAdapter;

final class TaskCacheTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private QueryLog $queries;
    private QueryLog $cacheLog;
    private ListTasks $raw;
    private RedisAdapter $pool;
    private CachedTaskList $cached;
    private \Redis $redis;
    private string $namespace;
    private array $ids = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->queries = self::getContainer()->get(QueryLog::class);
        $this->cacheLog = new QueryLog();
        $this->raw = new ListTasks(self::getContainer()->get(TaskRepository::class));
        $this->redis = RedisAdapter::createConnection($_SERVER['REDIS_URL'] ?? $_ENV['REDIS_URL']);
        self::assertTrue($this->redis->ping());
        $this->namespace = 'task_test_'.bin2hex(random_bytes(8));
        $this->pool = new RedisAdapter($this->redis, $this->namespace, 30);
        $this->pool->setLogger($this->cacheLog);
        $this->cached = new CachedTaskList($this->raw, $this->pool, $this->cacheLog, true, 30);
        foreach (range(1, 2) as $i) {
            $user = (new User())->setUsername('cache_'.bin2hex(random_bytes(8)))->setPassword('test-hash');
            $this->em->persist($user);
            $this->em->flush();
            $this->ids[] = $user->getId();
        }
    }

    protected function tearDown(): void
    {
        $db = $this->em->getConnection();
        foreach ($this->ids as $id) {
            $db->delete('users', ['id' => $id]);
        }
        $this->pool->clear();
        parent::tearDown();
    }

    private function owner(int $index = 0): User
    {
        // Model authentication loading the current owner for every new request.
        $this->em->clear();
        $user = $this->em->find(User::class, $this->ids[$index]);
        self::assertInstanceOf(User::class, $user);
        $this->queries->queries = [];

        return $user;
    }

    private function create(User $owner, string $title = 'Tarefa'): int
    {
        return (new CreateTask($this->em))->execute($owner, new TaskInput($title, null))->id;
    }

    public function testRealRedisColdAndWarmReadsCacheOnlyDtoDataAndSeparateOwnersAndPages(): void
    {
        $owner = $this->owner();
        $this->create($owner, 'Primeira');
        $this->create($owner, 'Segunda');
        $other = $this->owner(1);
        $this->create($other, 'Outra conta');
        $owner = $this->owner();
        $cold = $this->cached->execute($owner, new PageQuery(1, 1));
        self::assertCount(2, $this->queries->queries);
        $owner = $this->owner();
        self::assertEquals($cold, $this->cached->execute($owner, new PageQuery(1, 1)));
        self::assertCount(0, $this->queries->queries);
        $second = $this->cached->execute($owner, new PageQuery(2, 1));
        self::assertNotSame($cold->items[0]->id, $second->items[0]->id);
        self::assertCount(2, $this->queries->queries);
        $this->queries->queries = [];
        self::assertCount(2, $this->cached->execute($owner, new PageQuery(1, 20))->items);
        self::assertCount(2, $this->queries->queries);
        self::assertSame('Outra conta', $this->cached->execute($this->owner(1), new PageQuery(1, 1))->items[0]->title);
        $keys = $this->redis->keys($this->namespace.':*');
        self::assertNotEmpty($keys);
        foreach ($keys as $key) {
            $bytes = $this->redis->get($key);
            self::assertStringNotContainsString('App\\Entity\\', $bytes);
            self::assertStringNotContainsString('test-hash', $bytes);
            self::assertGreaterThan(0, $this->redis->ttl($key));
            self::assertLessThanOrEqual(30, $this->redis->ttl($key));
        }
    }

    public function testEveryMutationInvalidatesAllOwnerVariantsButKeepsTheOtherOwnerWarm(): void
    {
        $owner = $this->owner();
        $id = $this->create($owner, 'Antes');
        $this->cached->execute($owner, new PageQuery(1, 1));
        $this->cached->execute($owner, new PageQuery(2, 1));
        $other = $this->owner(1);
        $this->cached->execute($other, new PageQuery());
        $owner = $this->owner();
        $before = $owner->getTaskCacheVersion();
        $this->create($owner, 'Criada');
        $owner = $this->owner();
        self::assertNotSame($before, $owner->getTaskCacheVersion());
        self::assertSame(2, $this->cached->execute($owner, new PageQuery(2, 1))->total);
        self::assertCount(2, $this->queries->queries);
        $task = $this->em->find(Task::class, $id);
        (new UpdateTask($this->em))->execute($task, new TaskInput('Depois', 'Atualizada'));
        $owner = $this->owner();
        $page = $this->cached->execute($owner, new PageQuery(2, 1));
        self::assertSame('Depois', $page->items[0]->title);
        self::assertCount(2, $this->queries->queries);
        $task = $this->em->find(Task::class, $id);
        (new DeleteTask($this->em))->execute($task);
        $owner = $this->owner();
        self::assertSame([], $this->cached->execute($owner, new PageQuery(2, 1))->items);
        self::assertCount(2, $this->queries->queries);
        $this->cached->execute($this->owner(1), new PageQuery());
        self::assertCount(0, $this->queries->queries);
    }

    public function testLateReaderCannotRepopulateTheNewGenerationWithAnOldSnapshot(): void
    {
        $owner = $this->owner();
        $this->create($owner, 'Snapshot anterior');
        $snapshot = $this->raw->execute($owner, new PageQuery());
        $inner = $this->createMock(TaskListQuery::class);
        $inner->method('execute')->willReturnCallback(function () use ($owner, $snapshot) {
            // Exact interleaving: database read completes, mutation commits, old cache fill resumes.
            $this->create($owner, 'Commit concorrente');

            return $snapshot;
        });
        $late = new CachedTaskList($inner, $this->pool, $this->cacheLog, true, 30);
        self::assertSame(1, $late->execute($owner, new PageQuery())->total);
        self::assertSame(2, $this->cached->execute($this->owner(), new PageQuery())->total);
        self::assertCount(2, $this->queries->queries);
        self::assertSame(2, $this->cached->execute($this->owner(), new PageQuery())->total);
        self::assertCount(0, $this->queries->queries);
    }

    public function testWritesDuringRedisOutageCannotResurrectOldCacheOnRecovery(): void
    {
        $owner = $this->owner();
        $id = $this->create($owner, 'Antes da indisponibilidade');
        $this->cached->execute($owner, new PageQuery());
        // A real failed Redis connection; healthy Redis retains the old keys across recovery.
        $unavailable = new RedisAdapter(RedisAdapter::createConnection('redis://redis_test:6399?timeout=0.1&read_timeout=0.1&retry_interval=0&lazy=1'), $this->namespace, 30);
        $unavailable->setLogger($this->cacheLog);
        $fallback = new CachedTaskList($this->raw, $unavailable, $this->cacheLog, true, 30);
        self::assertSame(1, $fallback->execute($this->owner(), new PageQuery())->total);
        self::assertCount(2, $this->queries->queries);
        self::assertNotEmpty($this->cacheLog->warnings);
        $task = $this->em->find(Task::class, $id);
        (new UpdateTask($this->em))->execute($task, new TaskInput('Durante a falha', null));
        $this->create($this->owner(), 'Criada durante a falha');
        $owner = $this->owner();
        self::assertSame(2, $fallback->execute($owner, new PageQuery())->total);
        $recovered = $this->cached->execute($this->owner(), new PageQuery());
        self::assertSame(2, $recovered->total);
        self::assertSame('Durante a falha', $recovered->items[1]->title);
        self::assertCount(2, $this->queries->queries);
        $this->cached->execute($this->owner(), new PageQuery());
        self::assertCount(0, $this->queries->queries);
        $task = $this->em->find(Task::class, $id);
        (new DeleteTask($this->em))->execute($task);
        self::assertSame(1, $this->cached->execute($this->owner(), new PageQuery())->total);
    }

    public function testNaturalRedisExpiryRecomputesAndRefillsTheEntry(): void
    {
        $cached = new CachedTaskList($this->raw, $this->pool, $this->cacheLog, true, 1);
        $cached->execute($this->owner(), new PageQuery());
        self::assertCount(2, $this->queries->queries);
        $cached->execute($this->owner(), new PageQuery());
        self::assertCount(0, $this->queries->queries);
        usleep(1100000);
        $cached->execute($this->owner(), new PageQuery());
        self::assertCount(2, $this->queries->queries);
    }

    public function testTwoWritersStartingFromTheSameGenerationStillProduceDistinctCommittedVersions(): void
    {
        $owner = $this->owner();
        $db2 = DriverManager::getConnection($this->em->getConnection()->getParams());
        $em2 = new EntityManager($db2, $this->em->getConfiguration());
        try {
            $staleOwner = $em2->find(User::class, $owner->getId());
            self::assertSame($owner->getTaskCacheVersion(), $staleOwner->getTaskCacheVersion());
            $this->create($owner, 'Escrita A');
            $generationA = $owner->getTaskCacheVersion();
            (new CreateTask($em2))->execute($staleOwner, new TaskInput('Escrita B', null));
            self::assertNotSame($generationA, $staleOwner->getTaskCacheVersion());
            $current = $this->owner();
            self::assertSame($staleOwner->getTaskCacheVersion(), $current->getTaskCacheVersion());
            self::assertSame(2, $this->cached->execute($current, new PageQuery())->total);
        } finally {
            $db2->close();
        }
    }

    public function testMysqlFailureRollsBackBothTaskAndCacheGeneration(): void
    {
        $owner = $this->owner();
        $id = $this->create($owner, 'Persistida');
        $generation = $owner->getTaskCacheVersion();
        $task = $this->em->find(Task::class, $id);
        try {
            (new UpdateTask($this->em))->execute($task, new TaskInput(str_repeat('a', 256), null));
            self::fail('The strict MySQL title limit should fail this write.');
        } catch (DriverException) {
            $db = $this->em->getConnection();
            self::assertSame($generation, $db->fetchOne('SELECT task_cache_version FROM users WHERE id = ?', [$owner->getId()]));
            self::assertSame('Persistida', $db->fetchOne('SELECT title FROM tasks WHERE id = ?', [$id]));
        }
    }
}
