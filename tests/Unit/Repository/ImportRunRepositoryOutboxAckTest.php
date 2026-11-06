<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repository;

use App\Entity\ImportRun;
use App\Repository\ImportRunRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;

final class ImportRunRepositoryOutboxAckTest extends TestCase
{
    private function createRepositoryWithMockConnection(): array
    {
        $connection = $this->createMock(Connection::class);
        $metadata = new ClassMetadata(ImportRun::class);
        $metadata->name = ImportRun::class;

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $em->method('getClassMetadata')->with(ImportRun::class)->willReturn($metadata);

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->with(ImportRun::class)->willReturn($em);

        $repository = new ImportRunRepository($registry);

        return [$repository, $connection];
    }

    public function testMarkOutboxAckedByIdExecutesStrictPrimaryKeyUpdate(): void
    {
        /** @var ImportRunRepository $repository */
        /** @var \PHPUnit\Framework\MockObject\MockObject&Connection $connection */
        [$repository, $connection] = $this->createRepositoryWithMockConnection();

        $outboxId = 77;

        $connection->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->callback(function (string $sql): bool {
                    return str_contains($sql, 'UPDATE import_run_outbox')
                        && str_contains($sql, 'SET acked_at = NOW()')
                        && str_contains($sql, 'WHERE id = :id')
                        && str_contains($sql, 'published_at IS NOT NULL')
                        && str_contains($sql, 'acked_at IS NULL');
                }),
                $this->equalTo(['id' => $outboxId])
            );

        $repository->markOutboxAckedById($outboxId);
    }

    public function testLegacyFallbackMarkOutboxAckedExecutesTargetedUpdateWithWorkerAttempt(): void
    {
        /** @var ImportRunRepository $repository */
        /** @var \PHPUnit\Framework\MockObject\MockObject&Connection $connection */
        [$repository, $connection] = $this->createRepositoryWithMockConnection();

        $runId = 3;
        $workerAttempt = 1; // Defer was created with attempt 0, worker has attempt 1

        $connection->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->callback(function (string $sql): bool {
                    return str_contains($sql, 'UPDATE import_run_outbox')
                        && str_contains($sql, 'SET acked_at = NOW()')
                        && str_contains($sql, 'run_id = :runId')
                        && str_contains($sql, 'published_at IS NOT NULL')
                        && str_contains($sql, 'acked_at IS NULL')
                        && str_contains($sql, "kind = 'defer'")
                        && str_contains($sql, 'attempt = :attempt - 1');
                }),
                $this->equalTo(['runId' => $runId, 'attempt' => $workerAttempt])
            );

        $repository->markOutboxAcked($runId, $workerAttempt);
    }

    public function testLegacyFallbackSubsequentAttemptsDeferAck(): void
    {
        /** @var ImportRunRepository $repository */
        /** @var \PHPUnit\Framework\MockObject\MockObject&Connection $connection */
        [$repository, $connection] = $this->createRepositoryWithMockConnection();

        $runId = 15;
        $workerAttempt = 3;

        $connection->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->anything(),
                $this->equalTo(['runId' => $runId, 'attempt' => $workerAttempt])
            );

        $repository->markOutboxAcked($runId, $workerAttempt);
    }

    public function testSqlGuardsAgainstUnpublishedAndAlreadyAckedEntries(): void
    {
        /** @var ImportRunRepository $repository */
        /** @var \PHPUnit\Framework\MockObject\MockObject&Connection $connection */
        [$repository, $connection] = $this->createRepositoryWithMockConnection();

        $connection->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->callback(function (string $sql): bool {
                    return str_contains($sql, 'published_at IS NOT NULL')
                        && str_contains($sql, 'acked_at IS NULL');
                }),
                $this->equalTo(['id' => 999])
            );

        $repository->markOutboxAckedById(999);
    }
}
