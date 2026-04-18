<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ChatHistoryFile;
use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<ChatHistoryFile>
 */
class ChatHistoryFileRepository extends EntityRepository
{
    /**
     * @return array<ChatHistoryFile>
     */
    public function findByHistoryId(string $historyId): array
    {
        return $this->createQueryBuilder('f')
            ->where('IDENTITY(f.history) = :historyId')
            ->setParameter('historyId', $historyId)
            ->getQuery()
            ->getResult();
    }
}
