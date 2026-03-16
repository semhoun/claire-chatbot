<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TelegramSession;
use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<TelegramSession>
 */

class TelegramSessionRepository extends EntityRepository
{
    public function findByTelegramId(string $telegramId): ?TelegramSession
    {
        return $this->createQueryBuilder('ts')
            ->where('ts.telegramId = :telegramId')
            ->setParameter('telegramId', $telegramId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOrCreateByTelegramId(string $telegramId): TelegramSession
    {
        $session = $this->findByTelegramId($telegramId);

        if ($session instanceof \App\Entity\TelegramSession) {
            return $session;
        }

        // On a pas de session on va en créer une et configurer les infos de l'utilisateur
        $session = new TelegramSession();
        $session->setTelegramId($telegramId);
        $this->getEntityManager()->persist($session);
        $this->getEntityManager()->flush();

        return $session;
    }

    public function save(TelegramSession $telegramSession): void
    {
        $this->getEntityManager()->persist($telegramSession);
        $this->getEntityManager()->flush();
    }

    public function delete(TelegramSession $telegramSession): void
    {
        $this->getEntityManager()->remove($telegramSession);
        $this->getEntityManager()->flush();
    }
}
