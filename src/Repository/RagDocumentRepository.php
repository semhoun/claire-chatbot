<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RagDocument;
use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<RagDocument>
 */
class RagDocumentRepository extends EntityRepository
{
    /** @return array<RagDocument> */
    public function listByUser(string $userId): array
    {
        return $this->createQueryBuilder('r')
            ->where('IDENTITY(r.user) = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** @return array<RagDocument> */
    public function findActiveByUser(string $userId): array
    {
        return $this->createQueryBuilder('r')
            ->where('IDENTITY(r.user) = :userId')
            ->andWhere('r.isActive = :active')
            ->setParameter('userId', $userId)
            ->setParameter('active', true)
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function countByUserId(string $userId): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('IDENTITY(r.user) = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findOneByDocumentIdAndUser(string $documentId, string $userId): ?RagDocument
    {
        return $this->createQueryBuilder('r')
            ->where('r.documentId = :documentId')
            ->andWhere('IDENTITY(r.user) = :userId')
            ->setParameter('documentId', $documentId)
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
