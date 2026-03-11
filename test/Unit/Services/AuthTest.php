<?php



declare(strict_types=1);



namespace App\Test\Unit\Services;



use App\Services\Auth;

use App\Services\Settings;

use App\Session\SessionInterface;

use Doctrine\ORM\EntityManager;

use PHPUnit\Framework\TestCase;



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

        $this->settings = new Settings(['llm' => ['defaultBrain' => 'claire']]);

        $this->auth = new Auth($this->session, $this->entityManager, $this->settings);

    }



    public function testIsAuthenticatedReturnsTrueWhenSessionLoggedIsTrue(): void

    {

        $this->session->method('has')->with('logged')->willReturn(true);

        $this->session->method('get')->with('logged')->willReturn(true);



        $this->assertTrue($this->auth->isAuthenticated());

    }



    public function testIsAuthenticatedReturnsFalseWhenSessionLoggedIsMissing(): void

    {

        $this->session->method('has')->with('logged')->willReturn(false);



        $this->assertFalse($this->auth->isAuthenticated());

    }



    public function testLogoutClearsSession(): void

    {

        $this->session->expects($this->exactly(2))

            ->method('set')

            ->willReturnCallback(function ($key, $value) {

                static $count = 0;

                $expected = [

                    ['logged', false],

                    ['data', null]

                ];

                $this->assertSame($expected[$count][0], $key);

                $this->assertSame($expected[$count][1], $value);

                $count++;

            });



        $this->auth->logout();

    }

}

