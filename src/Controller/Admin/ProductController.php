<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Admin\Dto\ProductItemView;
use App\Admin\Dto\ProductsCatalogViewModel;
use App\Admin\Dto\ProductVariantView;
use App\Admin\Repository\AdminProductQueryService;
use App\Entity\ProductVariant;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/products', name: 'admin_products_')]
final class ProductController extends AbstractController
{
    public function __construct(
        private readonly AdminProductQueryService $queryService,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET', 'HEAD'])]
    public function index(Request $request): Response
    {
        $page = $request->query->getInt('page', 1);
        $limit = $request->query->getInt('limit', 25);
        $search = $request->query->getString('search');

        $search = trim($search) !== '' ? trim($search) : null;

        $paginatedIds = $this->queryService->findPaginatedProductIds($page, $limit, $search);
        $products = $this->queryService->findProductsWithVariantsByIds($paginatedIds->ids);

        $productViews = array_map(function ($product): ProductItemView {
            $variantViews = [];
            foreach ($product->getVariants() as $variant) {
                /** @var ProductVariant $variant */
                // Safe financial formatting without float: 150000 -> 1500.00
                $priceAmount = $variant->getPriceAmount();
                $integerPart = intdiv($priceAmount, 100);
                $cents = abs($priceAmount % 100);
                $formattedPrice = sprintf('%s.%02d %s', number_format($integerPart, 0, '', ' '), $cents, $variant->getCurrency());

                $variantViews[] = new ProductVariantView(
                    id: (int) $variant->getId(),
                    externalId: $variant->getExternalId(),
                    priceAmount: $priceAmount,
                    priceFormatted: $formattedPrice,
                    currency: $variant->getCurrency(),
                    stockFormatted: $variant->getStock(),
                    updatedAt: $variant->getUpdatedAt(),
                );
            }

            return new ProductItemView(
                id: (int) $product->getId(),
                externalId: $product->getExternalId(),
                sku: $product->getSku(),
                name: $product->getName(),
                variantsCount: count($variantViews),
                updatedAt: $product->getUpdatedAt(),
                variants: $variantViews,
            );
        }, $products);

        $viewModel = new ProductsCatalogViewModel(
            products: $productViews,
            currentPage: $paginatedIds->page,
            totalPages: $paginatedIds->totalPages,
            totalItems: $paginatedIds->totalItems,
            limit: $paginatedIds->limit,
            search: $search,
        );

        $response = $this->render('admin/products/index.html.twig', [
            'catalog' => $viewModel,
        ]);

        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');

        return $response;
    }
}
