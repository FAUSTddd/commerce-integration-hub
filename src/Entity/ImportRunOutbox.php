<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'import_run_outbox')]
#[ORM\UniqueConstraint(name: 'uniq_outbox_idempotency_key', columns: ['idempotency_key'])]
#[ORM\Index(name: 'idx_outbox_unpublished', columns: ['available_at'])]
class ImportRunOutbox
{
    public const KIND_RETRY = 'retry';
    public const KIND_DEFER = 'defer';
    public const KIND_RECOVERY = 'recovery';

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(name: 'id', type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(name: 'run_id', type: Types::BIGINT, nullable: false)]
    private int $runId;

    #[ORM\Column(name: 'attempt', type: Types::INTEGER, nullable: false)]
    private int $attempt;

    #[ORM\Column(name: 'kind', type: Types::STRING, length: 16, nullable: false, options: ['default' => 'retry'])]
    private string $kind = self::KIND_RETRY;

    #[ORM\Column(name: 'idempotency_key', type: Types::STRING, length: 128, nullable: false)]
    private string $idempotencyKey;

    #[ORM\Column(name: 'queue_name', type: Types::STRING, length: 64, nullable: false)]
    private string $queueName;

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(name: 'headers', type: Types::JSON, nullable: false)]
    private array $headers = [];

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(name: 'payload', type: Types::JSON, nullable: false)]
    private array $payload = [];

    #[ORM\Column(name: 'available_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: false)]
    private DateTimeImmutable $availableAt;

    #[ORM\Column(name: 'published_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $publishedAt = null;

    #[ORM\Column(name: 'acked_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $ackedAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: false)]
    private DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed> $headers
     * @param array<string, mixed> $payload
     */
    public function __construct(
        int $runId,
        int $attempt,
        string $kind,
        string $idempotencyKey,
        string $queueName,
        array $headers,
        array $payload,
        DateTimeImmutable $availableAt
    ) {
        $this->runId = $runId;
        $this->attempt = $attempt;
        $this->kind = $kind;
        $this->idempotencyKey = $idempotencyKey;
        $this->queueName = $queueName;
        $this->headers = $headers;
        $this->payload = $payload;
        $this->availableAt = $availableAt;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRunId(): int
    {
        return $this->runId;
    }

    public function getAttempt(): int
    {
        return $this->attempt;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getIdempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    public function getQueueName(): string
    {
        return $this->queueName;
    }

    /**
     * @return array<string, mixed>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getAvailableAt(): DateTimeImmutable
    {
        return $this->availableAt;
    }

    public function getPublishedAt(): ?DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function setPublishedAt(?DateTimeImmutable $publishedAt): self
    {
        $this->publishedAt = $publishedAt;
        return $this;
    }

    public function getAckedAt(): ?DateTimeImmutable
    {
        return $this->ackedAt;
    }

    public function setAckedAt(?DateTimeImmutable $ackedAt): self
    {
        $this->ackedAt = $ackedAt;
        return $this;
    }
}
