<?php

declare(strict_types=1);

namespace Migrations;

use Doctrine\DBAL\Schema\Schema;

final class Version20260418221334 extends BaseMigration
{
    public function getDescription(): string
    {
        return 'Create chat_history_file table for generated images';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($this->isSqlitePlatform($platform)) {
            $this->addSql('CREATE TABLE chat_history_file (id INTEGER PRIMARY KEY AUTOINCREMENT, history_id BIGINT NOT NULL, user_id TEXT NOT NULL, file_type VARCHAR(32) NOT NULL, file_path VARCHAR(512) NOT NULL, metadata CLOB NOT NULL, created_at DATETIME NOT NULL, CONSTRAINT fk_chf_history FOREIGN KEY (history_id) REFERENCES chat_history(id) ON DELETE CASCADE ON UPDATE NO ACTION, CONSTRAINT fk_chf_user FOREIGN KEY (user_id) REFERENCES account(id) ON DELETE CASCADE ON UPDATE NO ACTION)');
            $this->addSql('CREATE INDEX idx_chf_history ON chat_history_file(history_id)');
            $this->addSql('CREATE INDEX idx_chf_user ON chat_history_file(user_id)');

            return;
        }

        if ($this->isPostgreSqlPlatform($platform)) {
            $this->addSql('CREATE TABLE chat_history_file (id BIGSERIAL PRIMARY KEY, history_id BIGINT NOT NULL, user_id VARCHAR(36) NOT NULL, file_type VARCHAR(32) NOT NULL, file_path VARCHAR(512) NOT NULL, metadata JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL)');
            $this->addSql('CREATE INDEX idx_chf_history ON chat_history_file(history_id)');
            $this->addSql('CREATE INDEX idx_chf_user ON chat_history_file(user_id)');
            $this->addSql('ALTER TABLE chat_history_file ADD CONSTRAINT fk_chf_history FOREIGN KEY (history_id) REFERENCES chat_history(id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE chat_history_file ADD CONSTRAINT fk_chf_user FOREIGN KEY (user_id) REFERENCES account(id) ON DELETE CASCADE');

            return;
        }

        // MySQL/MariaDB
        $this->addSql('CREATE TABLE chat_history_file (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, history_id BIGINT UNSIGNED NOT NULL, user_id VARCHAR(36) NOT NULL, file_type VARCHAR(32) NOT NULL, file_path VARCHAR(512) NOT NULL, metadata JSON NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_chf_history (history_id), INDEX idx_chf_user (user_id), CONSTRAINT fk_chf_history FOREIGN KEY (history_id) REFERENCES chat_history(id) ON DELETE CASCADE, CONSTRAINT fk_chf_user FOREIGN KEY (user_id) REFERENCES account(id) ON DELETE CASCADE)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS chat_history_file');
    }
}
