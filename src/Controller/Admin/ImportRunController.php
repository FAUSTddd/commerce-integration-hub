<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Admin\Repository\AdminImportRunQueryService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/imports', name: 'admin_imports_')]
final class ImportRunController extends AbstractController
{
    public function __construct(
        private readonly AdminImportRunQueryService $queryService,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $page = $request->query->getInt('page', 1);
        $limit = $request->query->getInt('limit', 20);
        $status = $request->query->getString('status');
        $type = $request->query->getString('type');

        $status = $status !== '' ? $status : null;
        $type = $type !== '' ? $type : null;

        $paginatedRuns = $this->queryService->findPaginatedRuns($page, $limit, $status, $type);

        return $this->render('admin/imports/index.html.twig', [
            'paginated' => $paginatedRuns,
            'current_status' => $status,
            'current_type' => $type,
        ]);
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): Response
    {
        $runDetail = $this->queryService->findRunDetail($id);

        if ($runDetail === null) {
            throw new NotFoundHttpException(sprintf('ImportRun #%d not found.', $id));
        }

        return $this->render('admin/imports/show.html.twig', [
            'run' => $runDetail,
        ]);
    }
}
