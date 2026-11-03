<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Entity\Product;
use App\MessageHandler\CatalogImportHandler;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use FaustDDD\Symfony1cImport\Application\Command\ImportCatalogCommand;
use PHPUnit\Framework\TestCase;

final class CatalogImportHandlerTest extends TestCase
{
    public function testImportsCatalogAndUpdatesIdempotently(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->atLeastOnce())->method('flush');

        /** @var array<string, Product> $storage */
        $storage = [];
        $repo = $this->createMock(ProductRepository::class);

        $repo->method('findByExternalId')->willReturnCallback(function (string $extId) use (&$storage) {
            return $storage[$extId] ?? null;
        });

        $repo->method('save')->willReturnCallback(function (Product $product) use (&$storage) {
            $storage[$product->getExternalId()] = $product;
        });

        $handler = new CatalogImportHandler($em, $repo);

        $fixturePath = dirname(__DIR__, 2) . '/Fixtures/import_catalog.xml';
        $command = new ImportCatalogCommand($fixturePath);

        // 1st run
        $handler->__invoke($command);

        $this->assertCount(3, $storage);
        $this->assertArrayHasKey('prod-1001', $storage);
        $this->assertSame('Кофемашина DeLonghi Magnifica S', $storage['prod-1001']->getName());
        $this->assertSame('ART-1001', $storage['prod-1001']->getSku());

        $this->assertArrayHasKey('prod-1002', $storage);
        $this->assertSame('ART-1002', $storage['prod-1002']->getSku());

        $this->assertArrayHasKey('prod-1003', $storage);
        $this->assertNull($storage['prod-1003']->getSku());

        // 2nd run: idempotency test
        $handler->__invoke($command);
        $this->assertCount(3, $storage);
    }
}
