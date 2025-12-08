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

    /**
     * Retrieves formatted GPT messages associated with a specific user and thread.
     *
     * @param string $userId The ID of the user whose GPT messages are being retrieved.
     * @param string $threadId The ID of the thread linked to the user's messages.
     *
     * @return array|null Returns an array of formatted messages, where each message contains a role and content, or null if no messages are found or cannot be decoded.
     */
    public function getShareGptMessages(string $userId, string $threadId): ?array
    {
        $messagesEnt = $this->createQueryBuilder('h')
            ->leftJoin('h.user', 'u')
            ->where('u.id = :userId AND h.threadId = :threadId')
            ->setParameter('userId', $userId)
            ->setParameter('threadId', $threadId)
            ->getQuery()
            ->getOneOrNullResult();

        if ($messagesEnt === null) {
            return null;
        }

        $messages = [];
        try {
            $decoded = json_decode((string) $messagesEnt->getMessages(), true, 512, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                foreach ($decoded as $m) {
                    if (! is_array($m)) {
                        continue;
                    }

                    $role = (string) ($m['role'] ?? 'assistant');
                    $content = (string) ($m['content'][0]['text'] ?? '');
                    if ($content === '') {
                        continue;
                    }

                    $messages[] = [
                        'role' => $role,
                        'content' => $content,
                    ];
                }
            }
        } catch (\JsonException) {
            // ignore, laisser messages vide
        }

        return $messages;
    }
}
