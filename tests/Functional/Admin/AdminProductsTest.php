<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AdminProductsTest extends WebTestCase
{
    /**
     * @return array<string, string>
     */
    private function authHeaders(): array
    {
        return [
            'PHP_AUTH_USER' => 'admin',
            'PHP_AUTH_PW' => 'test_admin_pass',
        ];
    }

    public function testUnauthorizedWithoutCredentialsReturns401(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/products');

        self::assertResponseStatusCodeSame(401);
    }

    public function testInvalidCredentialsReturns401(): void
    {
        $client = static::createClient([], [
            'PHP_AUTH_USER' => 'admin',
            'PHP_AUTH_PW' => 'wrong_pass',
        ]);
        $client->request('GET', '/admin/products');

        self::assertResponseStatusCodeSame(401);
    }

    public function testPostMethodReturns405(): void
    {
        $client = static::createClient([], $this->authHeaders());
        $client->request('POST', '/admin/products');

        self::assertResponseStatusCodeSame(405);
    }

    public function testHeadMethodReturns200WithEmptyBody(): void
    {
        $client = static::createClient([], $this->authHeaders());
        $client->request('HEAD', '/admin/products');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('', (string) $client->getResponse()->getContent());
        self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('no-cache'));
    }

    public function testGetProductsRendersCatalogPaginationAndFilters(): void
    {
        $client = static::createClient([], $this->authHeaders());

        /** @var Connection $connection */
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');

        // Insert test records
        $connection->executeStatement("INSERT INTO products (id, external_id, name, sku, created_at, updated_at) VALUES
            (77701, 'ext-prod-77701', 'Apple iPhone 15', 'SKU-APPLE-15', NOW(), NOW()),
            (77702, 'ext-prod-77702', 'Apple MacBook Pro', 'SKU-APPLE-MBP', NOW(), NOW()),
            (77703, 'ext-prod-77703', 'Samsung Galaxy S24', 'SKU-SAMS-S24', NOW(), NOW())
            ON CONFLICT (id) DO NOTHING");

        $connection->executeStatement("INSERT INTO product_variants (id, product_id, external_id, price_amount, currency, stock, updated_at) VALUES
            (777011, 77701, 'ext-var-777011', 8990000, 'RUB', 15.000, NOW()),
            (777021, 77702, 'ext-var-777021', 19999000, 'RUB', 3.000, NOW())
            ON CONFLICT (id) DO NOTHING");

        try {
            // 1. Basic index page
            $crawler = $client->request('GET', '/admin/products', [], [], $this->authHeaders());

            self::assertResponseIsSuccessful();
            self::assertResponseStatusCodeSame(200);
            self::assertSelectorTextContains('h2', 'Products & Inventory');
            self::assertSelectorExists('#products-table');
            self::assertSelectorExists('#product-row-77701');
            self::assertSelectorTextContains('#product-row-77701', 'Apple iPhone 15');
            self::assertSelectorTextContains('#product-row-77701', '89 900.00 RUB');
            self::assertSelectorTextContains('#product-row-77701', 'Stock: 15.000');

            // 2. Filter by SKU prefix
            $crawlerSku = $client->request('GET', '/admin/products?search=SKU-APPLE', [], [], $this->authHeaders());
            self::assertResponseIsSuccessful();
            $skuHtml = $crawlerSku->html();
            self::assertStringContainsString('Apple iPhone 15', $skuHtml);
            self::assertStringContainsString('Apple MacBook Pro', $skuHtml);
            self::assertStringNotContainsString('Samsung Galaxy S24', $skuHtml);

            // 3. Filter by exact external_id
            $crawlerExt = $client->request('GET', '/admin/products?search=ext-prod-77703', [], [], $this->authHeaders());
            self::assertResponseIsSuccessful();
            $extHtml = $crawlerExt->html();
            self::assertStringContainsString('Samsung Galaxy S24', $extHtml);
            self::assertStringNotContainsString('Apple iPhone 15', $extHtml);

            // 4. Pagination
            $crawlerPaginated = $client->request('GET', '/admin/products?limit=1&page=1', [], [], $this->authHeaders());
            self::assertResponseIsSuccessful();
            self::assertCount(1, $crawlerPaginated->filter('.product-row'));

            // 5. Empty search result
            $crawlerEmpty = $client->request('GET', '/admin/products?search=NON_EXISTENT_QUERY_XYZ', [], [], $this->authHeaders());
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('#empty-products-row');
            self::assertSelectorTextContains('#empty-products-row', 'No products found matching the criteria.');
        } finally {
            $connection->executeStatement('DELETE FROM product_variants WHERE id IN (777011, 777021)');
            $connection->executeStatement('DELETE FROM products WHERE id IN (77701, 77702, 77703)');
        }
    }
}
