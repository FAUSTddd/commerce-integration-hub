<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\ImportRun;
use App\Entity\ImportRunOutbox;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminImportsTest extends WebTestCase
{
    private function getEntityManager(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine.orm.entity_manager');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        // Reset DB connection or cleanup test fixtures
    }

    public function testUnauthenticatedIndexReturns401(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/imports');

        self::assertResponseStatusCodeSame(401);
        self::assertTrue($client->getResponse()->headers->has('WWW-Authenticate'));
    }

    public function testUnauthenticatedShowReturns401(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/imports/1');

        self::assertResponseStatusCodeSame(401);
    }

    public function testIndexMethodNotAllowedForPost(): void
    {
        $client = static::createClient([], [
            'PHP_AUTH_USER' => 'admin',
            'PHP_AUTH_PW' => 'test_admin_pass',
        ]);
        $client->request('POST', '/admin/imports');

        self::assertResponseStatusCodeSame(405);
    }

    public function testShowMethodNotAllowedForPost(): void
    {
        $client = static::createClient([], [
            'PHP_AUTH_USER' => 'admin',
            'PHP_AUTH_PW' => 'test_admin_pass',
        ]);
        $client->request('POST', '/admin/imports/1');

        self::assertResponseStatusCodeSame(405);
    }

    public function testAuthenticatedIndexRendersRunsWithPaginationAndFilters(): void
    {
        $client = static::createClient([], [
            'PHP_AUTH_USER' => 'admin',
            'PHP_AUTH_PW' => 'test_admin_pass',
        ]);

        $em = $this->getEntityManager();
        // Seed test run
        $run = new ImportRun('catalog', 'test_feed_01.xml', hash('sha256', 'test_feed_01'));
        $run->setStatus(ImportRun::STATUS_SUCCESS);
        $run->setItemsCount(42);
        $em->persist($run);
        $em->flush();

        $client->request('GET', '/admin/imports?status=SUCCESS&type=catalog');

        self::assertResponseIsSuccessful();
        self::assertResponseStatusCodeSame(200);
        $response = $client->getResponse();
        self::assertTrue($response->headers->hasCacheControlDirective('no-cache'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertTrue($response->headers->hasCacheControlDirective('must-revalidate'));
        self::assertSelectorTextContains('table', 'test_feed_01.xml');
        self::assertSelectorTextContains('table', 'catalog');
        self::assertSelectorTextContains('table', 'SUCCESS');
        self::assertSelectorTextContains('table', '42');
    }

    public function testAuthenticatedShowRendersDetailsAndSanitizesErrorsAndSecrets(): void
    {
        $client = static::createClient([], [
            'PHP_AUTH_USER' => 'admin',
            'PHP_AUTH_PW' => 'test_admin_pass',
        ]);

        $em = $this->getEntityManager();
        $sensitiveError = 'Connection failed: postgresql://admin:supersecret@10.0.0.1:5432/commerce_db in /home/faustddd/app/vendor/autoload.php with <tag>leak</tag>';
        $run = new ImportRun('offers', 'offers_sensitive.xml', hash('sha256', 'offers_sensitive'));
        $run->setStatus(ImportRun::STATUS_FAILED);
        $run->setErrorMessage($sensitiveError);
        $em->persist($run);
        $em->flush();

        $runId = $run->getId();
        self::assertNotNull($runId);

        // Seed outbox event
        $outbox = new ImportRunOutbox(
            $runId,
            1,
            ImportRunOutbox::KIND_RETRY,
            'test:key:' . $runId,
            'messenger.retry',
            ['X-Test' => 'value'],
            ['test' => true],
            new DateTimeImmutable('+60 seconds')
        );
        $em->persist($outbox);
        $em->flush();

        $client->request('GET', '/admin/imports/' . $runId);

        self::assertResponseIsSuccessful();
        self::assertResponseStatusCodeSame(200);
        $response = $client->getResponse();
        self::assertTrue($response->headers->hasCacheControlDirective('no-cache'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertTrue($response->headers->hasCacheControlDirective('must-revalidate'));

        $responseContent = (string) $client->getResponse()->getContent();
        // Verifying Sanitization
        self::assertStringNotContainsString('supersecret', $responseContent);
        self::assertStringNotContainsString('/home/faustddd', $responseContent);
        self::assertStringNotContainsString('<tag>leak</tag>', $responseContent);
        self::assertStringContainsString('[REDACTED_DSN]', $responseContent);
        self::assertStringContainsString('[REDACTED_PATH]', $responseContent);
        self::assertStringContainsString('[XML_REDACTED]', $responseContent);

        // Verifying Outbox display
        self::assertStringContainsString('messenger.retry', $responseContent);
        self::assertStringContainsString('retry', $responseContent);
    }

    public function testShowReturns404ForNonExistentRun(): void
    {
        $client = static::createClient([], [
            'PHP_AUTH_USER' => 'admin',
            'PHP_AUTH_PW' => 'test_admin_pass',
        ]);
        $client->request('GET', '/admin/imports/999999999');

        self::assertResponseStatusCodeSame(404);
    }
}
