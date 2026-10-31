<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AuthenticationTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $username;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->username = 'auth_'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->getConnection()->delete('users', ['username' => $this->username]);
        parent::tearDown();
    }

    public function testRegistrationHashesPasswordNormalizesUsernameAndDoesNotExposeSecrets(): void
    {
        $this->client->jsonRequest('POST', '/api/register', ['username' => strtoupper($this->username), 'password' => ' senha com espaços ']);
        self::assertResponseStatusCodeSame(201);
        $body = $this->body();
        self::assertSame(['id', 'username'], array_keys($body));
        self::assertIsInt($body['id']);
        self::assertSame($this->username, $body['username']);
        $user = self::getContainer()->get(UserRepository::class)->findOneBy(['username' => $this->username]);
        self::assertInstanceOf(User::class, $user);
        self::assertNotSame(' senha com espaços ', $user->getPassword());
        self::assertTrue(password_verify(' senha com espaços ', $user->getPassword()));
        $this->client->jsonRequest('POST', '/api/login', ['username' => $this->username, 'password' => 'senha com espaços']);
        self::assertResponseStatusCodeSame(401);
    }

    public function testLoginAndMeHaveTheDocumentedTypesAndIdentity(): void
    {
        $this->register();
        $this->client->jsonRequest('POST', '/api/login', ['username' => strtoupper($this->username), 'password' => 'password-example']);
        self::assertResponseIsSuccessful();
        $session = $this->body();
        self::assertSame(['token', 'tokenType', 'expiresIn', 'user'], array_keys($session));
        self::assertSame('Bearer', $session['tokenType']);
        self::assertSame(3600, $session['expiresIn']);
        self::assertIsString($session['token']);
        self::assertSame(['id', 'username'], array_keys($session['user']));
        $this->client->request('GET', '/api/me', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$session['token']]);
        self::assertResponseIsSuccessful();
        self::assertSame($session['user'], $this->body());
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
    }

    public function testDuplicateUsernameIsAConflictRegardlessOfCase(): void
    {
        $this->register();
        $this->client->jsonRequest('POST', '/api/register', ['username' => strtoupper($this->username), 'password' => 'other-password']);
        $this->assertError(409, 'username_taken');
    }

    public function testUnknownUserAndWrongPasswordHaveIdenticalCredentialErrors(): void
    {
        $this->client->jsonRequest('POST', '/api/login', ['username' => $this->username, 'password' => 'password-example']);
        $this->assertError(401, 'invalid_credentials');
        $unknown = $this->body()['error'];
        $this->register();
        $this->client->jsonRequest('POST', '/api/login', ['username' => $this->username, 'password' => 'wrong-password']);
        $this->assertError(401, 'invalid_credentials');
        self::assertSame($unknown, $this->body()['error']);
    }

    /** @dataProvider invalidPayloads */
    public function testInvalidPayloadsAreRejected(string $json, int $status, string $code): void
    {
        foreach (['/api/register', '/api/login'] as $path) {
            $this->client->request('POST', $path, server: ['CONTENT_TYPE' => 'application/json'], content: $json);
            $this->assertError($status, $code);
        }
    }

    /** @return iterable<string, array{string, int, string}> */
    public static function invalidPayloads(): iterable
    {
        yield 'empty body' => ['', 400, 'invalid_json'];
        yield 'malformed JSON' => ['{', 400, 'invalid_json'];
        yield 'array' => ['[]', 422, 'validation_failed'];
        yield 'null' => ['null', 422, 'validation_failed'];
        yield 'scalar' => ['123', 422, 'validation_failed'];
        yield 'missing fields' => ['{}', 422, 'validation_failed'];
        yield 'wrong types' => ['{"username":12,"password":[]}', 422, 'validation_failed'];
        yield 'empty fields' => ['{"username":"","password":""}', 422, 'validation_failed'];
        yield 'short username' => ['{"username":"ab","password":"password-example"}', 422, 'validation_failed'];
        yield 'whitespace username' => ['{"username":" ana ","password":"password-example"}', 422, 'validation_failed'];
        yield 'long username' => [json_encode(['username' => str_repeat('a', 51), 'password' => 'password-example'], JSON_THROW_ON_ERROR), 422, 'validation_failed'];
        yield 'short unicode password' => [json_encode(['username' => 'ana', 'password' => str_repeat('é', 7)], JSON_THROW_ON_ERROR), 422, 'validation_failed'];
        yield 'password above byte limit' => [json_encode(['username' => 'ana', 'password' => str_repeat('é', 37)], JSON_THROW_ON_ERROR), 422, 'validation_failed'];
        yield 'unknown field' => ['{"username":"ana","password":"password-example","role":"admin"}', 422, 'validation_failed'];
    }

    public function testByteBoundaryPasswordAndJsonCharsetAreAccepted(): void
    {
        $password = str_repeat('é', 36);
        $this->client->request('POST', '/api/register', server: ['CONTENT_TYPE' => 'application/json; charset=UTF-8'], content: json_encode(['username' => $this->username, 'password' => $password], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        $this->client->jsonRequest('POST', '/api/login', ['username' => $this->username, 'password' => $password]);
        self::assertResponseIsSuccessful();
    }

    public function testNonJsonRequestIsUnsupported(): void
    {
        $this->client->request('POST', '/api/register', server: ['CONTENT_TYPE' => 'text/plain'], content: '{}');
        $this->assertError(415, 'unsupported_media_type');
    }

    public function testMissingAndInvalidTokensAreRejected(): void
    {
        $this->client->request('GET', '/api/me');
        $this->assertError(401, 'token_missing');
        $this->client->request('GET', '/api/me', server: ['HTTP_AUTHORIZATION' => 'Bearer invalid-token']);
        $this->assertError(401, 'token_invalid');
        self::assertResponseHeaderSame('WWW-Authenticate', 'Bearer');
    }

    public function testValidlySignedExpiredTokenIsRejected(): void
    {
        $this->register();
        $user = self::getContainer()->get(UserRepository::class)->findOneBy(['username' => $this->username]);
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->createFromPayload($user, ['iat' => time() - 120, 'exp' => time() - 60]);
        $this->client->request('GET', '/api/me', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        $this->assertError(401, 'token_expired');
    }

    public function testTamperedSignatureCannotAuthenticate(): void
    {
        $this->register();
        $this->client->jsonRequest('POST', '/api/login', ['username' => $this->username, 'password' => 'password-example']);
        $parts = explode('.', $this->body()['token']);
        $parts[2] = ('a' === $parts[2][0] ? 'b' : 'a').substr($parts[2], 1);
        $this->client->request('GET', '/api/me', server: ['HTTP_AUTHORIZATION' => 'Bearer '.implode('.', $parts)]);
        $this->assertError(401, 'token_invalid');
    }

    public function testPublicLoginIgnoresAnExpiredOrInvalidBearerHeader(): void
    {
        $this->register();
        $this->client->jsonRequest('POST', '/api/login', ['username' => $this->username, 'password' => 'password-example'], ['HTTP_AUTHORIZATION' => 'Bearer invalid-token']);
        self::assertResponseIsSuccessful();
    }

    private function register(): void
    {
        $this->client->jsonRequest('POST', '/api/register', ['username' => $this->username, 'password' => 'password-example']);
        self::assertResponseStatusCodeSame(201);
    }

    /** @return array<string, mixed> */
    private function body(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function assertError(int $status, string $code): void
    {
        self::assertResponseStatusCodeSame($status);
        self::assertResponseFormatSame('json');
        $body = $this->body();
        self::assertSame(['error', 'requestId'], array_keys($body));
        self::assertSame($code, $body['error']['code']);
        self::assertIsString($body['error']['message']);
        self::assertIsArray($body['error']['details']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $body['requestId']);
        self::assertSame($body['requestId'], $this->client->getResponse()->headers->get('X-Request-ID'));
        if (422 === $status) {
            self::assertNotEmpty($body['error']['details']);
        }
    }
}
