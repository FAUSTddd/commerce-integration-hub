<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Entity\Product;
use App\MessageHandler\CatalogImportHandler;
use App\Repository\ProductRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use FaustDDD\Symfony1cImport\Application\Command\ImportCatalogCommand;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CatalogImportTransactionTest extends TestCase
{
    public function testRollbackOnErrorDuringImport(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(true);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $repo = $this->createMock(ProductRepository::class);

        $em->expects($this->once())->method('beginTransaction');
        $em->expects($this->once())->method('rollback');
        $em->expects($this->once())->method('clear'); // In finally

        $handler = new CatalogImportHandler($em, $repo);

        $this->expectException(RuntimeException::class);
        $handler(new ImportCatalogCommand('/non/existent/file.xml'));
    }

    public function testRemainingPartialBatchIsFlushed(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(true);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $em->expects($this->once())->method('beginTransaction');
        $em->expects($this->once())->method('flush'); // Flush 3 products (partial batch < 100)
        $em->expects($this->once())->method('commit');
        $em->expects($this->once())->method('clear'); // In finally

        $repo = $this->createMock(ProductRepository::class);
        $repo->method('findByExternalId')->willReturn(null);
        $repo->expects($this->exactly(3))->method('save');

        $handler = new CatalogImportHandler($em, $repo);

        $fixturePath = dirname(__DIR__, 2) . '/Fixtures/import_catalog.xml';
        $handler(new ImportCatalogCommand($fixturePath));
    }
}
