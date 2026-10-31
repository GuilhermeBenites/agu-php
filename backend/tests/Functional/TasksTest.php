<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TasksTest extends WebTestCase
{
    private KernelBrowser $client;
    private array $names = [];
    private string $token;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->token = $this->account();
    }

    protected function tearDown(): void
    {
        $db = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        foreach ($this->names as $name) {
            $db->delete('users', ['username' => $name]);
        }
        parent::tearDown();
    }

    public function testCrudPersistsNormalizedDataDatesAndExactResponseShapes(): void
    {
        $this->request('GET', '/api/tasks');
        self::assertSame(['items' => [], 'pagination' => ['page' => 1, 'limit' => 20, 'total' => 0, 'totalPages' => 0]], $this->body());
        $this->request('POST', '/api/tasks', ['title' => '  Planejar o dia  ', 'description' => "\n  Primeiro passo \n"]);
        self::assertResponseStatusCodeSame(201);
        $created = $this->body();
        self::assertSame(['id', 'title', 'description', 'createdAt', 'updatedAt'], array_keys($created));
        self::assertIsInt($created['id']);
        self::assertSame('Planejar o dia', $created['title']);
        self::assertSame('Primeiro passo', $created['description']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $created['createdAt']);
        self::assertSame($created['createdAt'], $created['updatedAt']);
        $path = '/api/tasks/'.$created['id'];
        self::assertResponseHeaderSame('Location', $path);
        $this->request('GET', $path);
        self::assertSame($created, $this->body());
        // A deterministic older timestamp verifies touch without timing-dependent sleeps.
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->update('tasks', ['updated_at' => '2000-01-01 00:00:00'], ['id' => $created['id']]);
        $this->request('PUT', $path, ['title' => ' Revisar ', 'description' => null]);
        self::assertResponseIsSuccessful();
        $updated = $this->body();
        self::assertSame('Revisar', $updated['title']);
        self::assertNull($updated['description']);
        self::assertSame($created['createdAt'], $updated['createdAt']);
        self::assertGreaterThan('2000-01-01T00:00:00Z', $updated['updatedAt']);
        $this->request('GET', $path);
        self::assertSame($updated, $this->body());
        $this->request('DELETE', $path);
        self::assertResponseStatusCodeSame(204);
        self::assertSame('', $this->client->getResponse()->getContent());
        $this->request('DELETE', $path);
        $this->assertError(404, 'task_not_found');
        $this->request('GET', $path);
        $this->assertError(404, 'task_not_found');
    }

    public function testOtherUserHasIdenticalNotFoundAndCannotChangeTheOwnerData(): void
    {
        $this->request('POST', '/api/tasks', ['title' => 'Privada', 'description' => 'Só A']);
        $created = $this->body();
        $ownerToken = $this->token;
        $this->token = $this->account();
        $this->request('GET', '/api/tasks');
        self::assertSame([], $this->body()['items']);
        self::assertSame(0, $this->body()['pagination']['total']);
        foreach (['GET', 'PUT', 'DELETE'] as $method) {
            foreach ([(string) $created['id'], '2147483647', '0', '-1', '1.2', 'abc', '2147483648', str_repeat('9', 50)] as $id) {
                // Even an invalid body must be hidden behind the same ownership lookup.
                $this->client->request($method, '/api/tasks/'.$id, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token, 'CONTENT_TYPE' => 'application/json'], content: '{');
                $this->assertError(404, 'task_not_found');
                self::assertSame(['code' => 'task_not_found', 'message' => 'Tarefa não encontrada.', 'details' => []], $this->body()['error']);
            }
        }
        $this->token = $ownerToken;
        $this->request('GET', '/api/tasks/'.$created['id']);
        self::assertSame($created, $this->body());
    }

    public function testPaginationTraversesStableTiesWithoutDuplicatesOrMissingItems(): void
    {
        $ids = [];
        for ($i = 0; $i < 25; ++$i) {
            $this->request('POST', '/api/tasks', ['title' => 'Tarefa '.$i]);
            self::assertResponseStatusCodeSame(201);
            $ids[] = $this->body()['id'];
        }
        $db = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        foreach ($ids as $id) {
            $db->update('tasks', ['created_at' => '2026-01-01 00:00:00'], ['id' => $id]);
        }
        $seen = [];
        for ($page = 1; $page <= 4; ++$page) {
            $this->request('GET', '/api/tasks?page='.$page.'&limit=7');
            self::assertResponseIsSuccessful();
            $body = $this->body();
            self::assertSame(['page' => $page, 'limit' => 7, 'total' => 25, 'totalPages' => 4], $body['pagination']);
            $seen = array_merge($seen, array_column($body['items'], 'id'));
        }
        self::assertSame(array_reverse($ids), $seen);
        $this->request('GET', '/api/tasks?page=2147483647&limit=100');
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->body()['items']);
        self::assertSame(25, $this->body()['pagination']['total']);
        $this->request('GET', '/api/tasks');
        self::assertCount(20, $this->body()['items']);
    }

    /** @dataProvider invalidQueries */
    public function testQueryValidation(string $query): void
    {
        $this->request('GET', '/api/tasks?'.$query);
        $this->assertError(422, 'validation_failed');
    }

    public static function invalidQueries(): iterable
    {
        foreach (['page=0', 'page=-1', 'page=1.5', 'page=1e2', 'page=abc', 'page=', 'page[]=1', 'page=2147483648', 'limit=0', 'limit=101', 'limit=1.2', 'limit[]=2', 'sort=id', 'userId=1'] as $query) {
            yield $query => [$query];
        }
    }

    /** @dataProvider invalidBodies */
    public function testInvalidCreateAndUpdateBodies(string $json, int $status, string $code): void
    {
        $this->request('POST', '/api/tasks', ['title' => 'Original']);
        $path = '/api/tasks/'.$this->body()['id'];
        foreach ([['POST', '/api/tasks'], ['PUT', $path]] as [$method, $uri]) {
            $this->client->request($method, $uri, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token, 'CONTENT_TYPE' => 'application/json; charset=UTF-8'], content: $json);
            $this->assertError($status, $code);
        }
        $this->request('GET', $path);
        self::assertSame('Original', $this->body()['title']);
        $this->request('GET', '/api/tasks');
        self::assertSame(1, $this->body()['pagination']['total']);
    }

    public static function invalidBodies(): iterable
    {
        yield 'empty' => ['', 400, 'invalid_json'];
        yield 'malformed' => ['{', 400, 'invalid_json'];
        foreach (['[]', 'null', '123', '{}', '{"title":null}', '{"title":12}', '{"title":""}', '{"title":" \n\t"}', '{"title":"\u00a0"}', '{"title":"Ok","description":[]}', '{"title":"Ok","userId":2}', '{"title":"Ok","id":1}', '{"title":"Ok","createdAt":"2026-01-01"}'] as $json) {
            yield $json => [$json, 422, 'validation_failed'];
        }
        yield 'raw title limit before trim' => [json_encode(['title' => ' '.str_repeat('é', 255)], JSON_THROW_ON_ERROR), 422, 'validation_failed'];
        yield 'raw description limit before trim' => [json_encode(['title' => 'Ok', 'description' => str_repeat('é', 10000).' '], JSON_THROW_ON_ERROR), 422, 'validation_failed'];
    }

    public function testReplacementRequiresDescriptionAndRawUnicodeLimitsAreAccepted(): void
    {
        $this->request('POST', '/api/tasks', ['title' => str_repeat('é', 255), 'description' => str_repeat('é', 10000)]);
        self::assertResponseStatusCodeSame(201);
        $id = $this->body()['id'];
        $this->request('PUT', '/api/tasks/'.$id, ['title' => 'Novo']);
        $this->assertError(422, 'validation_failed');
        $this->request('PUT', '/api/tasks/'.$id, ['title' => 'Novo', 'description' => " \n\t "]);
        self::assertResponseIsSuccessful();
        self::assertNull($this->body()['description']);
    }

    public function testMediaTypeAndAuthenticationPrecedePayloadValidation(): void
    {
        $this->client->request('POST', '/api/tasks', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token, 'CONTENT_TYPE' => 'text/plain'], content: '{}');
        $this->assertError(415, 'unsupported_media_type');
        foreach (['GET', 'POST', 'PUT', 'DELETE'] as $method) {
            $this->client->request($method, '/api/tasks'.(in_array($method, ['PUT', 'DELETE'], true) ? '/invalid' : ''), server: ['CONTENT_TYPE' => 'application/json'], content: '{');
            $this->assertError(401, 'token_missing');
        }
    }

    public function testHttpCacheHitsStillAuthenticateAndUseFreshRequestIds(): void
    {
        $this->request('POST', '/api/tasks', ['title' => 'Cache via HTTP']);
        $this->request('GET', '/api/tasks');
        $first = $this->body();
        $firstRequest = $this->client->getResponse()->headers->get('X-Request-ID');
        $queries = self::getContainer()->get(\App\Tests\Support\QueryLog::class)->queries;
        self::assertCount(2, array_filter($queries, static fn ($query) => str_contains($query['sql'], 'FROM tasks')));
        $this->request('GET', '/api/tasks');
        self::assertSame($first, $this->body());
        self::assertNotSame($firstRequest, $this->client->getResponse()->headers->get('X-Request-ID'));
        $queries = self::getContainer()->get(\App\Tests\Support\QueryLog::class)->queries;
        self::assertCount(0, array_filter($queries, static fn ($query) => str_contains($query['sql'], 'FROM tasks')));
        self::assertCount(1, array_filter($queries, static fn ($query) => str_contains($query['sql'], 'FROM users')));
        $this->request('POST', '/api/tasks', ['title' => 'Nova geração']);
        $this->request('GET', '/api/tasks');
        self::assertSame(2, $this->body()['pagination']['total']);
        $this->client->request('GET', '/api/tasks');
        $this->assertError(401, 'token_missing');
    }

    private function account(): string
    {
        $name = 'tasks_'.bin2hex(random_bytes(6));
        $this->names[] = $name;
        $this->client->jsonRequest('POST', '/api/register', ['username' => $name, 'password' => 'password-example']);
        self::assertResponseStatusCodeSame(201);
        $this->client->jsonRequest('POST', '/api/login', ['username' => $name, 'password' => 'password-example']);
        self::assertResponseIsSuccessful();

        return $this->body()['token'];
    }

    private function request(string $method, string $uri, ?array $data = null): void
    {
        $this->client->request($method, $uri, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token, 'CONTENT_TYPE' => 'application/json'], content: null === $data ? null : json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function body(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function assertError(int $status, string $code): void
    {
        self::assertResponseStatusCodeSame($status);
        $body = $this->body();
        self::assertSame($code, $body['error']['code']);
        self::assertIsArray($body['error']['details']);
        self::assertSame($body['requestId'], $this->client->getResponse()->headers->get('X-Request-ID'));
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
    }
}
