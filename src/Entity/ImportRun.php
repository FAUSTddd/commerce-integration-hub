<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ImportRunRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ImportRunRepository::class)]
#[ORM\Table(name: 'import_runs')]
#[ORM\Index(name: 'idx_import_runs_status', columns: ['status'])]
#[ORM\Index(name: 'idx_import_runs_created_at', columns: ['created_at'])]
#[ORM\Index(name: 'idx_import_runs_retry_scheduled_at', columns: ['retry_scheduled_at'])]
class ImportRun
{
    public const string STATUS_PENDING = 'PENDING';
    public const string STATUS_PROCESSING = 'PROCESSING';
    public const string STATUS_SUCCESS = 'SUCCESS';
    public const string STATUS_FAILED = 'FAILED';

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 32, nullable: false)]
    private string $type; // 'catalog' | 'offers'

    #[ORM\Column(type: Types::STRING, length: 255, nullable: false)]
    private string $filename;

    #[ORM\Column(name: 'file_hash', type: Types::STRING, length: 64, nullable: false)]
    private string $fileHash;

    #[ORM\Column(type: Types::STRING, length: 32, nullable: false, options: ['default' => self::STATUS_PENDING])]
    private string $status;

    #[ORM\Column(name: 'items_count', type: Types::INTEGER, nullable: false, options: ['default' => 0])]
    private int $itemsCount = 0;

    #[ORM\Column(name: 'error_message', type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: false)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'started_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $startedAt = null;

    #[ORM\Column(name: 'finished_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $finishedAt = null;

    #[ORM\Column(name: 'attempt', type: Types::INTEGER, nullable: false, options: ['default' => 0])]
    private int $attempt = 0;

    #[ORM\Column(name: 'fencing_token', type: Types::GUID, nullable: true)]
    private ?string $fencingToken = null;

    #[ORM\Column(name: 'retry_scheduled_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $retryScheduledAt = null;

    #[ORM\Column(name: 'has_deferred_retry', type: Types::BOOLEAN, nullable: false, options: ['default' => false])]
    private bool $hasDeferredRetry = false;

    public function __construct(string $type, string $filename, string $fileHash)
    {
        $this->type = $type;
        $this->filename = $filename;
        $this->fileHash = $fileHash;
        $this->status = self::STATUS_PENDING;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function getFileHash(): string
    {
        return $this->fileHash;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getItemsCount(): int
    {
        return $this->itemsCount;
    }

    public function setItemsCount(int $itemsCount): self
    {
        $this->itemsCount = $itemsCount;
        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): self
    {
        $this->errorMessage = $errorMessage;
        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getStartedAt(): ?DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(?DateTimeImmutable $startedAt): self
    {
        $this->startedAt = $startedAt;
        return $this;
    }

    public function getFinishedAt(): ?DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function setFinishedAt(?DateTimeImmutable $finishedAt): self
    {
        $this->finishedAt = $finishedAt;
        return $this;
    }

    public function getAttempt(): int
    {
        return $this->attempt;
    }

    public function setAttempt(int $attempt): self
    {
        $this->attempt = $attempt;
        return $this;
    }

    public function getFencingToken(): ?string
    {
        return $this->fencingToken;
    }

    public function setFencingToken(?string $fencingToken): self
    {
        $this->fencingToken = $fencingToken;
        return $this;
    }

    public function getRetryScheduledAt(): ?DateTimeImmutable
    {
        return $this->retryScheduledAt;
    }

    public function setRetryScheduledAt(?DateTimeImmutable $retryScheduledAt): self
    {
        $this->retryScheduledAt = $retryScheduledAt;
        return $this;
    }

    public function isHasDeferredRetry(): bool
    {
        return $this->hasDeferredRetry;
    }

    public function setHasDeferredRetry(bool $hasDeferredRetry): self
    {
        $this->hasDeferredRetry = $hasDeferredRetry;
        return $this;
    }
}
