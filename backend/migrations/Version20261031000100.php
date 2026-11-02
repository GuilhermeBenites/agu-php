<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261031000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add transactional per-owner task cache generation without changing API responses';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE users ADD task_cache_version VARCHAR(32) DEFAULT '00000000000000000000000000000000' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP task_cache_version');
    }
}
