<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ChatHistory;
use App\Entity\User;
use App\Services\ChatGenerationBusyException;
use App\Services\ChatThreadLock;
use App\Services\ChatTurnJournal;
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

    public function getLatestHistory(string $userId): ?ChatHistory
    {
        $user = $this->getUser($userId);
        if (! $user instanceof \App\Entity\User) {
            return null;
        }

        return $this->createQueryBuilder('h')
            ->where('h.user = :user')
            ->andWhere('h.displayMessagesCount > ' . self::MIN_MESSAGES)
            ->setParameter('user', $user)
            ->orderBy('h.updatedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
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

        $connection = $this->getEntityManager()->getConnection();
        $threads = $connection->fetchFirstColumn(
            'SELECT thread_id FROM chat_history WHERE user_id = ? AND display_messages_count <= ?'
            . ' AND NOT EXISTS (SELECT 1 FROM chat_turn WHERE chat_turn.user_id = chat_history.user_id'
            . ' AND chat_turn.thread_id = chat_history.thread_id)',
            [$userId, self::MIN_MESSAGES],
        );
        $deleted = 0;
        foreach ($threads as $threadId) {
            try {
                $lock = new ChatThreadLock($connection->getNativeConnection(), $userId, $threadId);
            } catch (ChatGenerationBusyException) {
                continue;
            }
            try {
                // Journalized threads include selected Telegram openings and rolled-back drafts.
                $eligible = $connection->fetchOne(
                    'SELECT thread_id FROM chat_history WHERE user_id = ? AND thread_id = ?'
                    . ' AND display_messages_count <= ? AND current_turn_id IS NULL'
                    . ' AND NOT EXISTS (SELECT 1 FROM chat_turn WHERE chat_turn.user_id = chat_history.user_id'
                    . ' AND chat_turn.thread_id = chat_history.thread_id)',
                    [$userId, $threadId, self::MIN_MESSAGES],
                );
                if ($eligible !== false && $this->deleteThread($userId, $threadId)) {
                    ++$deleted;
                }
            } finally {
                $lock->release();
            }
        }
        return $deleted;
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

        // Caller holds ChatThreadLock; recovery must never recreate a deleted thread.
        $connection = $this->getEntityManager()->getConnection();
        $connection->transactional(function () use ($connection, $userId, $threadId, $history): void {
            new ChatTurnJournal($connection)->neutralize($userId, $threadId);
            $this->getEntityManager()->remove($history);
            $this->getEntityManager()->flush();
        });

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
                // Ignore errors during file deletion to avoid blocking thread deletion
            }
        }
    }

    private function getUser(string $userId): ?User
    {
        return $this->getEntityManager()->getRepository(User::class)->find($userId);
    }
}
