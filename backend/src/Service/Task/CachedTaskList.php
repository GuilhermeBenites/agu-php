<?php

declare(strict_types=1);

namespace App\Service\Task;

use App\DTO\Task\PageQuery;
use App\DTO\Task\TaskPage;
use App\Entity\User;
use Psr\Cache\CacheException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class CachedTaskList implements TaskListQuery
{
    public function __construct(
        private readonly TaskListQuery $inner,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly bool $enabled,
        private readonly int $ttl,
    ) {
        if ($ttl < 1) {
            throw new \InvalidArgumentException('Task cache TTL must be positive.');
        }
    }

    public function execute(User $user, PageQuery $query): TaskPage
    {
        if (!$this->enabled) {
            return $this->inner->execute($user, $query);
        }
        $id = $user->getId();
        if (null === $id) {
            throw new \LogicException('Task cache requires a persisted owner.');
        }
        // Capture before reading: a late reader can only populate its old generation.
        $key = sprintf('tasks_v1_u%d_g%s_p%d_l%d_created_id_desc', $id, $user->getTaskCacheVersion(), $query->page, $query->limit);
        $computed = null;
        try {
            // Cache hits are external data and may predate the current DTO schema.
            $result = $this->cache->get($key, function (ItemInterface $item) use ($user, $query, &$computed): TaskPage {
                $item->expiresAfter($this->ttl);

                return $computed = $this->inner->execute($user, $query);
            }, 0.0);
            $page = $this->pageFromCache($result, $query);
            if (null !== $page) {
                return $page;
            }
            $this->cache->delete($key);
            $this->logger->warning('Invalid task list cache value; using MySQL.');
        } catch (CacheException|\RedisException $error) {
            // Database failures are outside this catch and propagate without a retry.
            $this->logger->warning('Task list cache failed; using MySQL.', ['exception' => $error]);
        }

        return $computed ?? $this->inner->execute($user, $query);
    }

    private function pageFromCache(mixed $value, PageQuery $query): ?TaskPage
    {
        if (!$value instanceof TaskPage || $value->query->page !== $query->page || $value->query->limit !== $query->limit) {
            return null;
        }

        return $value;
    }
}
