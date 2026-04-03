<?php

declare(strict_types=1);

namespace app;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260403153531 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add display history columns to chat_history and migrate existing messages';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof SQLitePlatform) {
            $this->addSql("ALTER TABLE chat_history ADD COLUMN display_messages TEXT NOT NULL DEFAULT '[]'");
            $this->addSql('ALTER TABLE chat_history ADD COLUMN display_messages_count INTEGER NOT NULL DEFAULT 0');
        } else {
            $this->addSql('ALTER TABLE chat_history ADD COLUMN display_messages LONGTEXT NOT NULL');
            $this->addSql('ALTER TABLE chat_history ADD COLUMN display_messages_count INT NOT NULL DEFAULT 0');
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, messages FROM chat_history WHERE messages IS NOT NULL'
        );

        foreach ($rows as $row) {
            $messages = json_decode((string) $row['messages'], true, flags: JSON_THROW_ON_ERROR);

            if (isset($messages['llm'], $messages['display'])) {
                $llmMessages = json_encode($messages['llm'], JSON_THROW_ON_ERROR);
                $displayMessages = json_encode($messages['display'], JSON_THROW_ON_ERROR);
            } else {
                $llmMessages = json_encode($messages, JSON_THROW_ON_ERROR);
                $displayMessages = $llmMessages;
            }

            $this->addSql(
                'UPDATE chat_history SET messages = :messages, display_messages = :display_messages, display_messages_count = :display_messages_count WHERE id = :id',
                [
                    'messages' => $llmMessages,
                    'display_messages' => $displayMessages,
                    'display_messages_count' => count(json_decode($displayMessages, true, flags: JSON_THROW_ON_ERROR)),
                    'id' => $row['id'],
                ],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, display_messages FROM chat_history WHERE display_messages IS NOT NULL'
        );

        foreach ($rows as $row) {
            $displayMessages = json_decode(
                (string) $row['display_messages'],
                true,
                flags: JSON_THROW_ON_ERROR
            );

            $this->addSql(
                'UPDATE chat_history SET messages = :messages WHERE id = :id',
                [
                    'messages' => json_encode($displayMessages, JSON_THROW_ON_ERROR),
                    'id' => $row['id'],
                ],
            );
        }

        if ($platform instanceof SQLitePlatform) {
            $this->addSql('DROP INDEX IF EXISTS idx_thread_id');
            $this->addSql('DROP INDEX IF EXISTS idx_user_id');
            $this->addSql('DROP INDEX IF EXISTS uk_thread_id');
            $this->addSql('CREATE TABLE chat_history_tmp (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id TEXT NOT NULL, thread_id TEXT NOT NULL, messages TEXT NOT NULL, title TEXT, summary TEXT, created_at DATETIME DEFAULT (CURRENT_TIMESTAMP), updated_at DATETIME DEFAULT (CURRENT_TIMESTAMP), CONSTRAINT fk_chat_history_user FOREIGN KEY (user_id) REFERENCES user(id) ON DELETE CASCADE ON UPDATE NO ACTION)');
            $this->addSql('INSERT INTO chat_history_tmp (id, user_id, thread_id, messages, title, summary, created_at, updated_at) SELECT id, user_id, thread_id, messages, title, summary, created_at, updated_at FROM chat_history');
            $this->addSql('DROP TABLE chat_history');
            $this->addSql('ALTER TABLE chat_history_tmp RENAME TO chat_history');
            $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uk_thread_id ON chat_history(thread_id)');
            $this->addSql('CREATE INDEX IF NOT EXISTS idx_user_id ON chat_history(user_id)');
            $this->addSql('CREATE INDEX IF NOT EXISTS idx_thread_id ON chat_history(thread_id)');

            return;
        }

        $this->addSql('ALTER TABLE chat_history DROP COLUMN display_messages_count');
        $this->addSql('ALTER TABLE chat_history DROP COLUMN display_messages');
    }
}
