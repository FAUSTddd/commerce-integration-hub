<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Handler;

use App\Application\Handler\CatalogImportHandler;
use App\Domain\Model\Product;
use App\Domain\Repository\ProductRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use FaustDDD\Symfony1cImport\Application\Command\ImportCatalogCommand;
use PHPUnit\Framework\TestCase;

final class CatalogImportHandlerTest extends TestCase
{
    public function testImportsCatalogAndUpdatesIdempotently(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->atLeastOnce())->method('flush');

        $storage = [];
        $repo = $this->createMock(ProductRepositoryInterface::class);

        $repo->method('findByExternalId')->willReturnCallback(function (string $extId) use (&$storage) {
            return $storage[$extId] ?? null;
        });

        $repo->method('save')->willReturnCallback(function (Product $product) use (&$storage) {
            $storage[$product->getExternalId()] = $product;
        });

        $handler = new CatalogImportHandler($em, $repo);

        $fixturePath = dirname(__DIR__, 3) . '/Fixtures/import_catalog.xml';
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
