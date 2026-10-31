<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
(new Dotenv())->bootEnv(dirname(__DIR__).'/.env', 'test');

$testUrl = $_SERVER['TEST_DATABASE_URL'] ?? $_ENV['TEST_DATABASE_URL'] ?? '';
$developmentUrl = $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? '';
$testName = is_string($testUrl) ? ltrim((string) parse_url($testUrl, PHP_URL_PATH), '/') : '';
$developmentName = is_string($developmentUrl) ? ltrim((string) parse_url($developmentUrl, PHP_URL_PATH), '/') : '';

if (!str_ends_with($testName, '_test') || $testName === $developmentName) {
    throw new RuntimeException('TEST_DATABASE_URL must use a distinct database ending in _test.');
}
