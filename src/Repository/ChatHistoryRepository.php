<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ChatHistory;
use App\Entity\User;
use Doctrine\ORM\EntityRepository;
use League\Flysystem\Filesystem;

/**
 * @extends EntityRepository<ChatHistory>
 */
class ChatHistoryRepository extends EntityRepository
{
    private const int MIN_MESSAGES = 1;

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
            ->andWhere('h.displayMessagesCount > ' . self::MIN_MESSAGES)
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
            ->andWhere('h.displayMessagesCount > ' . self::MIN_MESSAGES)
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
            ->andWhere('h.displayMessagesCount <= ' . self::MIN_MESSAGES . ' OR h.displayMessages IS NULL')
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }

    /**
     * Deletes a thread belonging to a specific user.
     *
     * @param string $userId The ID of the user attempting to delete the thread.
     * @param string $threadId The ID of the thread to be deleted.
     * @param Filesystem|null $filesystem Optional filesystem to delete associated files
     *
     * @return bool Returns true if the thread was successfully deleted, or false if the thread does not exist or does not belong to the user.
     */
    public function deleteThread(string $userId, string $threadId, ?Filesystem $filesystem = null): bool
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

        // Delete physical files before removing DB entity (cascade will delete records)
        if ($filesystem instanceof \League\Flysystem\Filesystem) {
            $this->deleteAssociatedFiles($history, $filesystem);
        }

        $this->getEntityManager()->remove($history);
        $this->getEntityManager()->flush();

        return true;
    }

    public function getCurrentUserChatHistory($session, string $threadId): ?ChatHistory
    {
        $user = $this->getEntityManager()->getRepository(User::class)->getCurrentUser($session);
        if ($user === null) {
            return null;
        }

        return $this->findOneBy(['threadId' => $threadId, 'user' => $user]);
    }

    /**
     * Delete files associated with a chat history.
     */
    private function deleteAssociatedFiles(ChatHistory $chatHistory, Filesystem $filesystem): void
    {
        foreach ($chatHistory->getFiles() as $file) {
            $filePath = $file->getFilePath();

            try {
                if ($filesystem->fileExists($filePath)) {
                    $filesystem->delete($filePath);
                }
            } catch (\Throwable) {
            }
        }
    }

    private function getUser(string $userId): ?User
    {
        return $this->getEntityManager()->getRepository(User::class)->find($userId);
    }
}
