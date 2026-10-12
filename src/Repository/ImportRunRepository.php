<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ImportRun;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ImportRun>
 */
class ImportRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ImportRun::class);
    }

    public function save(ImportRun $importRun): void
    {
        $this->getEntityManager()->persist($importRun);
        $this->getEntityManager()->flush();
    }

    public function findActiveRun(string $type, string $filename): ?ImportRun
    {
        return $this->createQueryBuilder('r')
            ->where('r.type = :type')
            ->andWhere('r.filename = :filename')
            ->andWhere('r.status IN (:statuses)')
            ->setParameter('type', $type)
            ->setParameter('filename', $filename)
            ->setParameter('statuses', [ImportRun::STATUS_PENDING, ImportRun::STATUS_PROCESSING])
            ->orderBy('r.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findLatestPendingOrProcessing(string $type, string $filename): ?ImportRun
    {
        return $this->findActiveRun($type, $filename);
    }
}
