<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Handler;

use App\Application\Handler\CatalogImportHandler;
use App\Domain\Model\Product;
use App\Domain\Repository\ProductRepositoryInterface;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use FaustDDD\Symfony1cImport\Application\Command\ImportCatalogCommand;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SimpleXMLElement;
use Throwable;

final class BatchBoundaryTest extends TestCase
{
    public function testBatchBoundaryTriggersCommitAndReopensTransaction(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(true);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);

        // Expected: initial beginTransaction, commit on 100 items, beginTransaction for second batch, final commit
        $em->expects($this->exactly(2))->method('beginTransaction');
        $em->expects($this->exactly(2))->method('commit');
        $em->expects($this->exactly(2))->method('flush');
        $em->expects($this->exactly(2))->method('clear');

        $repo = $this->createMock(ProductRepositoryInterface::class);
        $repo->method('findByExternalId')->willReturn(null);
        $repo->expects($this->exactly(105))->method('save');

        // Create temporary XML with 105 products to cross the BATCH_SIZE (100) boundary
        $tmpFile = tempnam(sys_get_temp_dir(), 'cml_batch_');
        $xmlContent = '<?xml version="1.0" encoding="UTF-8"?><КоммерческаяИнформация ВерсияСхемы="2.09" ДатаФормирования="2026-10-12"><Каталог><Товары>';
        for ($i = 1; $i <= 105; ++$i) {
            $xmlContent .= sprintf('<Товар><Ид>batch-prod-%d</Ид><Наименование>Товар %d</Наименование></Товар>', $i, $i);
        }
        $xmlContent .= '</Товары></Каталог></КоммерческаяИнформация>';
        file_put_contents($tmpFile, $xmlContent);

        try {
            $handler = new CatalogImportHandler($em, $repo);
            $handler(new ImportCatalogCommand($tmpFile));
        } finally {
            @unlink($tmpFile);
        }
    }

    public function testErrorInSecondBatchRollbacksOnlyActiveTransaction(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(true);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);

        $em->expects($this->exactly(2))->method('beginTransaction');
        $em->expects($this->once())->method('commit'); // First batch of 100 items was committed successfully
        $em->expects($this->once())->method('rollback'); // Second batch rolls back on error

        $repo = $this->createMock(ProductRepositoryInterface::class);
        $repo->method('findByExternalId')->willReturn(null);

        // Throw error on item 102 (inside 2nd batch)
        $savedCount = 0;
        $repo->method('save')->willReturnCallback(function () use (&$savedCount) {
            ++$savedCount;
            if ($savedCount === 102) {
                throw new RuntimeException('Database deadlock or connection error in batch 2');
            }
        });

        $tmpFile = tempnam(sys_get_temp_dir(), 'cml_batch_err_');
        $xmlContent = '<?xml version="1.0" encoding="UTF-8"?><КоммерческаяИнформация ВерсияСхемы="2.09" ДатаФормирования="2026-10-12"><Каталог><Товары>';
        for ($i = 1; $i <= 105; ++$i) {
            $xmlContent .= sprintf('<Товар><Ид>batch-prod-%d</Ид><Наименование>Товар %d</Наименование></Товар>', $i, $i);
        }
        $xmlContent .= '</Товары></Каталог></КоммерческаяИнформация>';
        file_put_contents($tmpFile, $xmlContent);

        $caughtException = null;
        try {
            $handler = new CatalogImportHandler($em, $repo);
            $handler(new ImportCatalogCommand($tmpFile));
        } catch (Throwable $e) {
            $caughtException = $e;
        } finally {
            @unlink($tmpFile);
        }

        $this->assertNotNull($caughtException, 'Handler must rethrow exception');
        $this->assertStringContainsString('Import failed for file', $caughtException->getMessage());
        $this->assertNotNull($caughtException->getPrevious());
        $this->assertSame('Database deadlock or connection error in batch 2', $caughtException->getPrevious()->getMessage());
    }
}
