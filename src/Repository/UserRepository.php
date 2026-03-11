<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<User>
 */
class UserRepository extends EntityRepository
{
    public function findByTelegramId(string $telegramId): ?User
    {
        return $this->createQueryBuilder('u')
            ->where('u.telegramId = :telegramId')
            ->setParameter('telegramId', $telegramId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function updateBrainAvatar(User $user, string $brainName): void
    {
        $params = $user->getParams() ?? [];
        $params['brain'] = $brainName;
        $user->setParams($params);

        $this->getEntityManager()->flush();
    }
}
