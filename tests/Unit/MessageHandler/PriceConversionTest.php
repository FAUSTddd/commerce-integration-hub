<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Entity\Product;
use App\Entity\ProductVariant;
use App\MessageHandler\OffersImportHandler;
use App\Repository\ProductRepository;
use App\Repository\ProductVariantRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SimpleXMLElement;

final class PriceConversionTest extends TestCase
{
    private OffersImportHandler $handler;

    protected function setUp(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $productRepo = $this->createMock(ProductRepository::class);
        $variantRepo = $this->createMock(ProductVariantRepository::class);
        $this->handler = new OffersImportHandler($em, $productRepo, $variantRepo);
    }

    private function parsePrice(string $rawPrice): int
    {
        $reflection = new ReflectionMethod(OffersImportHandler::class, 'parsePriceToCents');
        return $reflection->invoke($this->handler, $rawPrice);
    }

    public function testStandardPrices(): void
    {
        $this->assertSame(10000, $this->parsePrice('100.00'));
        $this->assertSame(499900, $this->parsePrice('4999.00'));
        $this->assertSame(3599050, $this->parsePrice('35990.50'));
        $this->assertSame(150, $this->parsePrice('1.5'));
        $this->assertSame(100, $this->parsePrice('1'));
        $this->assertSame(0, $this->parsePrice('0.00'));
        $this->assertSame(0, $this->parsePrice('invalid'));
    }

    public function testBoundaryPricesRoundingHalfUp(): void
    {
        // 12.349 -> third digit 9 >= 5, rounds to 12.35 -> 1235 kopecks
        $this->assertSame(1235, $this->parsePrice('12.349'));

        // 100.999 -> third digit 9 >= 5, rounds to 101.00 -> 10100 kopecks
        $this->assertSame(10100, $this->parsePrice('100.999'));

        // 12.344 -> third digit 4 < 5, rounds down to 12.34 -> 1234 kopecks
        $this->assertSame(1234, $this->parsePrice('12.344'));

        // 12.345 -> third digit 5 >= 5, rounds up to 12.35 -> 1235 kopecks
        $this->assertSame(1235, $this->parsePrice('12.345'));
    }

    public function testNegativePrices(): void
    {
        $this->assertSame(-1050, $this->parsePrice('-10.50'));
        $this->assertSame(-1235, $this->parsePrice('-12.349'));
        $this->assertSame(-10000, $this->parsePrice('-100.00'));
    }

    public function testCommaDecimalSeparator(): void
    {
        $this->assertSame(1235, $this->parsePrice('12,349'));
        $this->assertSame(499950, $this->parsePrice('4999,50'));
    }
}
