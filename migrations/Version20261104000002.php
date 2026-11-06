<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration adding import_run_outbox table and has_deferred_retry column to import_runs.
 */
final class Version20261104000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add import_run_outbox table with kind, idempotency_key, acked_at and has_deferred_retry to import_runs';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE import_runs ADD COLUMN IF NOT EXISTS has_deferred_retry BOOLEAN DEFAULT FALSE NOT NULL');

        $this->addSql(<<<SQL
            CREATE TABLE IF NOT EXISTS import_run_outbox (
                id BIGSERIAL PRIMARY KEY,
                run_id BIGINT NOT NULL REFERENCES import_runs(id) ON DELETE CASCADE,
                attempt INT NOT NULL,
                kind VARCHAR(16) NOT NULL DEFAULT 'retry',
                idempotency_key VARCHAR(128) NOT NULL,
                queue_name VARCHAR(64) NOT NULL,
                headers JSONB NOT NULL,
                payload JSONB NOT NULL,
                available_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                published_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                acked_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL DEFAULT NOW(),
                CONSTRAINT uniq_outbox_idempotency_key UNIQUE (idempotency_key)
            )
        SQL);

        $this->addSql('CREATE INDEX IF NOT EXISTS idx_outbox_unpublished ON import_run_outbox (available_at) WHERE published_at IS NULL');
        $this->addSql("CREATE UNIQUE INDEX IF NOT EXISTS uniq_outbox_active_defer ON import_run_outbox (run_id) WHERE kind = 'defer' AND published_at IS NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS import_run_outbox');
        $this->addSql('ALTER TABLE import_runs DROP COLUMN IF EXISTS has_deferred_retry');
    }
}
