<?php

declare(strict_types=1);

namespace App\Services;

use App\Entity\User;
use App\Exception;
use App\Services\Session\SessionInterface;
use Doctrine\ORM\EntityManager;

class Auth
{
    public const string AUTHENTICATED = 'logged';

    public const string USERID = 'user_id';

    public const string USERINFO = 'user_info';

    public function __construct(
        private readonly SessionInterface $session,
        private readonly EntityManager $entityManager,
        private readonly Settings $settings,
    ) {
    }

    /**
     * Checks if the current user is authenticated by verifying the presence
     * and value of the self::AUTHENTICATED session key.
     *
     * @return bool True if the user is authenticated, false otherwise.
     */
    public function isAuthenticated(): bool
    {
        return $this->session->has(self::AUTHENTICATED) && $this->session->get(self::AUTHENTICATED);
    }

    /**
     * Authenticates and logs in a user by their identifier.
     *
     * Creates the user in the database if they do not exist, or updates their
     * information if they do. Persists user details from the provided info array,
     * sets user parameters in the session, initializes the brain avatar, and marks
     * the session as authenticated.
     *
     * @param string $userId The unique user identifier (OIDC sub).
     * @param array $userInfo Associative array containing user details such as firstName, lastName, email, and name.
     *
     * @throws Exception If the user cannot be found or persisted in the database.
     */
    public function login(string $userId, array $userInfo): void
    {
        // Vérifier l'existence de l'utilisateur en base via son id (sub OIDC).
        // Le créer s'il n'existe pas, sinon mettre à jour les infos de base.
        try {
            /** @var User|null $user */
            $user = $this->entityManager->getRepository(User::class)->find($userId);
            if ($user === null) {
                $user = new User();
                $user->setId($userId);
                $this->entityManager->persist($user);
                $this->entityManager->flush();
            }

            $user = $this->entityManager->getRepository(User::class)->find($userId);
            $user->setFirstName($userInfo['firstName']);
            $user->setLastName($userInfo['lastName']);
            $user->setEmail($userInfo['email']);
            if (($userInfo['firstName'] ?? null) === null && ($userInfo['lastName'] ?? null) === null && ($userInfo['name'] ?? null) !== null) {
                $user->setFirstName($userInfo['name']);
            }

            $this->entityManager->flush();

            foreach ($user->getParams() ?? [] as $key => $value) {
                $this->session->set($key, $value);
            }

            // Déterminer l'avatar/assistant courant (session, sinon préférence utilisateur, sinon défaut)
            $currentBrain = (string) ($this->session->get('brain_avatar') ?? '');
            if ($currentBrain === '') {
                $this->session->set('brain_avatar', $this->settings->get('llm.defaultBrain'));
            }
        } catch (\Exception $exception) {
            throw new Exception('User [' . $userId . "] not found in database and can't add it: " . $exception->getMessage(), $exception->getCode(), $exception);
        }

        $this->session->set(self::AUTHENTICATED, true);
        $this->session->set(self::USERID, $userId);
        $this->session->set(self::USERINFO, $userInfo);
    }

    /**
     * Logs out the current user by updating session data to reflect
     * that the user is no longer authenticated and clearing user information.
     */
    public function logout(): void
    {
        $this->session->clear();
    }
}
