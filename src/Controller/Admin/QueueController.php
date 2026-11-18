<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Admin\Repository\AdminQueueQueryService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/queues', name: 'admin_queues_', methods: ['GET', 'HEAD'])]
final class QueueController extends AbstractController
{
    public function __construct(
        private readonly AdminQueueQueryService $queueQueryService,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET', 'HEAD'])]
    public function index(): Response
    {
        $overview = $this->queueQueryService->getQueuesOverview();

        $response = $this->render('admin/queues/index.html.twig', [
            'overview' => $overview,
        ]);

        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');

        return $response;
    }
}
