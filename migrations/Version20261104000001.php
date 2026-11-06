<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration adding attempt, fencing_token and retry_scheduled_at to import_runs.
 */
final class Version20261104000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add attempt, fencing_token and retry_scheduled_at to import_runs table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE import_runs ADD attempt INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE import_runs ADD fencing_token UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE import_runs ADD retry_scheduled_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_import_runs_retry_scheduled_at ON import_runs (retry_scheduled_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_import_runs_retry_scheduled_at');
        $this->addSql('ALTER TABLE import_runs DROP retry_scheduled_at');
        $this->addSql('ALTER TABLE import_runs DROP fencing_token');
        $this->addSql('ALTER TABLE import_runs DROP attempt');
    }
}
