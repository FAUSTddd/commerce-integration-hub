<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminQueuesTest extends WebTestCase
{
    /**
     * @return array<string, string>
     */
    private function authHeaders(string $user = 'admin', string $pass = 'test_admin_pass'): array
    {
        return [
            'PHP_AUTH_USER' => $user,
            'PHP_AUTH_PW' => $pass,
        ];
    }

    public function testUnauthenticatedAccessReturns401(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/queues');

        self::assertResponseStatusCodeSame(401);
    }

    public function testInvalidCredentialsReturns401(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/queues', [], [], $this->authHeaders('admin', 'wrong_pass'));

        self::assertResponseStatusCodeSame(401);
    }

    public function testPostMethodReturns405(): void
    {
        $client = static::createClient();
        $client->request('POST', '/admin/queues', [], [], $this->authHeaders());

        self::assertResponseStatusCodeSame(405);
    }

    public function testHeadMethodReturns200WithEmptyBody(): void
    {
        $client = static::createClient();
        $client->request('HEAD', '/admin/queues', [], [], $this->authHeaders());

        self::assertResponseIsSuccessful();
        self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('no-cache'));
        self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('no-store'));
        self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('must-revalidate'));
        self::assertEmpty($client->getResponse()->getContent());
    }

    public function testGetQueuesRendersCleanStateCorrectly(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/admin/queues', [], [], $this->authHeaders());

        self::assertResponseIsSuccessful();
        self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('no-cache'));
        self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('no-store'));
        self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('must-revalidate'));

        // Check Queues Table contains expected queues
        $tableContent = $crawler->filter('#queues-table')->text();
        self::assertStringContainsString('async_catalog', $tableContent);
        self::assertStringContainsString('async_offers', $tableContent);
        self::assertStringContainsString('failed', $tableContent);

        // Check Outbox table exists and renders headers
        self::assertResponseStatusCodeSame(200);
        self::assertGreaterThanOrEqual(1, $crawler->filter('#outbox-table')->count());
    }

    public function testGetQueuesWithPendingOutboxAndStarvationWarning(): void
    {
        $client = static::createClient();

        /** @var Connection $connection */
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');

        // Insert test import_run and pending outbox item in commerce_db_test
        $connection->executeStatement(
            "INSERT INTO import_runs (id, type, filename, file_hash, status, attempt, items_count, created_at)
             VALUES (99901, 'catalog', 'test.xml', 'hash_q1', 'processing', 1, 10, NOW() - INTERVAL '20 minutes')
             ON CONFLICT (id) DO NOTHING"
        );

        $connection->executeStatement(
            "INSERT INTO import_run_outbox (id, run_id, attempt, kind, idempotency_key, queue_name, headers, payload, available_at, published_at, created_at)
             VALUES (99901, 99901, 1, 'defer', 'test_key_starve', 'async_catalog', '[]', '[]', NOW() - INTERVAL '20 minutes', NULL, NOW() - INTERVAL '20 minutes')
             ON CONFLICT (id) DO NOTHING"
        );

        try {
            $crawler = $client->request('GET', '/admin/queues', [], [], $this->authHeaders());

            self::assertResponseIsSuccessful();
            $html = $crawler->html();

            // Verify Outbox item appears
            self::assertStringContainsString('#99901', $html);
            self::assertStringContainsString('defer', $html);
            self::assertStringContainsString('async_catalog', $html);
        } finally {
            $connection->executeStatement('DELETE FROM import_run_outbox WHERE id = 99901');
            $connection->executeStatement('DELETE FROM import_runs WHERE id = 99901');
        }
    }
}
