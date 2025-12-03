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
    /**
     * Compte le nombre d'entrées d'historique pour un utilisateur donné.
     */
    public function countByUserId(string $userId): int
    {
        $qb = $this->createQueryBuilder('h')
            ->select('COUNT(h.id)')
            ->leftJoin('h.user', 'u')
            ->where('u.id = :userId')
            ->setParameter('userId', $userId);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Retourne la liste des historiques d'un utilisateur triés par date de mise à jour DESC.
     *
     * @return ChatHistory[]
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

    public function getShareGptMessages(string $userId, string $threadId): ?array
    {
        $messagesEnt =  $this->createQueryBuilder('h')
            ->leftJoin('h.user', 'u')
            ->where('u.id = :userId AND h.threadId = :threadId')
            ->setParameter('userId', $userId)
            ->setParameter('threadId', $threadId)
            ->getQuery()
            ->getOneOrNullResult();

        if ($messagesEnt === null)
        {
            return null;
        }

        $messages = [];
        try {
            $decoded = json_decode($messagesEnt->getMessages(), true, 512, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                foreach ($decoded as $m) {
                    if (!is_array($m)) continue;
                    $role = (string)($m['role'] ?? 'assistant');
                    $content = (string)($m['content'][0]['text'] ?? '');
                    if ($content === '') continue;
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
