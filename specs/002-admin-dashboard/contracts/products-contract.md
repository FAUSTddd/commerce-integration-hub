# UI & Data Contract: Products & Inventory Catalog (`/admin/products`)

## 1. Route Definition
- **Path**: `/admin/products`
- **HTTP Method**: `GET` (строго `GET`/`HEAD`, методы `POST`, `PUT`, `DELETE` возвращают `405 Method Not Allowed`)
- **Access Control**: `ROLE_ADMIN`
- **Response Format**: `text/html; charset=UTF-8`

## 2. Injected Services & Data Providers
- `App\Repository\ProductRepository`
- `App\Repository\ProductVariantRepository`
- Режим транзакции: `SET TRANSACTION READ ONLY;`, без вызова `flush()`.

## 3. Query Parameters
- `page`: integer (default: 1, min: 1)
- `limit`: integer (default: 25, max: 50)
- `search`: string (null, поиск по началу SKU или точному external_id)

## 4. Two-Phase Query Optimization & Doctrine ORM 3 Safety
Во избежание искажения лимитов пагинации при использовании `OneToMany` связей и исключения проблемы N+1 запросов:
- **Фаза 1 (ID-first scan)**:
  `SELECT p.id FROM App\Entity\Product p [WHERE ...] ORDER BY p.id DESC` с вызовом `setFirstResult(($page - 1) * $limit)` и `setMaxResults($limit)`.
- **Фаза 2 (Hydration)**:
  Если список `$ids` не пуст:
  `SELECT p, v FROM App\Entity\Product p LEFT JOIN p.variants v WHERE p.id IN (:ids) ORDER BY p.id DESC`.
- Поиск по `sku` задействует индекс `idx_products_sku`.
- Поиск по `external_id` задействует уникальный индекс `UNIQ_B3BA5A5A9F75D7B0`.

## 5. View Model (Twig Context)

```php
[
    'products' => [
        [
            'id' => int,
            'external_id' => string,
            'sku' => ?string,
            'name' => string,
            'variants_count' => int,
            'updated_at' => DateTimeImmutable,
            'variants' => [
                [
                    'id' => int,
                    'external_id' => string,
                    'price_formatted' => string, // Форматируется из price_amount: 150000 -> "1 500.00 RUB"
                    'currency' => string,
                    'stock_formatted' => string, // NUMERIC(14, 3): "10.000"
                    'updated_at' => DateTimeImmutable,
                ],
            ],
        ],
    ],
    'pagination' => [
        'current_page' => int,
        'total_pages' => int,
        'total_items' => int,
        'limit' => int,
    ],
    'search' => ?string,
]
```

## 6. Financial & Inventory Precision Requirements
- Цены хранятся в БД в копейках (`price_amount: int`). В UI выводится значение, разделенное на 100 с двумя знаками после запятой. Использование `float` в расчетах запрещено.
- Остатки хранятся как точные строки `NUMERIC(14, 3)` (`stock: string`). Выводятся со строгим сохранением точности без округлений.
- Автоэкранирование Twig включено: защита от XSS в `name`, `sku` и `external_id`.
