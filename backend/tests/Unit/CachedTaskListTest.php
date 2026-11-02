<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\DTO\Task\PageQuery;
use App\DTO\Task\TaskPage;
use App\Entity\User;
use App\Service\Task\CachedTaskList;
use App\Service\Task\TaskListQuery;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\CacheItem;
use Symfony\Contracts\Cache\CacheInterface;

final class CachedTaskListTest extends TestCase
{
    private function owner(): User
    {
        $user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, 42);

        return $user;
    }

    public function testDisabledCacheBypassesRedisEntirely(): void
    {
        $inner = $this->createMock(TaskListQuery::class);
        $page = new TaskPage([], new PageQuery(), 0);
        $inner->expects(self::once())->method('execute')->willReturn($page);
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects(self::never())->method('get');
        self::assertSame($page, (new CachedTaskList($inner, $cache, new NullLogger(), false, 30))->execute($this->owner(), new PageQuery()));
    }

    public function testCacheReadFailureFallsBackToTheDatabase(): void
    {
        $inner = $this->createMock(TaskListQuery::class);
        $page = new TaskPage([], new PageQuery(), 0);
        $inner->expects(self::once())->method('execute')->willReturn($page);
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willThrowException(new \RedisException('Redis unavailable.'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');
        self::assertSame($page, (new CachedTaskList($inner, $cache, $logger, true, 30))->execute($this->owner(), new PageQuery()));
    }

    public function testCacheSaveFailureReturnsTheAlreadyComputedResultWithoutRepeatingTheQuery(): void
    {
        $inner = $this->createMock(TaskListQuery::class);
        $page = new TaskPage([], new PageQuery(), 0);
        $inner->expects(self::once())->method('execute')->willReturn($page);
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willReturnCallback(static function ($key, $callback) {
            $callback(new CacheItem());
            throw new \RedisException('Redis failed after computation.');
        });
        self::assertSame($page, (new CachedTaskList($inner, $cache, new NullLogger(), true, 30))->execute($this->owner(), new PageQuery()));
    }

    public function testDatabaseFailureIsNeitherHiddenNorRetriedAsACacheFailure(): void
    {
        $inner = $this->createMock(TaskListQuery::class);
        $error = new \RuntimeException('MySQL unavailable.');
        $inner->expects(self::once())->method('execute')->willThrowException($error);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');
        $this->expectExceptionObject($error);
        (new CachedTaskList($inner, new ArrayAdapter(), $logger, true, 30))->execute($this->owner(), new PageQuery());
    }

    public function testInvalidCachedValueIsEvictedAndLoadedFromMysql(): void
    {
        $inner = $this->createMock(TaskListQuery::class);
        $page = new TaskPage([], new PageQuery(), 0);
        $inner->expects(self::once())->method('execute')->willReturn($page);
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willReturn('old incompatible payload');
        $cache->expects(self::once())->method('delete')->willReturn(true);
        self::assertSame($page, (new CachedTaskList($inner, $cache, new NullLogger(), true, 30))->execute($this->owner(), new PageQuery()));
    }

    public function testCacheTtlCannotAccidentallyBecomeInfinite(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CachedTaskList($this->createMock(TaskListQuery::class), new ArrayAdapter(), new NullLogger(), true, 0);
    }
}
