<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminSecurityTest extends WebTestCase
{
    public function testUnauthorizedAccessReturns401(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin');

        self::assertResponseStatusCodeSame(401);
        self::assertTrue($client->getResponse()->headers->has('WWW-Authenticate'));
    }

    public function testInvalidCredentialsReturns401(): void
    {
        $client = static::createClient([], [
            'PHP_AUTH_USER' => 'admin',
            'PHP_AUTH_PW' => 'wrong_password',
        ]);
        $client->request('GET', '/admin');

        self::assertResponseStatusCodeSame(401);
    }

    public function testValidCredentialsReturns200AndRendersDashboard(): void
    {
        $client = static::createClient([], [
            'PHP_AUTH_USER' => 'admin',
            'PHP_AUTH_PW' => 'test_admin_pass',
        ]);
        $crawler = $client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        self::assertResponseStatusCodeSame(200);
        self::assertSelectorTextContains('h1', 'Commerce Hub');
        self::assertSelectorTextContains('h2', 'System Overview');

        // Check DOM structure for Vanilla JS polling targets
        self::assertGreaterThanOrEqual(1, $crawler->filter('#val-active-runs')->count());
        self::assertGreaterThanOrEqual(1, $crawler->filter('#val-system-status')->count());
        self::assertGreaterThanOrEqual(1, $crawler->filter('#val-anomalies')->count());
        self::assertGreaterThanOrEqual(1, $crawler->filter('#header-status-badge')->count());
        self::assertGreaterThanOrEqual(1, $crawler->filter('#dashboard-queues-body')->count());
        self::assertGreaterThanOrEqual(1, $crawler->filter('#dashboard-outbox-body')->count());

        // Check that polling script is present with AbortController and cleanup listeners
        $html = $crawler->html();
        self::assertStringContainsString('/admin/api/health', $html);
        self::assertStringContainsString('AbortController', $html);
        self::assertStringContainsString('beforeunload', $html);
        self::assertStringContainsString('visibilitychange', $html);
    }

    public function testPostMethodReturns405(): void
    {
        $client = static::createClient([], [
            'PHP_AUTH_USER' => 'admin',
            'PHP_AUTH_PW' => 'test_admin_pass',
        ]);
        $client->request('POST', '/admin');

        self::assertResponseStatusCodeSame(405);
    }

    public function testCmlExchangeRemainsIndependentAndAccessible(): void
    {
        $client = static::createClient();
        // CML endpoint does not return 401 from admin firewall
        $client->request('GET', '/cml/1c_exchange?type=sale&mode=checkauth');

        // Faustddd/symfony-1c-import returns its own basic auth or response, but not the admin firewall 401
        self::assertNotSame('Commerce Hub Admin', $client->getResponse()->headers->get('WWW-Authenticate'));
    }

    public function testAdminDashboardSetsStrictNoStoreCacheControlHeaders(): void
    {
        $client = static::createClient([], [
            'PHP_AUTH_USER' => 'admin',
            'PHP_AUTH_PW' => 'test_admin_pass',
        ]);
        $client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertTrue($response->headers->hasCacheControlDirective('no-cache'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertTrue($response->headers->hasCacheControlDirective('must-revalidate'));
    }

    public function testNonAdminRoutesDoNotReceiveAdminCacheControlHeaders(): void
    {
        $client = static::createClient();
        $client->request('GET', '/cml/1c_exchange?type=sale&mode=checkauth');

        $response = $client->getResponse();
        self::assertFalse($response->headers->hasCacheControlDirective('no-store'));
    }
}
