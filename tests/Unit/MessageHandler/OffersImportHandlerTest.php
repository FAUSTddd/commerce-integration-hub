<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Entity\Product;
use App\Entity\ProductVariant;
use App\MessageHandler\OffersImportHandler;
use App\Repository\ProductRepository;
use App\Repository\ProductVariantRepository;
use Doctrine\ORM\EntityManagerInterface;
use FaustDDD\Symfony1cImport\Application\Command\ImportOffersCommand;
use PHPUnit\Framework\TestCase;

final class OffersImportHandlerTest extends TestCase
{
    public function testImportsOffersUpdatesPricesAndSkipsUnknownProducts(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->atLeastOnce())->method('flush');

        $prod1 = new Product('prod-1001', 'Кофемашина', 'ART-1001');
        $prod2 = new Product('prod-1002', 'Чайник', 'ART-1002');
        $products = [
            'prod-1001' => $prod1,
            'prod-1002' => $prod2,
        ];

        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->method('findByExternalId')->willReturnCallback(function (string $extId) use ($products) {
            return $products[$extId] ?? null;
        });

        $variantStorage = [];
        $variantRepo = $this->createMock(ProductVariantRepository::class);
        $variantRepo->method('findByExternalId')->willReturnCallback(function (string $extId) use (&$variantStorage) {
            return $variantStorage[$extId] ?? null;
        });
        $variantRepo->method('save')->willReturnCallback(function (ProductVariant $variant) use (&$variantStorage) {
            $variantStorage[$variant->getExternalId()] = $variant;
        });

        $handler = new OffersImportHandler($em, $productRepo, $variantRepo);

        $fixturePath = dirname(__DIR__, 2) . '/Fixtures/import_offers.xml';
        $command = new ImportOffersCommand($fixturePath);

        // 1st run
        $handler->__invoke($command);

        // non-existing-prod-999 is skipped, so only 2 variants created
        $this->assertCount(2, $variantStorage);

        $var1 = $variantStorage['prod-1001#var-1'];
        $this->assertSame(3599050, $var1->getPriceAmount()); // 35990.50 RUB in cents
        $this->assertSame('15.000', $var1->getStock());
        $this->assertSame('RUB', $var1->getCurrency());
        $this->assertSame($prod1, $var1->getProduct());

        $var2 = $variantStorage['prod-1002'];
        $this->assertSame(499900, $var2->getPriceAmount()); // 4999.00 RUB in cents
        $this->assertSame('42.500', $var2->getStock());

        // 2nd run with updated prices/stock
        $updatedFixturePath = dirname(__DIR__, 2) . '/Fixtures/import_offers_updated.xml';
        $updatedCommand = new ImportOffersCommand($updatedFixturePath);

        $handler->__invoke($updatedCommand);

        // Count should stay 2 (idempotent, no duplicates)
        $this->assertCount(2, $variantStorage);
        $this->assertSame(3299000, $var1->getPriceAmount()); // updated to 32990.00
        $this->assertSame('8.000', $var1->getStock());        // updated to 8.000
        $this->assertSame(450000, $var2->getPriceAmount());  // updated to 4500.00
        $this->assertSame('50.000', $var2->getStock());       // updated to 50.000
    }
}
