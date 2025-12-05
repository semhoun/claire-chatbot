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
    /** @return File[] */
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
        $qb = $this->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->where('IDENTITY(f.user) = :userId')
            ->setParameter('userId', $userId);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function deleteForUser(string $userId, string $id): bool
    {
        $em = $this->getEntityManager();
        $file = $this->find($id);
        if (! $file instanceof File) {
            return false;
        }
        if ((string) $file->getUser()->getId() !== $userId) {
            return false;
        }
        $em->remove($file);
        $em->flush();
        return true;
    }
}
