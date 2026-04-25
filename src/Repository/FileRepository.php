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
            ->andWhere('f.chatHistory IS NULL')
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
            ->andWhere('f.chatHistory IS NULL')
            ->setParameter('userId', $userId);

        return (int) $queryBuilder->getQuery()->getSingleScalarResult();
    }

    /**
     * @return array<File>
     */
    public function findByHistoryId(string $historyId): array
    {
        return $this->createQueryBuilder('f')
            ->where('IDENTITY(f.chatHistory) = :historyId')
            ->setParameter('historyId', $historyId)
            ->getQuery()
            ->getResult();
    }

    public function findOneByFilePath(string $filePath): ?File
    {
        return $this->findOneBy(['filePath' => $filePath]);
    }

    public function findDisplayNameByFilePath(string $filePath): ?string
    {
        $file = $this->findOneByFilePath($filePath);

        return $file?->getFilename();
    }
}
