<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * roadmap Phase 16.2 (Phase 6 retrofit): `PtSession.branch_id`, backfilled
 * to each gym's primary branch — same "add nullable, backfill, constrain"
 * shape as every other Phase 16.2 migration (the naive `NOT NULL` with no
 * default would fail against a table with existing rows).
 */
final class Version20260814145938 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 16.2: PtSession.branch_id, backfilled to each gym\'s primary branch';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pt_session ADD branch_id UUID DEFAULT NULL');

        $this->addSql(<<<'SQL'
            UPDATE pt_session ps
            SET branch_id = b.id
            FROM branch b
            WHERE b.is_primary = true
            SQL);

        $this->addSql('ALTER TABLE pt_session ALTER COLUMN branch_id SET NOT NULL');
        $this->addSql('ALTER TABLE pt_session ADD CONSTRAINT FK_79A294F7DCD6CC49 FOREIGN KEY (branch_id) REFERENCES branch (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_79A294F7DCD6CC49 ON pt_session (branch_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pt_session DROP CONSTRAINT FK_79A294F7DCD6CC49');
        $this->addSql('DROP INDEX IDX_79A294F7DCD6CC49');
        $this->addSql('ALTER TABLE pt_session DROP branch_id');
    }
}
