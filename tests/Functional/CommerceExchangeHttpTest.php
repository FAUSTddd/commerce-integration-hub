<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ImportRun;
use App\Entity\Product;
use App\Entity\ProductVariant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CommerceExchangeHttpTest extends WebTestCase
{
    public function testEndToEnd1cExchangeAndAsyncProcessing(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        // Clean tables
        $conn = $em->getConnection();
        $conn->executeStatement('DELETE FROM product_variants');
        $conn->executeStatement('DELETE FROM products');
        $conn->executeStatement('DELETE FROM import_runs');
        $conn->executeStatement('DELETE FROM messenger_messages');

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
        $catalogXml = (string) file_get_contents(static::getContainer()->getParameter('kernel.project_dir') . '/tests/Fixtures/import_catalog.xml');
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

        // Verify message in messenger transport table and ImportRun created
        $msgCount = (int) $conn->fetchOne('SELECT COUNT(*) FROM messenger_messages');
        self::assertSame(1, $msgCount, 'Catalog import message must be in messenger queue');

        $run = $em->getRepository(ImportRun::class)->findOneBy(['type' => 'catalog', 'filename' => 'import.xml']);
        self::assertNotNull($run, 'ImportRun record should exist');
        self::assertSame(ImportRun::STATUS_PENDING, $run->getStatus());

        // Consume queue (Worker simulation)
        $kernel = static::$kernel;
        $application = new \Symfony\Bundle\FrameworkBundle\Console\Application($kernel);
        $application->setAutoExit(false);

        $input = new \Symfony\Component\Console\Input\ArrayInput([
            'command' => 'messenger:consume',
            'receivers' => ['async'],
            '--limit' => 1,
            '--time-limit' => 10,
            '--quiet' => true,
        ]);
        $output = new \Symfony\Component\Console\Output\NullOutput();
        $exitCode = $application->run($input, $output);
        self::assertSame(0, $exitCode);

        // Verify catalog results
        $em->clear();
        $products = $em->getRepository(Product::class)->findAll();
        self::assertCount(3, $products);

        $completedRun = $em->getRepository(ImportRun::class)->find($run->getId());
        self::assertNotNull($completedRun);
        self::assertSame(ImportRun::STATUS_SUCCESS, $completedRun->getStatus());
        self::assertSame(3, $completedRun->getItemsCount());

        // 5. Step File upload for offers
        $offersXml = (string) file_get_contents(static::getContainer()->getParameter('kernel.project_dir') . '/tests/Fixtures/import_offers.xml');
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

        // Consume queue for offers
        $exitCodeOffers = $application->run($input, $output);
        self::assertSame(0, $exitCodeOffers);

        // Verify offers results
        $em->clear();
        $variants = $em->getRepository(ProductVariant::class)->findAll();
        self::assertCount(2, $variants);

        $completedOffersRun = $em->getRepository(ImportRun::class)->findOneBy(['type' => 'offers', 'filename' => 'offers.xml']);
        self::assertNotNull($completedOffersRun);
        self::assertSame(ImportRun::STATUS_SUCCESS, $completedOffersRun->getStatus());
        self::assertSame(2, $completedOffersRun->getItemsCount());
    }
}
