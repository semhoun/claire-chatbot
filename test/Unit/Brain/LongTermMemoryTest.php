<?php

declare(strict_types=1);

namespace Test\Unit\Brain;

use App\Brain\LongTermMemory;
use App\Services\Auth;
use App\Services\Session\InMemorySession;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class LongTermMemoryTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement(
            'CREATE TABLE long_term_memory (user_id TEXT PRIMARY KEY, content TEXT, updated_at DATETIME)'
        );
    }

    public function testRecallReturnsNothingWhenDisabled(): void
    {
        $session = new InMemorySession([Auth::USERID => 'user-1', LongTermMemory::SESSION_KEY => false]);
        $this->assertSame('', new LongTermMemory($this->connection, $session)->recall());
    }

    public function testMemoryEvolvesAndRemainsIsolatedByUser(): void
    {
        $session = new InMemorySession([Auth::USERID => 'user-1', LongTermMemory::SESSION_KEY => true]);
        $memory = new LongTermMemory($this->connection, $session);
        $memory->store('Le client préfère des réponses courtes.');
        $memory->store('Le client préfère désormais des réponses détaillées.');

        $this->assertSame('Le client préfère désormais des réponses détaillées.', $memory->recall());
        $otherSession = new InMemorySession([Auth::USERID => 'other', LongTermMemory::SESSION_KEY => true]);
        $this->assertSame('', new LongTermMemory($this->connection, $otherSession)->recall());
    }
}
