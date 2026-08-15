<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * roadmap Phase 16.2 (Phase 7 retrofit): `Announcement.branch_id`,
 * nullable — unlike every other Phase 16.2 migration, no backfill is
 * needed: NULL is itself the correct, meaningful value for every
 * pre-existing row ("gym-wide," the only option that existed before this
 * phase), not a placeholder standing in for missing data.
 */
final class Version20260814150903 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 16.2: Announcement.branch_id (nullable — null means gym-wide)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE announcement ADD branch_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE announcement ADD CONSTRAINT FK_4DB9D91CDCD6CC49 FOREIGN KEY (branch_id) REFERENCES branch (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_4DB9D91CDCD6CC49 ON announcement (branch_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE announcement DROP CONSTRAINT FK_4DB9D91CDCD6CC49');
        $this->addSql('DROP INDEX IDX_4DB9D91CDCD6CC49');
        $this->addSql('ALTER TABLE announcement DROP branch_id');
    }
}
