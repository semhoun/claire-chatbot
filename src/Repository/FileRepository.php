<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\File;
use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<File>
 */
class FileRepository extends EntityRepository
{
    /** @return array<File> */
    public function listByUser(string $userId): array
    {
        return $this->createQueryBuilder('f')
            ->where('IDENTITY(f.user) = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('f.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function countByUserId(string $userId): int
    {
        $queryBuilder = $this->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->where('IDENTITY(f.user) = :userId')
            ->setParameter('userId', $userId);

        return (int) $queryBuilder->getQuery()->getSingleScalarResult();
    }
}
