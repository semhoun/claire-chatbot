<?php

declare(strict_types=1);

namespace Migrations;

use Doctrine\DBAL\Schema\Schema;

final class Version20260930000300 extends BaseMigration
{
    public function getDescription(): string
    {
        return 'Bind Telegram accepted generations and deduplicated stop updates';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE telegram_stop_target ('
            . 'id VARCHAR(64) NOT NULL PRIMARY KEY, bot_id VARCHAR(64) NOT NULL, '
            . 'update_id BIGINT NOT NULL, user_id VARCHAR(255) NOT NULL, thread_id VARCHAR(128) NOT NULL, '
            . 'chat_id VARCHAR(64) NOT NULL, topic_id BIGINT NOT NULL DEFAULT 0)');
        $this->addSql('CREATE INDEX idx_telegram_stop_target ON telegram_stop_target '
            . '(bot_id, user_id, chat_id, topic_id, update_id)');
        $this->addSql('CREATE TABLE telegram_stop_update ('
            . 'id VARCHAR(64) NOT NULL PRIMARY KEY, target_id VARCHAR(64) DEFAULT NULL, '
            . 'scope_id VARCHAR(64) NOT NULL, '
            . 'notice_claimed SMALLINT NOT NULL DEFAULT 0, created_at BIGINT NOT NULL)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE telegram_stop_update');
        $this->addSql('DROP TABLE telegram_stop_target');
    }
}
