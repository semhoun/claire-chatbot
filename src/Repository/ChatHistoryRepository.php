<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ChatHistory;
use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<ChatHistory>
 */
class ChatHistoryRepository extends EntityRepository
{
    /**
     * Compte le nombre d'entrées d'historique pour un utilisateur donné.
     */
    public function countByUserId(string $userId): int
    {
        $queryBuilder = $this->createQueryBuilder('h')
            ->select('COUNT(h.id)')
            ->leftJoin('h.user', 'u')
            ->where('u.id = :userId')
            ->setParameter('userId', $userId);

        return (int) $queryBuilder->getQuery()->getSingleScalarResult();
    }

    /**
     * Retourne la liste des historiques d'un utilisateur triés par date de mise à jour DESC.
     *
     * @return array<ChatHistory>
     */
    public function getHistoryList(string $userId): array
    {
        return $this->createQueryBuilder('h')
            ->leftJoin('h.user', 'u')
            ->where('u.id = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('h.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Deletes a thread belonging to a specific user.
     *
     * @param string $userId The ID of the user attempting to delete the thread.
     * @param string $threadId The ID of the thread to be deleted.
     *
     * @return bool Returns true if the thread was successfully deleted, or false if the thread does not exist or does not belong to the user.
     */
    public function deleteThread(string $userId, string $threadId): bool
    {
        $queryBuilder = $this->createQueryBuilder('h')
            ->innerJoin('h.user', 'u')
            ->where('h.threadId = :threadId AND u.id = :userId')
            ->setParameter('threadId', $threadId)
            ->setParameter('userId', $userId)
            ->setMaxResults(1);

        $history = $queryBuilder->getQuery()->getOneOrNullResult();
        if ($history === null) {
            return false;
        }

        $this->getEntityManager()->remove($history);
        $this->getEntityManager()->flush();

        return true;
    }
}
