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

    public function deleteForUser(string $userId, string $id): bool
    {
        $entityManager = $this->getEntityManager();
        $file = $this->find($id);
        if (! $file instanceof File) {
            return false;
        }

        if ($file->getUser()->getId() !== $userId) {
            return false;
        }

        $entityManager->remove($file);
        $entityManager->flush();
        return true;
    }

    public function findOneByToken(string $token): ?File
    {
        return $this->findOneBy(['token' => $token]);
    }
}
