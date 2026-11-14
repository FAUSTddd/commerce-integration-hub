<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Admin\Database\ReadOnlyTransactionRunner;
use App\Monitoring\Service\SystemHealthMonitorService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    public function __construct(
        private readonly SystemHealthMonitorService $monitorService,
        private readonly ReadOnlyTransactionRunner $transactionRunner,
    ) {
    }

    #[Route('/admin', name: 'admin_dashboard', methods: ['GET'])]
    public function __invoke(): Response
    {
        $overview = $this->transactionRunner->run(
            fn() => $this->monitorService->getSystemOverview()
        );

        return $this->render('admin/dashboard.html.twig', [
            'overview' => $overview,
        ]);
    }
}
