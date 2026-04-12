<?php

declare(strict_types=1);

namespace App\Test\Unit\Services;

use App\Brain\BrainRegistry;
use App\Services\Auth;
use App\Services\Session\SessionInterface;
use App\Services\Settings;
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
        $this->settings = new Settings(['session' => ['defaultParams' => ['brain_avatar' => 'claire', 'layout_mode' => 'full']]]);
        $container = $this->createMock(ContainerInterface::class);
        $brainRegistry = new BrainRegistry($this->settings, $container);
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
}
