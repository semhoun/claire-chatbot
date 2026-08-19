<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Services\Auth;
use App\Services\Session\SessionInterface;
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

    public function getCurrentUser(SessionInterface $session): ?User
    {
        $userId = (string) $session->get(Auth::USERID);
        if ($userId === '') {
            return null;
        }

        return $this->find($userId);
    }
}
