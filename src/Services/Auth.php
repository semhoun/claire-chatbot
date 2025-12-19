<?php

namespace App\Services;

use App\Entity\User;
use App\Exception;
use Doctrine\ORM\EntityManager;
use Odan\Session\SessionInterface;
use Slim\Views\Twig;

class Auth
{
    public function __construct(
        private SessionInterface $session,
        private EntityManager $entityManager,
    ) {
    }

    /**
     * Checks if the current user is authenticated by verifying the presence
     * and value of the 'logged' session key.
     *
     * @return bool True if the user is authenticated, false otherwise.
     */
    public function isAuthenticated(): bool {
        return $this->session->has('logged') && $this->session->get('logged');
    }

    /**
     * Logs in a user by setting session variables and ensuring the user exists
     * in the database. If the user does not exist, it creates a new record;
     * otherwise, it updates their basic information. The method also synchronizes
     * additional user parameters with the session.
     *
     * @param array $uinfo An associative array containing user information,
     *                     typically provided by an OIDC provider. It must include
     *                     an 'id' key and may include 'firstName', 'lastName',
     *                     'email', and 'name'.
     *
     * @return void
     *
     * @throws Exception If the 'id' key is not provided in the user information
     *                   or if the user could not be processed in the database.
     */
    public function login(array $uinfo): void
    {
        $this->session->set('logged', true);
        $this->session->set('userId', $uinfo['id']);

        // Vérifier l'existence de l'utilisateur en base via son id (sub OIDC).
        // Le créer s'il n'existe pas, sinon mettre à jour les infos de base.
        $userId = (string) ($uinfo['id'] ?? '');
        if ($userId === '') {
            throw new Exception('User id not provided by OIDC provider:');
        }

        $this->session->set('uinfo', $uinfo);

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
            $user->setFirstName($uinfo['firstName']);
            $user->setLastName($uinfo['lastName']);
            $user->setEmail($uinfo['email']);
            if (($uinfo['firstName'] ?? null) === null && ($uinfo['lastName'] ?? null) === null && ($uinfo['name'] ?? null) !== null) {
                $user->setFirstName($uinfo['name']);
            }

            $this->entityManager->flush();

            foreach ($user->getParams() ?? [] as $key => $value) {
                $this->session->set($key, $value);
            }
        } catch (\Exception $exception) {
            throw new Exception('User not found in database: ' . $exception->getMessage());
        }
    }

    /**
     * Logs out the current user by updating session data to reflect
     * that the user is no longer authenticated and clearing user information.
     *
     * @return void
     */
    public function logout(): void
    {
        $this->session->set('logged', false);
        $this->session->set('uinfo', null);
    }
}