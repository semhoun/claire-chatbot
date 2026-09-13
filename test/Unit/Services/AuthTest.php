<?php

declare(strict_types=1);

namespace App\Test\Unit\Services;

use App\Brain\BrainRegistry;
use App\Services\Auth;
use App\Services\Session\SessionInterface;
use App\Services\Settings;
use App\Services\ThemeRegistry;
use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

#[AllowMockObjectsWithoutExpectations]
final class AuthTest extends TestCase
{
    private SessionInterface $session;

    private EntityManager $entityManager;

    private Settings $settings;

    private Auth $auth;

    protected function setUp(): void
    {
        $this->session = $this->createMock(SessionInterface::class);
        $this->entityManager = $this->createMock(EntityManager::class);
        $this->settings = new Settings([
            'session' => ['defaultParams' => ['brain_avatar' => 'claire', 'layout_mode' => 'full']],
            'llm' => ['brains' => ['claire' => \App\Brain\Claire::class]],
        ]);
        $container = $this->createMock(ContainerInterface::class);
        $brainRegistry = new BrainRegistry($this->settings, $container, new ThemeRegistry($this->settings));
        $this->auth = new Auth($this->entityManager, $this->settings, $brainRegistry);
    }

    public function testIsAuthenticatedReturnsTrueWhenSessionLoggedIsTrue(): void
    {
        $this->session->method('has')->with(Auth::AUTHENTICATED)->willReturn(true);
        $this->session->method('get')->with(Auth::AUTHENTICATED)->willReturn(true);

        $this->assertTrue($this->auth->isAuthenticated($this->session));
    }

    public function testIsAuthenticatedReturnsFalseWhenSessionLoggedIsMissing(): void
    {
        $this->session->method('has')->with(Auth::AUTHENTICATED)->willReturn(false);

        $this->assertFalse($this->auth->isAuthenticated($this->session));
    }

    public function testLogoutClearsSession(): void
    {
        $this->session->expects($this->once())
            ->method('clear');

        $this->auth->logout($this->session);
    }

    public function testRestoreRejectsDeletedUserWithoutCreatingIt(): void
    {
        $repository = $this->createStub(\App\Repository\UserRepository::class);
        $repository->method('find')->willReturn(null);
        $this->entityManager->method('getRepository')->willReturn($repository);
        $this->entityManager->expects(self::never())->method('persist');
        self::assertFalse($this->auth->restore($this->session, 'deleted'));
    }

    public function testRestoreLoadsCurrentProfileAndPreferences(): void
    {
        $user = new \App\Entity\User();
        $user->setId('user-1');
        $user->setFirstName('Current');
        $user->setLastName('Name');
        $user->setEmail('current@example.test');
        $user->setParams(['layout_mode' => 'compact']);
        $repository = $this->createStub(\App\Repository\UserRepository::class);
        $repository->method('find')->willReturn($user);
        $this->entityManager->method('getRepository')->willReturn($repository);
        $this->entityManager->expects(self::never())->method('flush');
        $session = new \App\Services\Session\ArraySession();
        $session->set('stale', true);
        self::assertTrue($this->auth->restore($session, 'user-1'));
        self::assertFalse($session->has('stale'));
        self::assertSame(true, $session->get(Auth::AUTHENTICATED));
        self::assertSame('user-1', $session->get(Auth::USERID));
        self::assertSame('compact', $session->get('layout_mode'));
        self::assertSame('Current Name', $session->get(Auth::USERINFO)['displayName']);
    }
}
