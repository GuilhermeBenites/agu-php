<?php

declare(strict_types=1);

use App\DTO\Task\PageQuery;
use App\Entity\User;
use App\Kernel;
use App\Repository\TaskRepository;
use App\Service\Task\CachedTaskList;
use App\Service\Task\ListTasks;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\AbstractManagerRegistry;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\RedisAdapter;

require __DIR__.'/bootstrap.php';

$kernel = new Kernel('test', true);
$kernel->boot();
$original = $kernel->getContainer()->get('test.service_container')->get(EntityManagerInterface::class);
$logger = new class extends AbstractLogger {
    public bool $enabled = false;
    public array $queries = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        if ($this->enabled && isset($context['sql'])) {
            $this->queries[] = $context;
        }
    }
};
$config = new Configuration();
$config->setMiddlewares([new Middleware($logger)]);
$db = DriverManager::getConnection($original->getConnection()->getParams(), $config);
$em = new EntityManager($db, $original->getConfiguration());
$registry = new class($em) extends AbstractManagerRegistry {
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct('benchmark', ['default' => 'db'], ['default' => 'em'], 'default', 'default');
    }

    protected function getService(string $name): object
    {
        return 'em' === $name ? $this->em : $this->em->getConnection();
    }

    protected function resetService(string $name): void
    {
        throw new LogicException('Reset is not supported by the benchmark registry.');
    }
};
$list = new ListTasks(new TaskRepository($registry));
$redis = RedisAdapter::createConnection($_SERVER['REDIS_URL'] ?? $_ENV['REDIS_URL']);
$cache = new RedisAdapter($redis, 'benchmark_'.bin2hex(random_bytes(8)), 30);
$cached = new CachedTaskList($list, $cache, new NullLogger(), true, 30);
$db->beginTransaction();
try {
    $owners = [];
    foreach (range(1, 2) as $owner) {
        $db->insert('users', ['username' => 'bench_'.bin2hex(random_bytes(6)), 'password' => 'not-a-login-fixture']);
        $owners[] = (int) $db->lastInsertId();
    }
    $insert = $db->prepare('INSERT INTO tasks (user_id,title,description,created_at,updated_at) VALUES (?,?,?,?,?)');
    $start = new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'));
    foreach ($owners as $owner) {
        for ($i = 0; $i < 10000; ++$i) {
            // Ties intentionally exercise the id ordering within each timestamp.
            $date = $start->modify('+'.intdiv($i, 5).' seconds')->format('Y-m-d H:i:s');
            $insert->executeStatement([$owner, 'Tarefa representativa '.$i, str_repeat('Detalhes. ', 24), $date, $date]);
        }
    }
    $results = [];
    foreach ([1, 250, 500] as $page) {
        $plans = [];
        foreach (['uncached', 'cold', 'warm'] as $mode) {
            $samples = [];
            $counts = [];
            if ('warm' === $mode) {
                $em->clear();
                $cache->clear();
                $cached->execute($em->find(User::class, $owners[0]), new PageQuery($page, 20));
            }
            for ($i = 0; $i < 110; ++$i) {
                $em->clear();
                // Authentication already loads this row in the HTTP path (verified by HTTP tests).
                $user = $em->find(User::class, $owners[0]);
                if ('cold' === $mode) {
                    $cache->clear();
                }
                $logger->queries = [];
                $logger->enabled = true;
                $before = hrtime(true);
                $result = ('uncached' === $mode ? $list : $cached)->execute($user, new PageQuery($page, 20));
                $json = json_encode($result, JSON_THROW_ON_ERROR);
                $elapsed = (hrtime(true) - $before) / 1e6;
                $logger->enabled = false;
                if ($i >= 10) {
                    $samples[] = $elapsed;
                    $counts[] = count($logger->queries);
                }
                if (20 !== count(json_decode($json, true, flags: JSON_THROW_ON_ERROR)['items'])) {
                    throw new RuntimeException('Unexpected benchmark result.');
                }
            }
            sort($samples);
            if ('uncached' === $mode) {
                foreach ($logger->queries as $query) {
                    $plans[] = ['sql' => $query['sql'], 'plan' => $db->executeQuery('EXPLAIN '.$query['sql'], array_values($query['params'] ?? []))->fetchAllAssociative()];
                }
            }
            $counts = array_values(array_unique($counts));
            if ($counts !== ['warm' === $mode ? 0 : 2]) {
                throw new RuntimeException('Unexpected query count for '.$mode.': '.json_encode($counts));
            }
            $results[] = ['page' => $page, 'mode' => $mode, 'limit' => 20, 'samples' => 100, 'queryCounts' => $counts, 'medianMs' => round(($samples[49] + $samples[50]) / 2, 3), 'p95Ms' => round($samples[94], 3), 'explain' => 'uncached' === $mode ? $plans : []];
        }
    }
    $json = json_encode(['measuredAtUtc' => gmdate('c'), 'mysql' => $db->fetchOne('SELECT VERSION()'), 'php' => PHP_VERSION, 'redis' => $redis->info('server')['redis_version'], 'architecture' => php_uname('m'), 'fixture' => ['users' => 2, 'tasksPerUser' => 10000, 'descriptionCharacters' => 240, 'transaction' => 'uncommitted fixture rolled back in finally'], 'scope' => 'TaskListQuery + DTO/JSON; warm metadata/DB, cleared identity map; excludes owner load (1 authentication query per HTTP request), routing, JWT, browser network and cache flush/preheat. Cold = Redis empty per sample. Warm = Redis preheated. Sequential single process, 10 warmup + 100 measured samples per scenario.', 'results' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    $output = getopt('', ['output:'])['output'] ?? null;
    if (is_string($output)) {
        if (!is_dir(dirname($output))) {
            mkdir(dirname($output), 0775, true);
        }
        file_put_contents($output, $json);
        echo 'Benchmark saved to '.$output."\n";
    } else {
        echo $json;
    }
} finally {
    $cache->clear();
    $db->rollBack();
    $kernel->shutdown();
}
