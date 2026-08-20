<?php

declare(strict_types=1);

namespace Migrations;

use Doctrine\DBAL\Schema\Schema;

final class Version20260820110000 extends BaseMigration
{
    public function getDescription(): string
    {
        return 'Create rag_document table';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($this->isSqlitePlatform($platform)) {
            $this->addSql(
                'CREATE TABLE rag_document ('
                . 'id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, '
                . 'user_id TEXT NOT NULL, '
                . 'document_id VARCHAR(64) NOT NULL UNIQUE, '
                . 'name VARCHAR(255) NOT NULL, '
                . 'source_type VARCHAR(32) NOT NULL, '
                . 'source_id VARCHAR(255) DEFAULT NULL, '
                . 'is_active BOOLEAN NOT NULL DEFAULT 1, '
                . 'chunk_count INTEGER NOT NULL DEFAULT 0, '
                . 'created_at DATETIME NOT NULL, '
                . 'updated_at DATETIME NOT NULL, '
                . 'CONSTRAINT fk_rag_document_user FOREIGN KEY (user_id) '
                . 'REFERENCES account(id) ON DELETE CASCADE)'
            );
            $this->addSql('CREATE INDEX idx_rag_user_id ON rag_document (user_id)');
            $this->addSql('CREATE INDEX idx_rag_active ON rag_document (user_id, is_active)');
            return;
        }

        if ($this->isPostgreSqlPlatform($platform)) {
            $this->addSql(
                'CREATE TABLE rag_document ('
                . 'id BIGSERIAL PRIMARY KEY NOT NULL, '
                . 'user_id VARCHAR(64) NOT NULL, '
                . 'document_id VARCHAR(64) NOT NULL UNIQUE, '
                . 'name VARCHAR(255) NOT NULL, '
                . 'source_type VARCHAR(32) NOT NULL, '
                . 'source_id VARCHAR(255) DEFAULT NULL, '
                . 'is_active BOOLEAN NOT NULL DEFAULT true, '
                . 'chunk_count INTEGER NOT NULL DEFAULT 0, '
                . 'created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, '
                . 'updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, '
                . 'CONSTRAINT fk_rag_document_user FOREIGN KEY (user_id) '
                . 'REFERENCES account(id) ON DELETE CASCADE)'
            );
            $this->addSql('CREATE INDEX idx_rag_user_id ON rag_document (user_id)');
            $this->addSql('CREATE INDEX idx_rag_active ON rag_document (user_id, is_active)');
            return;
        }

        $this->addSql(
            'CREATE TABLE rag_document ('
            . 'id BIGINT AUTO_INCREMENT PRIMARY KEY NOT NULL, '
            . 'user_id VARCHAR(64) NOT NULL, '
            . 'document_id VARCHAR(64) NOT NULL UNIQUE, '
            . 'name VARCHAR(255) NOT NULL, '
            . 'source_type VARCHAR(32) NOT NULL, '
            . 'source_id VARCHAR(255) DEFAULT NULL, '
            . 'is_active TINYINT(1) NOT NULL DEFAULT 1, '
            . 'chunk_count INT NOT NULL DEFAULT 0, '
            . 'created_at DATETIME NOT NULL, '
            . 'updated_at DATETIME NOT NULL, '
            . 'CONSTRAINT fk_rag_document_user FOREIGN KEY (user_id) '
            . 'REFERENCES account(id) ON DELETE CASCADE)'
        );
        $this->addSql('CREATE INDEX idx_rag_user_id ON rag_document (user_id)');
        $this->addSql('CREATE INDEX idx_rag_active ON rag_document (user_id, is_active)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS rag_document');
    }
}
