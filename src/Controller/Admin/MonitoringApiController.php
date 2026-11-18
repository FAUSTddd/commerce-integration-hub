<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Admin\Database\ReadOnlyTransactionRunner;
use App\Monitoring\Dto\SystemHealthOverview;
use App\Monitoring\Service\SystemHealthMonitorService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class MonitoringApiController extends AbstractController
{
    public function __construct(
        private readonly SystemHealthMonitorService $monitorService,
        private readonly ReadOnlyTransactionRunner $transactionRunner,
    ) {
    }

    #[Route('/admin/api/health', name: 'admin_api_health', methods: ['GET', 'HEAD'])]
    public function health(): JsonResponse
    {
        /** @var SystemHealthOverview $overview */
        $overview = $this->transactionRunner->run(
            fn() => $this->monitorService->getSystemOverview()
        );

        $queuesData = [];
        foreach ($overview->queueMetrics as $snapshot) {
            $queuesData[$snapshot->queueName] = [
                'name' => $snapshot->queueName,
                'size' => $snapshot->pendingCount,
                'oldest_message_age_seconds' => $snapshot->oldestMessageAgeSeconds ?? 0,
            ];
        }

        $activeRunsData = [];
        foreach ($overview->activeRuns as $run) {
            $activeRunsData[] = [
                'id' => $run->id,
                'type' => $run->type,
                'filename' => $run->filenameClean,
                'status' => $run->status,
                'duration_seconds' => $run->durationSeconds,
                'attempt' => $run->attempt,
                'is_stale' => $run->isStale,
            ];
        }

        $outboxData = [
            'unpublished_count' => $overview->outboxMetrics !== null ? $overview->outboxMetrics->unpublishedCount : 0,
            'max_lag_seconds' => $overview->outboxMetrics !== null ? ($overview->outboxMetrics->oldestUnpublishedAgeSeconds ?? 0) : 0,
        ];

        $data = [
            'status' => $overview->status,
            'generated_at' => $overview->timestamp->format(\DateTimeInterface::ATOM),
            'anomalies' => $overview->anomalies,
            'queues' => $queuesData,
            'outbox' => $outboxData,
            'active_runs' => $activeRunsData,
        ];

        $response = new JsonResponse($data, Response::HTTP_OK);
        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');

        return $response;
    }
}
