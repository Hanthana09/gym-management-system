<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * roadmap Phase 16.2 (Phase 4 retrofit): `MembershipPlan.gym_id` →
 * `branch_id`. The auto-generated diff for this rename is wrong for a
 * migration that runs against real data — it renames the column in place
 * and repoints its FK straight at `branch(id)`, but the column still
 * holds old `gym_id` UUIDs, which don't exist as `branch.id` values.
 * Postgres validates existing rows against a new FK constraint by
 * default, so that version would fail outright on any DB with existing
 * plans. This version adds `branch_id` fresh, backfills it from each
 * plan's gym's primary branch (created by 16.1's own migration), then
 * drops the old column — same "backfill before constrain" shape as
 * 16.1's own Branch backfill.
 */
final class Version20260814112051 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 16.2: MembershipPlan.gym_id -> branch_id, backfilled via each gym\'s primary branch';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE membership_plan DROP CONSTRAINT fk_a6656eb6bd2f03');
        $this->addSql('DROP INDEX idx_a6656eb6bd2f03');
        $this->addSql('ALTER TABLE membership_plan ADD branch_id UUID DEFAULT NULL');

        $this->addSql(<<<'SQL'
            UPDATE membership_plan mp
            SET branch_id = b.id
            FROM branch b
            WHERE b.gym_id = mp.gym_id AND b.is_primary = true
            SQL);

        $this->addSql('ALTER TABLE membership_plan ALTER COLUMN branch_id SET NOT NULL');
        $this->addSql('ALTER TABLE membership_plan DROP COLUMN gym_id');
        $this->addSql('ALTER TABLE membership_plan ADD CONSTRAINT FK_A6656EB6DCD6CC49 FOREIGN KEY (branch_id) REFERENCES branch (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_A6656EB6DCD6CC49 ON membership_plan (branch_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE membership_plan DROP CONSTRAINT FK_A6656EB6DCD6CC49');
        $this->addSql('DROP INDEX IDX_A6656EB6DCD6CC49');
        $this->addSql('ALTER TABLE membership_plan ADD gym_id UUID DEFAULT NULL');

        $this->addSql(<<<'SQL'
            UPDATE membership_plan mp
            SET gym_id = b.gym_id
            FROM branch b
            WHERE b.id = mp.branch_id
            SQL);

        $this->addSql('ALTER TABLE membership_plan ALTER COLUMN gym_id SET NOT NULL');
        $this->addSql('ALTER TABLE membership_plan DROP COLUMN branch_id');
        $this->addSql('ALTER TABLE membership_plan ADD CONSTRAINT fk_a6656eb6bd2f03 FOREIGN KEY (gym_id) REFERENCES gym (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_a6656eb6bd2f03 ON membership_plan (gym_id)');
    }
}
