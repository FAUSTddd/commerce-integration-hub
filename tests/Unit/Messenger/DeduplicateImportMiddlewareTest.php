<?php

declare(strict_types=1);

namespace App\Tests\Unit\Messenger;

use App\Entity\ImportRun;
use App\Messenger\DeduplicateImportMiddleware;
use App\Messenger\DuplicateImportStamp;
use App\Messenger\ImportRunStamp;
use App\Repository\ImportRunRepository;
use FaustDDD\Symfony1cImport\Application\Command\ImportCatalogCommand;
use FaustDDD\Symfony1cImport\Application\Command\ImportOffersCommand;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

final class DeduplicateImportMiddlewareTest extends TestCase
{
    private ImportRunRepository $repository;
    private DeduplicateImportMiddleware $middleware;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(ImportRunRepository::class);
        $this->middleware = new DeduplicateImportMiddleware(
            $this->repository,
            new NullLogger()
        );
    }

    public function testNonCmlCommandBypassesDeduplication(): void
    {
        $envelope = new Envelope(new stdClass());
        $stack = $this->createStackExpectsHandle($envelope);

        $this->repository->expects($this->never())->method('tryCreateActiveRun');

        $result = $this->middleware->handle($envelope, $stack);
        $this->assertSame($envelope, $result);
    }

    public function testExistingImportRunStampBypassesDeduplication(): void
    {
        $command = new ImportCatalogCommand('/path/to/import.xml');
        $envelope = (new Envelope($command))->with(new ImportRunStamp(42));
        $stack = $this->createStackExpectsHandle($envelope);

        $this->repository->expects($this->never())->method('tryCreateActiveRun');

        $result = $this->middleware->handle($envelope, $stack);
        $this->assertSame($envelope, $result);
    }

    public function testFirstDispatchCreatesPendingRunAndProceedsDownStack(): void
    {
        $command = new ImportCatalogCommand('/tmp/test_import.xml');
        $envelope = new Envelope($command);

        $this->repository->expects($this->once())
            ->method('tryCreateActiveRun')
            ->with('catalog', 'test_import.xml', $this->isType('string'))
            ->willReturn(101);

        $nextMiddleware = $this->createMock(MiddlewareInterface::class);
        $nextMiddleware->expects($this->once())
            ->method('handle')
            ->with($this->callback(function (Envelope $env): bool {
                $stamp = $env->last(ImportRunStamp::class);
                return $stamp instanceof ImportRunStamp && $stamp->getImportRunId() === 101;
            }))
            ->willReturnArgument(0);

        $stack = $this->createMock(StackInterface::class);
        $stack->expects($this->once())->method('next')->willReturn($nextMiddleware);

        $result = $this->middleware->handle($envelope, $stack);

        $stamp = $result->last(ImportRunStamp::class);
        $this->assertNotNull($stamp);
        $this->assertSame(101, $stamp->getImportRunId());
        $this->assertNull($result->last(DuplicateImportStamp::class));
    }

    public function testDuplicateDispatchShortCircuitsWithDuplicateImportStamp(): void
    {
        $command = new ImportOffersCommand('/tmp/offers.xml');
        $envelope = new Envelope($command);

        $this->repository->expects($this->once())
            ->method('tryCreateActiveRun')
            ->with('offers', 'offers.xml', $this->isType('string'))
            ->willReturn(null);

        $existingRun = $this->createMock(ImportRun::class);
        $existingRun->method('getId')->willReturn(202);

        $this->repository->expects($this->once())
            ->method('findActiveRun')
            ->with('offers', 'offers.xml')
            ->willReturn($existingRun);

        // Next middleware must NEVER be called on short-circuit
        $stack = $this->createMock(StackInterface::class);
        $stack->expects($this->never())->method('next');

        $result = $this->middleware->handle($envelope, $stack);

        $stamp = $result->last(DuplicateImportStamp::class);
        $this->assertInstanceOf(DuplicateImportStamp::class, $stamp);
        $this->assertSame('offers', $stamp->type);
        $this->assertSame('offers.xml', $stamp->filename);
        $this->assertSame(202, $stamp->existingRunId);
        $this->assertNull($result->last(ImportRunStamp::class));
    }

    public function testDispatchFailureCompensatesToFailedWhenNoMessageInTransport(): void
    {
        $command = new ImportCatalogCommand('/tmp/catalog_err.xml');
        $envelope = new Envelope($command);

        $this->repository->expects($this->once())
            ->method('tryCreateActiveRun')
            ->willReturn(303);

        $nextMiddleware = $this->createMock(MiddlewareInterface::class);
        $nextMiddleware->expects($this->once())
            ->method('handle')
            ->willThrowException(new RuntimeException('Transport error on enqueue'));

        $stack = $this->createMock(StackInterface::class);
        $stack->expects($this->once())->method('next')->willReturn($nextMiddleware);

        $this->repository->expects($this->once())
            ->method('hasMessageForRunId')
            ->with(303)
            ->willReturn(false);

        $this->repository->expects($this->once())
            ->method('compensateFailedEnqueue')
            ->with(303, $this->stringContains('Transport error on enqueue'))
            ->willReturn(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Transport error on enqueue');

        $this->middleware->handle($envelope, $stack);
    }

    public function testDispatchFailurePreservesPendingWhenMessageAlreadyInTransport(): void
    {
        $command = new ImportOffersCommand('/tmp/offers_err.xml');
        $envelope = new Envelope($command);

        $this->repository->expects($this->once())
            ->method('tryCreateActiveRun')
            ->willReturn(404);

        $nextMiddleware = $this->createMock(MiddlewareInterface::class);
        $nextMiddleware->expects($this->once())
            ->method('handle')
            ->willThrowException(new RuntimeException('Post-enqueue listener failed'));

        $stack = $this->createMock(StackInterface::class);
        $stack->expects($this->once())->method('next')->willReturn($nextMiddleware);

        $this->repository->expects($this->once())
            ->method('hasMessageForRunId')
            ->with(404)
            ->willReturn(true);

        // Crucial: compensateFailedEnqueue must NEVER be called if message exists
        $this->repository->expects($this->never())
            ->method('compensateFailedEnqueue');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Post-enqueue listener failed');

        $this->middleware->handle($envelope, $stack);
    }

    private function createStackExpectsHandle(Envelope $expectedEnvelope): StackInterface
    {
        $nextMiddleware = $this->createMock(MiddlewareInterface::class);
        $nextMiddleware->expects($this->once())
            ->method('handle')
            ->with($expectedEnvelope)
            ->willReturn($expectedEnvelope);

        $stack = $this->createMock(StackInterface::class);
        $stack->expects($this->once())
            ->method('next')
            ->willReturn($nextMiddleware);

        return $stack;
    }
}
