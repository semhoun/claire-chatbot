<?php

declare(strict_types=1);

namespace Migrations;

use Doctrine\DBAL\Schema\Schema;

final class Version20260425151500 extends BaseMigration
{
    public function getDescription(): string
    {
        return 'Merge chat_history_file into file table and increase file_id length';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($this->isSqlitePlatform($platform)) {
            $this->addSql('ALTER TABLE file ADD COLUMN history_id BIGINT DEFAULT NULL REFERENCES chat_history(id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE file ADD COLUMN file_type VARCHAR(32) DEFAULT NULL');
            $this->addSql('ALTER TABLE file ADD COLUMN file_path VARCHAR(512) DEFAULT NULL');
            $this->addSql('ALTER TABLE file ADD COLUMN metadata CLOB NOT NULL DEFAULT "{}"');

            $this->addSql('INSERT INTO file (user_id, history_id, filename, mime_type, size_bytes, file_id, file_type, file_path, metadata, created_at)
                           SELECT user_id, history_id,
                           replace(file_path, rtrim(file_path, replace(file_path, "/", "")), ""),
                           CASE WHEN file_type = \'pdf\' THEN \'application/pdf\' ELSE \'image/png\' END,
                           0,
                           \'@@GENERATED@@\' || user_id || \'@\' || replace(file_path, rtrim(file_path, replace(file_path, "/", "")), "") || \'@@\',
                           file_type, file_path, metadata, created_at
                           FROM chat_history_file');

            $this->addSql('DROP TABLE chat_history_file');

            $this->addSql('UPDATE file SET file_path = file_id WHERE file_path IS NULL');
            $this->addSql('ALTER TABLE file CHANGE file_path file_path VARCHAR(512) NOT NULL;');

            return;
        }

        if ($this->isPostgreSqlPlatform($platform)) {
            $this->addSql('ALTER TABLE "file" ADD COLUMN history_id BIGINT DEFAULT NULL');
            $this->addSql('ALTER TABLE "file" ADD COLUMN file_type VARCHAR(32) DEFAULT NULL');
            $this->addSql('ALTER TABLE "file" ADD COLUMN file_path VARCHAR(512) DEFAULT NULL');
            $this->addSql('ALTER TABLE "file" ADD COLUMN metadata JSON NOT NULL DEFAULT \'{}\'');
            $this->addSql('ALTER TABLE "file" ADD CONSTRAINT fk_file_history FOREIGN KEY (history_id) REFERENCES chat_history(id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE "file" ALTER COLUMN file_id TYPE VARCHAR(255)');

            $this->addSql('INSERT INTO "file" (user_id, history_id, filename, mime_type, size_bytes, file_id, file_type, file_path, metadata, created_at)
                           SELECT user_id, history_id,
                           reverse(split_part(reverse(file_path), \'/\', 1)),
                           CASE WHEN file_type = \'pdf\' THEN \'application/pdf\' ELSE \'image/png\' END,
                           0,
                           \'@@GENERATED@@\' || user_id || \'@\' || reverse(split_part(reverse(file_path), \'/\', 1)) || \'@@\',
                           file_type, file_path, metadata, created_at
                           FROM chat_history_file');

            $this->addSql('DROP TABLE chat_history_file');

            $this->addSql('UPDATE file SET file_path = file_id WHERE file_path IS NULL');
            $this->addSql('ALTER TABLE file CHANGE file_path file_path VARCHAR(512) NOT NULL;');

            return;
        }

        // MySQL/MariaDB
        $this->addSql('ALTER TABLE `file` ADD COLUMN history_id BIGINT UNSIGNED DEFAULT NULL, ADD COLUMN file_type VARCHAR(32) DEFAULT NULL, ADD COLUMN file_path VARCHAR(512) DEFAULT NULL, ADD COLUMN metadata JSON NOT NULL DEFAULT \'{}\'');
        $this->addSql('ALTER TABLE `file` ADD CONSTRAINT fk_file_history FOREIGN KEY (history_id) REFERENCES chat_history(id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE `file` MODIFY file_id VARCHAR(255) NOT NULL');

        $this->addSql('INSERT INTO `file` (user_id, history_id, filename, mime_type, size_bytes, file_id, file_type, file_path, metadata, created_at)
                       SELECT user_id, history_id,
                       SUBSTRING_INDEX(file_path, \'/\', -1),
                       CASE WHEN file_type = \'pdf\' THEN \'application/pdf\' ELSE \'image/png\' END,
                       0,
                       CONCAT(\'@@GENERATED@@\', user_id, \'@\', SUBSTRING_INDEX(file_path, \'/\', -1), \'@@\'),
                       file_type, file_path, metadata, created_at
                       FROM chat_history_file');

        $this->addSql('DROP TABLE chat_history_file');

        $this->addSql('UPDATE file SET file_path = file_id WHERE file_path IS NULL');
        $this->addSql('ALTER TABLE file CHANGE file_path file_path VARCHAR(512) NOT NULL;');
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($this->isSqlitePlatform($platform)) {
            return;
        }

        if ($this->isPostgreSqlPlatform($platform)) {
            $this->addSql('ALTER TABLE "file" DROP COLUMN IF EXISTS history_id');
            $this->addSql('ALTER TABLE "file" DROP COLUMN IF EXISTS file_type');
            $this->addSql('ALTER TABLE "file" DROP COLUMN IF EXISTS file_path');
            $this->addSql('ALTER TABLE "file" DROP COLUMN IF EXISTS metadata');
            $this->addSql('ALTER TABLE "file" ALTER COLUMN file_id TYPE VARCHAR(36)');
            return;
        }

        $this->addSql('ALTER TABLE `file` DROP FOREIGN KEY fk_file_history');
        $this->addSql('ALTER TABLE `file` DROP COLUMN history_id, DROP COLUMN file_type, DROP COLUMN file_path, DROP COLUMN metadata');
        $this->addSql('ALTER TABLE `file` MODIFY file_id VARCHAR(36) NOT NULL');
    }
}
