<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class MonitoringApiTest extends WebTestCase
{
    public function testUnauthenticatedAccessReturns401(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/api/health');

        self::assertResponseStatusCodeSame(401);
        self::assertTrue($client->getResponse()->headers->has('WWW-Authenticate'));
    }

    public function testInvalidCredentialsReturns401(): void
    {
        $client = static::createClient([], [
            'PHP_AUTH_USER' => 'admin',
            'PHP_AUTH_PW' => 'wrong_password',
        ]);
        $client->request('GET', '/admin/api/health');

        self::assertResponseStatusCodeSame(401);
    }

    public function testPostMethodReturns405(): void
    {
        $client = static::createClient([], [
            'PHP_AUTH_USER' => 'admin',
            'PHP_AUTH_PW' => 'test_admin_pass',
        ]);
        $client->request('POST', '/admin/api/health');

        self::assertResponseStatusCodeSame(405);
    }

    public function testDeleteMethodReturns405(): void
    {
        $client = static::createClient([], [
            'PHP_AUTH_USER' => 'admin',
            'PHP_AUTH_PW' => 'test_admin_pass',
        ]);
        $client->request('DELETE', '/admin/api/health');

        self::assertResponseStatusCodeSame(405);
    }

    public function testAuthenticatedGetReturns200AndValidJsonContract(): void
    {
        $client = static::createClient([], [
            'PHP_AUTH_USER' => 'admin',
            'PHP_AUTH_PW' => 'test_admin_pass',
        ]);
        $client->request('GET', '/admin/api/health');

        self::assertResponseIsSuccessful();
        self::assertResponseStatusCodeSame(200);
        self::assertTrue($client->getResponse()->headers->contains('Content-Type', 'application/json'));
        self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('no-cache'));
        self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('no-store'));
        self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('must-revalidate'));

        $content = (string) $client->getResponse()->getContent();
        /** @var array<string, mixed> $data */
        $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('status', $data);
        self::assertContains($data['status'], ['HEALTHY', 'DEGRADED', 'CRITICAL']);
        self::assertArrayHasKey('generated_at', $data);
        self::assertArrayHasKey('anomalies', $data);
        self::assertIsArray($data['anomalies']);
        self::assertArrayHasKey('queues', $data);
        self::assertIsArray($data['queues']);
        self::assertArrayHasKey('outbox', $data);
        self::assertIsArray($data['outbox']);
        self::assertArrayHasKey('unpublished_count', $data['outbox']);
        self::assertArrayHasKey('max_lag_seconds', $data['outbox']);
        self::assertArrayHasKey('active_runs', $data);
        self::assertIsArray($data['active_runs']);
    }
}
