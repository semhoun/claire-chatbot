<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ChatHistory;
use App\Entity\User;
use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<ChatHistory>
 */
class ChatHistoryRepository extends EntityRepository
{
    private const int MIN_MESSAGES_LENGTH = 4;

    /**
     * Compte le nombre d'entrées d'historique pour un utilisateur donné.
     */
    public function countByUserId(string $userId): int
    {
        $user = $this->getUser($userId);
        if (! $user instanceof \App\Entity\User) {
            return 0;
        }

        $queryBuilder = $this->createQueryBuilder('h')
            ->select('COUNT(h.id)')
            ->where('h.user = :user')
            ->andWhere('LENGTH(h.messages) > ' . self::MIN_MESSAGES_LENGTH)
            ->setParameter('user', $user);

        return (int) $queryBuilder->getQuery()->getSingleScalarResult();
    }

    /**
     * Retourne la liste des historiques d'un utilisateur triés par date de mise à jour DESC.
     *
     * @return array<ChatHistory>
     */
    public function getHistoryList(string $userId): array
    {
        $user = $this->getUser($userId);
        if (! $user instanceof \App\Entity\User) {
            return [];
        }

        return $this->createQueryBuilder('h')
            ->where('h.user = :user')
            ->andWhere('LENGTH(h.messages) > ' . self::MIN_MESSAGES_LENGTH)
            ->setParameter('user', $user)
            ->orderBy('h.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Supprime les conversations vides d'un utilisateur (titre par défaut et résumé vide/null).
     */
    public function deleteEmptyConversations(string $userId): int
    {
        $user = $this->getUser($userId);
        if (! $user instanceof \App\Entity\User) {
            return 0;
        }

        return (int) $this->createQueryBuilder('h')
            ->delete()
            ->where('h.user = :user')
            ->andWhere('LENGTH(h.messages) < ' . self::MIN_MESSAGES_LENGTH . ' OR h.messages IS NULL')
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
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
        $user = $this->getUser($userId);
        if (! $user instanceof \App\Entity\User) {
            return false;
        }

        $queryBuilder = $this->createQueryBuilder('h')
            ->where('h.threadId = :threadId AND h.user = :user')
            ->setParameter('threadId', $threadId)
            ->setParameter('user', $user)
            ->setMaxResults(1);

        $history = $queryBuilder->getQuery()->getOneOrNullResult();
        if ($history === null) {
            return false;
        }

        $this->getEntityManager()->remove($history);
        $this->getEntityManager()->flush();

        return true;
    }

    private function getUser(string $userId): ?User
    {
        return $this->getEntityManager()->getRepository(User::class)->find($userId);
    }
}
