<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProductVariantRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProductVariantRepository::class)]
#[ORM\Table(name: 'product_variants')]
#[ORM\Index(name: 'idx_product_variants_product_id', columns: ['product_id'])]
class ProductVariant
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'variants')]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Product $product;

    #[ORM\Column(name: 'external_id', type: Types::STRING, length: 128, unique: true, nullable: false)]
    private string $externalId;

    /**
     * Цена за единицу в минорных единицах (копейки). Исключает использование float.
     */
    #[ORM\Column(name: 'price_amount', type: Types::BIGINT, nullable: false, options: ['default' => 0])]
    private int $priceAmount = 0;

    #[ORM\Column(type: Types::STRING, length: 3, nullable: false, options: ['default' => 'RUB'])]
    private string $currency = 'RUB';

    /**
     * Количественный остаток со строгой точностью (NUMERIC 14, 3).
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 3, nullable: false, options: ['default' => '0.000'])]
    private string $stock = '0.000';

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: false)]
    private DateTimeImmutable $updatedAt;

    public function __construct(Product $product, string $externalId)
    {
        $this->product = $product;
        $this->externalId = $externalId;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProduct(): Product
    {
        return $this->product;
    }

    public function setProduct(Product $product): self
    {
        $this->product = $product;
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function getExternalId(): string
    {
        return $this->externalId;
    }

    public function getPriceAmount(): int
    {
        return $this->priceAmount;
    }

    public function setPriceAmount(int $priceAmount): self
    {
        $this->priceAmount = $priceAmount;
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): self
    {
        $this->currency = $currency;
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function getStock(): string
    {
        return $this->stock;
    }

    public function setStock(string $stock): self
    {
        $this->stock = $stock;
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
