<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * roadmap Phase 16.2 (Phase 5 retrofit): `AttendanceLog.branch_id`.
 * There's no way to know which physical branch a historical check-in
 * actually happened at (branches didn't exist yet) — every existing row
 * backfills to its gym's primary branch, the same "primary branch is the
 * honest historical default" choice 16.1's own migration and every other
 * Phase 16.2 backfill makes. Same "add nullable, backfill, then
 * constrain" shape as the MembershipPlan migration — the auto-generated
 * `ADD branch_id UUID NOT NULL` with no default would fail outright
 * against a table with existing rows.
 */
final class Version20260814143615 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 16.2: AttendanceLog.branch_id, backfilled to each gym\'s primary branch';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE attendance_log ADD branch_id UUID DEFAULT NULL');

        $this->addSql(<<<'SQL'
            UPDATE attendance_log al
            SET branch_id = b.id
            FROM branch b
            WHERE b.is_primary = true
            SQL);

        $this->addSql('ALTER TABLE attendance_log ALTER COLUMN branch_id SET NOT NULL');
        $this->addSql('ALTER TABLE attendance_log ADD CONSTRAINT FK_8920D967DCD6CC49 FOREIGN KEY (branch_id) REFERENCES branch (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_8920D967DCD6CC49 ON attendance_log (branch_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE attendance_log DROP CONSTRAINT FK_8920D967DCD6CC49');
        $this->addSql('DROP INDEX IDX_8920D967DCD6CC49');
        $this->addSql('ALTER TABLE attendance_log DROP branch_id');
    }
}
