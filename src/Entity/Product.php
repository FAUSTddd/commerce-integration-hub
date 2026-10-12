<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProductRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProductRepository::class)]
#[ORM\Table(name: 'products')]
#[ORM\Index(name: 'idx_products_sku', columns: ['sku'])]
class Product
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(name: 'external_id', type: Types::STRING, length: 64, unique: true, nullable: false)]
    private string $externalId;

    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $sku = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: false)]
    private string $name;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: false)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: false)]
    private DateTimeImmutable $updatedAt;

    /**
     * @var Collection<int, ProductVariant>
     */
    #[ORM\OneToMany(targetEntity: ProductVariant::class, mappedBy: 'product', cascade: ['persist'])]
    private Collection $variants;

    public function __construct(string $externalId, string $name, ?string $sku = null)
    {
        $this->externalId = $externalId;
        $this->name = $name;
        $this->sku = $sku;
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
        $this->variants = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getExternalId(): string
    {
        return $this->externalId;
    }

    public function setExternalId(string $externalId): self
    {
        $this->externalId = $externalId;
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function getSku(): ?string
    {
        return $this->sku;
    }

    public function setSku(?string $sku): self
    {
        $this->sku = $sku;
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * @return Collection<int, ProductVariant>
     */
    public function getVariants(): Collection
    {
        return $this->variants;
    }
}
