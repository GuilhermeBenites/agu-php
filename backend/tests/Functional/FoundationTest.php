<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class FoundationTest extends WebTestCase
{
    public function testHealthIsPublicAndDoesNotExposeInternalConfiguration(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/health');

        self::assertResponseIsSuccessful();
        self::assertResponseFormatSame('json');
        self::assertSame(['status' => 'ok'], json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR));
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $client->getResponse()->headers->get('X-Request-ID'));
    }

    public function testTasksRequireAuthenticationAndReturnTheDocumentedErrorEnvelope(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/tasks');

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('WWW-Authenticate', 'Bearer');
        $body = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['code' => 'token_missing', 'message' => 'Autenticação necessária.', 'details' => []], $body['error']);
        self::assertSame($body['requestId'], $client->getResponse()->headers->get('X-Request-ID'));
    }

    public function testUnsupportedMethodReturnsJsonAndAllowHeader(): void
    {
        $client = self::createClient();
        $client->request('POST', '/api/health');

        self::assertResponseStatusCodeSame(405);
        self::assertResponseHasHeader('Allow');
        self::assertSame('method_not_allowed', json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['error']['code']);
    }

    public function testCorsPreflightDoesNotRequireAuthentication(): void
    {
        $client = self::createClient();
        $client->request('OPTIONS', '/api/tasks', server: [
            'HTTP_ORIGIN' => 'http://localhost:4200',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization',
        ]);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Access-Control-Allow-Origin', 'http://localhost:4200');
    }

    /** @dataProvider disallowedOrigins */
    public function testCorsDoesNotAllowAnUnknownOrigin(string $origin): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/health', server: ['HTTP_ORIGIN' => $origin]);

        self::assertResponseIsSuccessful();
        self::assertResponseNotHasHeader('Access-Control-Allow-Origin');
    }

    /** @return iterable<string, array{string}> */
    public static function disallowedOrigins(): iterable
    {
        yield 'external origin' => ['https://untrusted.example'];
        yield 'other localhost port' => ['http://localhost:4201'];
    }
}
