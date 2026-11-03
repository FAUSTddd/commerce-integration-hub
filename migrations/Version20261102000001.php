<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\AbortMigration;

/**
 * Migration creating partial unique index for ImportRun active runs deduplication.
 *
 * Guardrails:
 * 1. Acquires SHARE ROW EXCLUSIVE table locks on import_runs and messenger_messages
 *    at the very beginning to block concurrent writes/inserts while allowing concurrent reads.
 * 2. Aborts immediately if any run is in PROCESSING status (active import running).
 * 3. Diagnoses all PENDING runs:
 *    - Aborts if duplicate PENDING runs exist for the same (type, filename).
 *    - Validates queue messages: aborts on corrupt/unparseable CML messages.
 *    - Verifies matching queue messages for each PENDING run.
 *    - Aborts if multiple queue messages match the same runId.
 *    - Aborts if a message is currently in-flight/locked (delivered_at IS NOT NULL).
 *    - Safely marks strictly orphaned PENDING runs (proven absence in transport) as FAILED immediately via executeStatement().
 * 4. Creates partial unique index:
 *    CREATE UNIQUE INDEX uniq_import_runs_active_type_filename
 *    ON import_runs (type, filename)
 *    WHERE status IN ('PENDING', 'PROCESSING');
 */
final class Version20261102000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Safely resolve active import runs and create partial unique index uniq_import_runs_active_type_filename';
    }

    public function up(Schema $schema): void
    {
        // 1. Concurrency guardrail: acquire SHARE ROW EXCLUSIVE locks prior to any diagnostics.
        // Blocks concurrent inserts/updates/deletes on both tables within this migration transaction.
        $this->connection->executeStatement('LOCK TABLE import_runs IN SHARE ROW EXCLUSIVE MODE');
        $this->connection->executeStatement('LOCK TABLE messenger_messages IN SHARE ROW EXCLUSIVE MODE');

        // 2. Guardrail: abort if any worker is actively PROCESSING an import
        $processingCount = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM import_runs WHERE status = 'PROCESSING'"
        );
        if ($processingCount > 0) {
            throw new AbortMigration(sprintf(
                'Migration aborted: detected %d import_runs in PROCESSING status. Wait for active workers to finish or inspect manually.',
                $processingCount
            ));
        }

        // 3. Guardrail: abort if duplicate PENDING runs exist for the same (type, filename)
        $duplicatePending = $this->connection->fetchAllAssociative(
            "SELECT type, filename, COUNT(*) as cnt, array_agg(id) as ids
             FROM import_runs
             WHERE status = 'PENDING'
             GROUP BY type, filename
             HAVING COUNT(*) > 1"
        );
        if (!empty($duplicatePending)) {
            $details = json_encode($duplicatePending, JSON_THROW_ON_ERROR);
            throw new AbortMigration(sprintf(
                'Migration aborted: ambiguous duplicate PENDING runs detected for (type, filename): %s. Manual operator resolution required.',
                $details
            ));
        }

        // 4. Inspect existing PENDING runs against messenger_messages queue
        $pendingRuns = $this->connection->fetchAllAssociative(
            "SELECT id, type, filename, created_at FROM import_runs WHERE status = 'PENDING' ORDER BY id ASC"
        );

        if (!empty($pendingRuns)) {
            $queueMessages = $this->connection->fetchAllAssociative(
                'SELECT id, queue_name, delivered_at, body FROM messenger_messages'
            );

            // Pre-scan queue messages: if any message looks like a CML import or contains ImportRunStamp,
            // verify that its runId can be unambiguously resolved.
            // If it cannot be unambiguously extracted, abort to prevent false orphan classification.
            $parsedQueue = [];
            foreach ($queueMessages as $msg) {
                $body = (string) $msg['body'];
                $isCmlRelated = $this->isCmlImportMessage($body);
                $extractedRunId = $this->extractImportRunIdFromBody($body);

                if ($isCmlRelated && $extractedRunId === null) {
                    throw new AbortMigration(sprintf(
                        'Migration aborted: queue message ID %s contains CML import markers but ImportRunStamp cannot be unambiguously parsed. Body preview: %s',
                        (string) $msg['id'],
                        substr($body, 0, 200)
                    ));
                }

                $parsedQueue[] = [
                    'id' => $msg['id'],
                    'delivered_at' => $msg['delivered_at'],
                    'run_id' => $extractedRunId,
                ];
            }

            foreach ($pendingRuns as $pendingRun) {
                $runId = (int) $pendingRun['id'];
                $matchingMessages = [];
                $hasInFlightMessage = false;

                foreach ($parsedQueue as $parsed) {
                    if ($parsed['run_id'] === $runId) {
                        $matchingMessages[] = $parsed['id'];
                        if ($parsed['delivered_at'] !== null) {
                            $hasInFlightMessage = true;
                        }
                    }
                }

                // Guardrail: in-flight delivery detected
                if ($hasInFlightMessage) {
                    throw new AbortMigration(sprintf(
                        'Migration aborted: PENDING run %d matches message in transport currently being delivered (delivered_at is set). Wait for completion.',
                        $runId
                    ));
                }

                // Guardrail: multiple queue messages for single run
                if (count($matchingMessages) > 1) {
                    throw new AbortMigration(sprintf(
                        'Migration aborted: ambiguous state - multiple queue messages (%s) reference PENDING run %d.',
                        implode(', ', $matchingMessages),
                        $runId
                    ));
                }

                // Strictly proven orphaned PENDING run: no queue message exists anywhere in transport
                // Execute immediately within transaction before creating index
                if (empty($matchingMessages)) {
                    $this->connection->executeStatement(
                        "UPDATE import_runs
                         SET status = 'FAILED',
                             error_message = 'Marked as FAILED by migration Version20261102000001: orphaned PENDING run without transport message',
                             finished_at = NOW()
                         WHERE id = :id AND status = 'PENDING'",
                        ['id' => $runId]
                    );
                }
                // If exactly 1 matching message exists, the PENDING run is valid and remains PENDING
            }
        }

        // 5. Create partial unique index
        $this->addSql(
            "CREATE UNIQUE INDEX uniq_import_runs_active_type_filename
             ON import_runs (type, filename)
             WHERE status IN ('PENDING', 'PROCESSING')"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS uniq_import_runs_active_type_filename');
    }

    private function isCmlImportMessage(string $body): bool
    {
        return str_contains($body, 'ImportCatalogCommand')
            || str_contains($body, 'ImportOffersCommand')
            || str_contains($body, 'ImportRunStamp');
    }

    private function extractImportRunIdFromBody(string $body): ?int
    {
        // PHP serialize format (e.g. O:31:"App\Messenger\ImportRunStamp":1:{s:11:"importRunId";i:42;})
        if (preg_match('/importRunId[^\w]*;i:(\d+);/', $body, $matches) === 1) {
            return (int) $matches[1];
        }

        // JSON format (e.g. {"importRunId":42})
        if (preg_match('/"importRunId"\s*:\s*(\d+)/', $body, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }
}
