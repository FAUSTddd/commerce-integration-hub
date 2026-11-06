<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ImportRun;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Messenger\ImportRunStamp;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

final class CommerceExchangeHttpTest extends WebTestCase
{
    private const array TARGET_PRODUCT_EXTERNAL_IDS = [
        'prod-1001',
        'prod-1002',
        'prod-1003',
    ];

    private const array TARGET_VARIANT_EXTERNAL_IDS = [
        'prod-1001#var-1',
        'prod-1002',
    ];

    private ?KernelBrowser $client = null;

    /** @var list<int> */
    private array $createdRunIds = [];

    /** @var list<int> */
    private array $createdProductIds = [];

    /** @var list<int> */
    private array $createdVariantIds = [];

    private ?string $storageDir = null;
    private bool $catalogFileExistedBefore = false;
    private bool $offersFileExistedBefore = false;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Fail-closed guards: check environment before any actions
        $appEnv = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? null;
        if ($appEnv !== 'test') {
            throw new \RuntimeException(sprintf('CommerceExchangeHttpTest must run strictly under APP_ENV=test, got "%s"', (string) $appEnv));
        }

        $projectDir = dirname(__DIR__, 2);
        $this->storageDir = $projectDir . '/var/import';
        $catalogPath = $this->storageDir . '/import.xml';
        $offersPath = $this->storageDir . '/offers.xml';

        $this->catalogFileExistedBefore = is_file($catalogPath);
        $this->offersFileExistedBefore = is_file($offersPath);

        // Guard: import.xml and offers.xml must NOT exist before running the test
        if ($this->catalogFileExistedBefore || $this->offersFileExistedBefore) {
            throw new \RuntimeException(sprintf(
                'Precondition failed: storage files already exist before test execution (import.xml: %s, offers.xml: %s). Aborting to prevent data corruption.',
                $this->catalogFileExistedBefore ? 'YES' : 'NO',
                $this->offersFileExistedBefore ? 'YES' : 'NO'
            ));
        }

        // Initialize WebTestCase client exactly once before container access
        $this->client = static::createClient();

        // 2. Database guard: strictly verify database name
        /** @var Connection $conn */
        $conn = static::getContainer()->get(Connection::class);
        $dbName = (string) $conn->fetchOne('SELECT current_database()');
        if ($dbName !== 'commerce_db_test') {
            throw new \RuntimeException(sprintf('CommerceExchangeHttpTest must run strictly on commerce_db_test, got "%s"', $dbName));
        }

