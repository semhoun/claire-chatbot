<?php

declare(strict_types=1);

namespace App\Test\Unit\Services;

use App\Entity\TelegramSession as SessionEntity;
use App\Repository\TelegramSessionRepository;
use App\Services\Session\Exception\SessionException;
use App\Services\Session\TelegramSession;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TelegramSessionTest extends TestCase
{
    public function testSaveKeepsSessionLoadedAndFlushFinalizes(): void
    {
        $entity = new SessionEntity();
        $repository = $this->createStub(TelegramSessionRepository::class);
        $repository->method('findOrCreateByTelegramId')->willReturn($entity);
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getRepository')->willReturn($repository);
        $manager->expects(self::exactly(3))->method('flush');
        $session = new TelegramSession($manager);
        $session->load('42');
        $session->set('brain_avatar', 'first');
        $session->save();
        self::assertSame('first', $session->get('brain_avatar'));
        $session->set('brain_avatar', 'second');
        $session->save();
        $session->flush();
        self::assertSame(['brain_avatar' => 'second'], $entity->getSessionData());
        $this->expectException(SessionException::class);
        $session->ensureLoaded();
    }

    public function testUnchangedSessionStillFlushesOtherManagedSettings(): void
    {
        $repository = $this->createStub(TelegramSessionRepository::class);
        $repository->method('findOrCreateByTelegramId')->willReturn(new SessionEntity());
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getRepository')->willReturn($repository);
        $manager->expects(self::once())->method('flush');
        $session = new TelegramSession($manager);
        $session->load('42');
        $session->save();
        self::assertSame([], $session->all());
    }

    public function testFailedLoadCannotLeavePreviousUserLoaded(): void
    {
        $repository = $this->createStub(TelegramSessionRepository::class);
        $repository->method('findOrCreateByTelegramId')->willReturnCallback(
            static function (string $id): SessionEntity {
                if ($id === 'bad') {
                    throw new RuntimeException('Database unavailable');
                }
                return new SessionEntity();
            },
        );
        $manager = $this->createStub(EntityManagerInterface::class);
        $manager->method('getRepository')->willReturn($repository);
        $session = new TelegramSession($manager);
        $session->load('42');
        try {
            $session->load('bad');
            self::fail('Load must fail');
        } catch (RuntimeException) {
            $this->expectException(SessionException::class);
            $session->ensureLoaded();
        }
    }
}
