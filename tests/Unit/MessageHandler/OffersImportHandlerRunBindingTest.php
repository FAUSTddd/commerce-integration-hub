<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Entity\ImportRun;
use App\Entity\Product;
use App\MessageHandler\OffersImportHandler;
use App\Messenger\ImportRunContext;
use App\Repository\ImportRunRepository;
use App\Repository\ProductRepository;
use App\Repository\ProductVariantRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use FaustDDD\Symfony1cImport\Application\Command\ImportOffersCommand;
use PHPUnit\Framework\TestCase;

final class OffersImportHandlerRunBindingTest extends TestCase
{
    private string $fixturePath;

    protected function setUp(): void
    {
        $this->fixturePath = dirname(__DIR__, 2) . '/Fixtures/import_offers.xml';
    }

    public function testPrefersImportRunContextExactId(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $conn = $this->createMock(Connection::class);
        $em->method('getConnection')->willReturn($conn);

        $productRepo = $this->createMock(ProductRepository::class);
        $product = new Product('prod-1001', 'Test Product');
        $productRepo->method('findByExternalId')->willReturn($product);

        $variantRepo = $this->createMock(ProductVariantRepository::class);

        $run = new ImportRun('offers', 'import_offers.xml', 'test_hash');
        $ref = new \ReflectionProperty(ImportRun::class, 'id');
        $ref->setValue($run, 777);

        $runRepo = $this->createMock(ImportRunRepository::class);
        $runRepo->expects($this->once())
            ->method('find')
            ->with(777)
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
        $context->setCurrentRunId(777);

        $handler = new OffersImportHandler($em, $productRepo, $variantRepo, $runRepo, null, $context);
        $handler->__invoke(new ImportOffersCommand($this->fixturePath));

        $this->assertSame(ImportRun::STATUS_PROCESSING, $run->getStatus());
        $this->assertNotNull($run->getStartedAt());
    }

    public function testDoesNotLookupOrBindRunWhenContextIsEmpty(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $conn = $this->createMock(Connection::class);
        $em->method('getConnection')->willReturn($conn);

        $productRepo = $this->createMock(ProductRepository::class);
        $product = new Product('prod-1001', 'Test Product');
        $productRepo->method('findByExternalId')->willReturn($product);

        $variantRepo = $this->createMock(ProductVariantRepository::class);

        $runRepo = $this->createMock(ImportRunRepository::class);
        // Neither find nor findLatestPendingOrProcessing nor save must be called
        $runRepo->expects($this->never())->method('find');
        $runRepo->expects($this->never())->method('findLatestPendingOrProcessing');
        $runRepo->expects($this->never())->method('save');

        $context = new ImportRunContext(); // null runId

        $handler = new OffersImportHandler($em, $productRepo, $variantRepo, $runRepo, null, $context);
        // Execution succeeds cleanly without touching import_runs
        $handler->__invoke(new ImportOffersCommand($this->fixturePath));
    }

    public function testGracefullyHandlesWhenContextRunIdNotFoundInRepository(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $conn = $this->createMock(Connection::class);
        $em->method('getConnection')->willReturn($conn);

        $productRepo = $this->createMock(ProductRepository::class);
        $product = new Product('prod-1001', 'Test Product');
        $productRepo->method('findByExternalId')->willReturn($product);

        $variantRepo = $this->createMock(ProductVariantRepository::class);

        $runRepo = $this->createMock(ImportRunRepository::class);
        $runRepo->expects($this->once())
            ->method('find')
            ->with(66666)
            ->willReturn(null);

        // Fallback must NOT occur, no save
        $runRepo->expects($this->never())->method('findLatestPendingOrProcessing');
        $runRepo->expects($this->never())->method('save');

        $context = new ImportRunContext();
        $context->setCurrentRunId(66666);

        $handler = new OffersImportHandler($em, $productRepo, $variantRepo, $runRepo, null, $context);
        $handler->__invoke(new ImportOffersCommand($this->fixturePath));
    }
}