        // 3. Precondition guard: products with target external_ids must not exist
        $existingProductCount = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM products WHERE external_id IN (:ids)',
            ['ids' => self::TARGET_PRODUCT_EXTERNAL_IDS],
            ['ids' => ArrayParameterType::STRING]
        );
        if ($existingProductCount > 0) {
            throw new \RuntimeException(sprintf('Precondition failed: found %d existing products with target external_ids in database', $existingProductCount));
        }

        // 4. Precondition guard: product_variants with target external_ids must not exist
        $existingVariantCount = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM product_variants WHERE external_id IN (:ids)',
            ['ids' => self::TARGET_VARIANT_EXTERNAL_IDS],
            ['ids' => ArrayParameterType::STRING]
        );
        if ($existingVariantCount > 0) {
            throw new \RuntimeException(sprintf('Precondition failed: found %d existing product_variants with target external_ids in database', $existingVariantCount));
        }

        // 5. Precondition guard: no import_runs with import.xml or offers.xml
        $existingRunsCount = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM import_runs WHERE filename IN ('import.xml', 'offers.xml')"
        );
        if ($existingRunsCount > 0) {
            throw new \RuntimeException(sprintf('Precondition failed: found %d existing import_runs with filename import.xml or offers.xml', $existingRunsCount));
        }

        // 6. Precondition guard: no messages in catalog or offers_test queues
        $existingMessagesCount = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM messenger_messages WHERE queue_name IN ('catalog', 'offers_test')"
        );
        if ($existingMessagesCount > 0) {
            throw new \RuntimeException(sprintf('Precondition failed: found %d existing messages in catalog/offers_test queues', $existingMessagesCount));
        }

        $this->createdRunIds = [];
        $this->createdProductIds = [];
        $this->createdVariantIds = [];
    }

    protected function tearDown(): void
    {
        $cleanupExceptions = [];

        try {
            $em = $this->getActiveEntityManager();
            /** @var Connection $conn */
            $conn = $em->getConnection();

            // 1. Remove product variants created by this test strictly by registered PKs
            if ($this->createdVariantIds !== []) {
                try {
                    $conn->executeStatement(
                        'DELETE FROM product_variants WHERE id IN (:ids)',
                        ['ids' => $this->createdVariantIds],
                        ['ids' => ArrayParameterType::INTEGER]
                    );
                } catch (\Throwable $e) {
                    $cleanupExceptions[] = $e;
                }
            }

            // 2. Remove products created by this test strictly by registered PKs (FK safe after variants)
            if ($this->createdProductIds !== []) {
                try {
                    $conn->executeStatement(
                        'DELETE FROM products WHERE id IN (:ids)',
                        ['ids' => $this->createdProductIds],
                        ['ids' => ArrayParameterType::INTEGER]
                    );
                } catch (\Throwable $e) {
                    $cleanupExceptions[] = $e;
                }
            }

            // 3. Remove messenger_messages for runs created by this test (by decoding ImportRunStamp)
            // Fail-closed: if message decoding fails, do not proceed with deleting Outbox and ImportRun, and register cleanup exception
            if ($this->createdRunIds !== []) {
                $messengerCleanupFailed = false;
                try {
                    $serializer = new PhpSerializer();
                    $stmt = $conn->executeQuery('SELECT id, body, headers FROM messenger_messages');
                    $matchingMessageIds = [];

                    while ($row = $stmt->fetchAssociative()) {
                        $msgId = (int) $row['id'];
                        $body = (string) $row['body'];
                        $headers = json_decode((string) ($row['headers'] ?? '[]'), true);
                        if (!is_array($headers)) {
                            $headers = [];
                        }

                        try {
                            $envelope = $serializer->decode(['body' => $body, 'headers' => $headers]);
                            $stamp = $envelope->last(ImportRunStamp::class);
                            if ($stamp instanceof ImportRunStamp && in_array($stamp->getImportRunId(), $this->createdRunIds, true)) {
                                $matchingMessageIds[] = $msgId;
                            }
                        } catch (\Throwable $e) {
                            $messengerCleanupFailed = true;
                            $cleanupExceptions[] = new \RuntimeException(
                                sprintf('Failed to decode messenger_messages ID %d during cleanup: %s', $msgId, $e->getMessage()),
                                0,
                                $e
                            );
                        }
                    }

                    if (!$messengerCleanupFailed && $matchingMessageIds !== []) {
                        $conn->executeStatement(
                            'DELETE FROM messenger_messages WHERE id IN (:ids)',
                            ['ids' => $matchingMessageIds],
                            ['ids' => ArrayParameterType::INTEGER]
                        );
                    }
                } catch (\Throwable $e) {
                    $messengerCleanupFailed = true;
                    $cleanupExceptions[] = $e;
                }

                // If messenger messages inspection/cleanup failed, fail-closed: do NOT delete Outbox and ImportRun
                if (!$messengerCleanupFailed) {
                    // 4. Remove outbox records for runs created by this test
                    try {
                        $conn->executeStatement(
                            'DELETE FROM import_run_outbox WHERE run_id IN (:runIds)',
                            ['runIds' => $this->createdRunIds],
                            ['runIds' => ArrayParameterType::INTEGER]
                        );
                    } catch (\Throwable $e) {
                        $cleanupExceptions[] = $e;
                    }

                    // 5. Remove ImportRun records created by this test
                    try {
                        $conn->executeStatement(
                            'DELETE FROM import_runs WHERE id IN (:runIds)',
                            ['runIds' => $this->createdRunIds],
                            ['runIds' => ArrayParameterType::INTEGER]
                        );
                    } catch (\Throwable $e) {
                        $cleanupExceptions[] = $e;
                    }
                }
            }

            // 6. Clean up created XML files strictly if they did not exist before and exist now
            if ($this->storageDir !== null) {
                $catalogFile = $this->storageDir . '/import.xml';
                if (!$this->catalogFileExistedBefore && is_file($catalogFile)) {
                    @unlink($catalogFile);
                }

                $offersFile = $this->storageDir . '/offers.xml';
                if (!$this->offersFileExistedBefore && is_file($offersFile)) {
                    @unlink($offersFile);
                }
            }
        } catch (\Throwable $e) {
            $cleanupExceptions[] = $e;
        } finally {
            $this->client = null;
            try {
                parent::tearDown();
            } catch (\Throwable $e) {
                $cleanupExceptions[] = $e;
            }
        }

        if ($cleanupExceptions !== []) {
            $messages = array_map(static fn(\Throwable $e): string => $e->getMessage(), $cleanupExceptions);
            throw new \RuntimeException(
                sprintf('Errors occurred during CommerceExchangeHttpTest::tearDown(): %s', implode('; ', $messages)),
                0,
                $cleanupExceptions[0]
            );
        }
    }

    public function testEndToEnd1cExchangeAndAsyncProcessing(): void
    {
        $client = $this->client;
        self::assertNotNull($client, 'KernelBrowser client must be initialized in setUp()');

        // 1. Step Checkauth without credentials -> 401 failure
        $client->request('GET', '/cml/1c_exchange?mode=checkauth');
        self::assertResponseStatusCodeSame(401);
        self::assertStringStartsWith('failure', (string) $client->getResponse()->getContent());

        // Step Checkauth with valid credentials -> 200 success
        $client->request(
            'GET',
            '/cml/1c_exchange?mode=checkauth',
            [],
            [],
            [
                'PHP_AUTH_USER' => 'admin',
                'PHP_AUTH_PW' => 'secret_cml_password',
            ]
        );
        self::assertResponseStatusCodeSame(200);
        $content = (string) $client->getResponse()->getContent();
        self::assertStringStartsWith('success', $content);

        // 2. Step Init
        $client->request('GET', '/cml/1c_exchange?mode=init');
        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('zip=yes', (string) $client->getResponse()->getContent());

        // 3. Step File upload for catalog
        $catalogXml = (string) file_get_contents(dirname(__DIR__, 2) . '/tests/Fixtures/import_catalog.xml');
        $client->request(
            'POST',
            '/cml/1c_exchange?mode=file&filename=import.xml',
            [],
            [],
            ['CONTENT_TYPE' => 'application/xml'],
            $catalogXml
        );
        self::assertResponseStatusCodeSame(200);
        self::assertStringStartsWith('success', (string) $client->getResponse()->getContent());

        // 4. Step Import trigger for catalog
        $client->request('GET', '/cml/1c_exchange?mode=import&filename=import.xml');
        self::assertResponseStatusCodeSame(200);
        self::assertStringStartsWith('success', (string) $client->getResponse()->getContent());

        // Retrieve active EntityManager from current kernel container
        $em = $this->getActiveEntityManager();
        $conn = $em->getConnection();

        // Find and register catalog ImportRun record immediately
        $run = $em->getRepository(ImportRun::class)->findOneBy(['type' => 'catalog', 'filename' => 'import.xml']);
        self::assertNotNull($run, 'Catalog ImportRun record should exist');
        $catalogRunId = (int) $run->getId();
        $this->createdRunIds[] = $catalogRunId;
        self::assertSame(ImportRun::STATUS_PENDING, $run->getStatus());

        // Targeted assertion: exact deserialization via PhpSerializer verifying ImportRunStamp
        $serializer = new PhpSerializer();
        $rows = $conn->fetchAllAssociative("SELECT body, headers FROM messenger_messages WHERE queue_name = 'catalog'");
        $matched = false;
        foreach ($rows as $row) {
            /** @var array<string, mixed> $headers */
            $headers = json_decode((string) $row['headers'], true) ?? [];
            $envelope = $serializer->decode(['body' => (string) $row['body'], 'headers' => $headers]);
            $stamp = $envelope->last(ImportRunStamp::class);
            if ($stamp !== null && $stamp->getImportRunId() === $catalogRunId) {
                $matched = true;
                break;
            }
        }
        self::assertTrue($matched, 'Catalog import message with exact ImportRunStamp must be in catalog messenger queue');

        // Consume queue (Worker simulation)
        $kernel = static::$kernel;
        $application = new \Symfony\Bundle\FrameworkBundle\Console\Application($kernel);
        $application->setAutoExit(false);

        $inputCatalog = new \Symfony\Component\Console\Input\ArrayInput([
            'command' => 'messenger:consume',
            'receivers' => ['async_catalog'],
            '--limit' => 1,
            '--time-limit' => 10,
            '--quiet' => true,
        ]);
        $output = new \Symfony\Component\Console\Output\NullOutput();
        $exitCode = $application->run($inputCatalog, $output);
        self::assertSame(0, $exitCode);

        // Verify catalog results on targeted external_ids and register their PKs
        $em = $this->getActiveEntityManager();
        $em->clear();
        $products = $em->getRepository(Product::class)->findBy(['externalId' => self::TARGET_PRODUCT_EXTERNAL_IDS]);
        self::assertCount(3, $products);
        foreach ($products as $p) {
            $pId = (int) $p->getId();
            if (!in_array($pId, $this->createdProductIds, true)) {
                $this->createdProductIds[] = $pId;
            }
        }

        $completedRun = $em->getRepository(ImportRun::class)->find($catalogRunId);
        self::assertNotNull($completedRun);
        self::assertSame(ImportRun::STATUS_SUCCESS, $completedRun->getStatus());
        self::assertSame(3, $completedRun->getItemsCount());

        // 5. Step File upload for offers (exclude non-existing-prod-999 to avoid synthetic recoverable retry collision with baseline active catalog)
        $rawOffersXml = (string) file_get_contents(dirname(__DIR__, 2) . '/tests/Fixtures/import_offers.xml');
        $filteredOffersXml = preg_replace(
            '~<Предложение>\s*<Ид>non-existing-prod-999#var-1</Ид>.*?</Предложение>\s*~su',
            '',
            $rawOffersXml
        );
        if ($filteredOffersXml === null || trim($filteredOffersXml) === '') {
            throw new \RuntimeException('Failed to filter offers XML: preg_replace returned null or empty result');
        }
        $offersXml = $filteredOffersXml;
        $offersElementCount = substr_count($offersXml, '<Предложение>');
        if ($offersElementCount !== 2) {
            throw new \RuntimeException(sprintf('Expected exactly 2 <Предложение> elements in filtered offers XML, got %d', $offersElementCount));
        }
        $client->request(
            'POST',
            '/cml/1c_exchange?mode=file&filename=offers.xml',
            [],
            [],
            ['CONTENT_TYPE' => 'application/xml'],
            $offersXml
        );
        self::assertResponseStatusCodeSame(200);
        self::assertStringStartsWith('success', (string) $client->getResponse()->getContent());

        // Step Import trigger for offers
        $client->request('GET', '/cml/1c_exchange?mode=import&filename=offers.xml');
        self::assertResponseStatusCodeSame(200);
        self::assertStringStartsWith('success', (string) $client->getResponse()->getContent());

        // Find and register offers ImportRun record immediately
        $em = $this->getActiveEntityManager();
        $completedOffersRun = $em->getRepository(ImportRun::class)->findOneBy(['type' => 'offers', 'filename' => 'offers.xml']);
        self::assertNotNull($completedOffersRun, 'Offers ImportRun record should exist');
        $offersRunId = (int) $completedOffersRun->getId();
        $this->createdRunIds[] = $offersRunId;
        self::assertSame(ImportRun::STATUS_PENDING, $completedOffersRun->getStatus());

        // Consume queue for offers
        $inputOffers = new \Symfony\Component\Console\Input\ArrayInput([
            'command' => 'messenger:consume',
            'receivers' => ['async_offers'],
            '--limit' => 1,
            '--time-limit' => 10,
            '--quiet' => true,
        ]);
        $exitCodeOffers = $application->run($inputOffers, $output);
        self::assertSame(0, $exitCodeOffers);

        // Verify offers results on targeted external_ids and register their PKs
        $em = $this->getActiveEntityManager();
        $em->clear();
        $variants = $em->getRepository(ProductVariant::class)->findBy(['externalId' => self::TARGET_VARIANT_EXTERNAL_IDS]);
        self::assertCount(2, $variants);
        foreach ($variants as $v) {
            $vId = (int) $v->getId();
            if (!in_array($vId, $this->createdVariantIds, true)) {
                $this->createdVariantIds[] = $vId;
            }
        }

        $freshOffersRun = $em->getRepository(ImportRun::class)->find($offersRunId);
        self::assertNotNull($freshOffersRun);
        self::assertSame(ImportRun::STATUS_SUCCESS, $freshOffersRun->getStatus());
        self::assertSame(2, $freshOffersRun->getItemsCount());
    }

    private function getActiveEntityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        return $em;
    }
}
