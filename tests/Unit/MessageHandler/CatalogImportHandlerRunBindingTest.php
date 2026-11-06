<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Entity\ImportRun;
use App\Entity\Product;
use App\MessageHandler\CatalogImportHandler;
use App\Messenger\ImportRunContext;
use App\Repository\ImportRunRepository;
use App\Repository\ProductRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use FaustDDD\Symfony1cImport\Application\Command\ImportCatalogCommand;
use PHPUnit\Framework\TestCase;

final class CatalogImportHandlerRunBindingTest extends TestCase
{
    private string $fixturePath;

    protected function setUp(): void
    {
        $this->fixturePath = dirname(__DIR__, 2) . '/Fixtures/import_catalog.xml';
    }

    public function testPrefersImportRunContextExactId(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $conn = $this->createMock(Connection::class);
        $em->method('getConnection')->willReturn($conn);

        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->method('findByExternalId')->willReturn(null);

        $run = new ImportRun('catalog', 'import_catalog.xml', 'test_hash');
        $ref = new \ReflectionProperty(ImportRun::class, 'id');
        $ref->setValue($run, 888);

        $runRepo = $this->createMock(ImportRunRepository::class);
        $runRepo->expects($this->once())
            ->method('find')
            ->with(888)
            ->willReturn($run);
        $runRepo->method('tryAcquireSessionAdvisoryLock')->willReturn(true);
        $runRepo->method('claimRunCas2')->willReturn(true);

        // findLatestPendingOrProcessing must NEVER be called
        $runRepo->expects($this->never())
            ->method('findLatestPendingOrProcessing');

        $runRepo->expects($this->once())
            ->method('save')
            ->with($run);

        $context = new ImportRunContext();
        $context->setCurrentRunId(888);

        $handler = new CatalogImportHandler($em, $productRepo, $runRepo, $context);
        $handler->__invoke(new ImportCatalogCommand($this->fixturePath));

        $this->assertSame(ImportRun::STATUS_PROCESSING, $run->getStatus());
        $this->assertNotNull($run->getStartedAt());
    }

    public function testDoesNotLookupOrBindRunWhenContextIsEmpty(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $conn = $this->createMock(Connection::class);
        $em->method('getConnection')->willReturn($conn);

        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->method('findByExternalId')->willReturn(null);

        $runRepo = $this->createMock(ImportRunRepository::class);
        // Neither find nor findLatestPendingOrProcessing nor save must be called
        $runRepo->expects($this->never())->method('find');
        $runRepo->expects($this->never())->method('findLatestPendingOrProcessing');
        $runRepo->expects($this->never())->method('save');

        $context = new ImportRunContext(); // null runId

        $handler = new CatalogImportHandler($em, $productRepo, $runRepo, $context);
        // Execution succeeds cleanly without touching import_runs
        $handler->__invoke(new ImportCatalogCommand($this->fixturePath));
    }

    public function testGracefullyHandlesWhenContextRunIdNotFoundInRepository(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $conn = $this->createMock(Connection::class);
        $em->method('getConnection')->willReturn($conn);

        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->method('findByExternalId')->willReturn(null);

        $runRepo = $this->createMock(ImportRunRepository::class);
        $runRepo->expects($this->once())
            ->method('find')
            ->with(99999)
            ->willReturn(null);

        // Fallback must NOT occur, no save
        $runRepo->expects($this->never())->method('findLatestPendingOrProcessing');
        $runRepo->expects($this->never())->method('save');

        $context = new ImportRunContext();
        $context->setCurrentRunId(99999);

        $handler = new CatalogImportHandler($em, $productRepo, $runRepo, $context);
        $handler->__invoke(new ImportCatalogCommand($this->fixturePath));
    }
}
