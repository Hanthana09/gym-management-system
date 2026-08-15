<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * roadmap Phase 16.1: Branch/BranchAssignment tables, plus the required
 * backfill — every pre-existing gym gets exactly one `is_primary` branch,
 * and every pre-existing Coach/Staff user is assigned to it. The Coach/
 * Staff backfill isn't in the roadmap's literal bullet list (which only
 * names the Branch backfill), but it's required by 16.2's own regression
 * requirement: once AttendanceVoter/MemberVoter/PtSessionVoter start
 * checking hasAssignedBranch(), any already-active Coach/Staff account
 * with no assignment would silently lose all visibility the moment this
 * ships — including in a gym that never adds a second branch. Backfilling
 * this now is the same "not optional scaffolding" reasoning the roadmap
 * already applies to the Branch row itself, extended one step further.
 */
final class Version20260814101322 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 16.1: Branch/BranchAssignment tables + backfill (one primary branch per gym, existing Coach/Staff assigned to it)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE branch (id UUID NOT NULL, name VARCHAR(255) NOT NULL, address VARCHAR(255) NOT NULL, phone VARCHAR(32) DEFAULT NULL, is_primary BOOLEAN DEFAULT false NOT NULL, status VARCHAR(20) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, gym_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_BB861B1FBD2F03 ON branch (gym_id)');
        $this->addSql('CREATE TABLE branch_assignment (id UUID NOT NULL, assigned_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, branch_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_2F1D4DE4A76ED395 ON branch_assignment (user_id)');
        $this->addSql('CREATE INDEX IDX_2F1D4DE4DCD6CC49 ON branch_assignment (branch_id)');
        $this->addSql('CREATE UNIQUE INDEX user_branch_unique ON branch_assignment (user_id, branch_id)');
        $this->addSql('ALTER TABLE branch ADD CONSTRAINT FK_BB861B1FBD2F03 FOREIGN KEY (gym_id) REFERENCES gym (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE branch_assignment ADD CONSTRAINT FK_2F1D4DE4A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE branch_assignment ADD CONSTRAINT FK_2F1D4DE4DCD6CC49 FOREIGN KEY (branch_id) REFERENCES branch (id) NOT DEFERRABLE');

        // Exactly one primary branch per existing gym — named after the gym
        // itself, matching GymProvisioningService::ensurePrimaryBranch()'s
        // behavior for any gym provisioned from this point forward, so a
        // pre-existing and a freshly-created gym end up looking identical.
        $this->addSql(<<<'SQL'
            INSERT INTO branch (id, gym_id, name, address, phone, is_primary, status, created_at)
            SELECT gen_random_uuid(), g.id, g.name, '', NULL, true, 'active', now()
            FROM gym g
            WHERE NOT EXISTS (SELECT 1 FROM branch b WHERE b.gym_id = g.id AND b.is_primary = true)
            SQL);

        // Single-gym product (CLAUDE.md) — every Coach/Staff user belongs to
        // "the" gym, so this assigns all of them to that one gym's one
        // primary branch (cross join is safe precisely because there is
        // only ever one gym/one primary branch in practice, same collapse
        // reasoning used throughout this codebase's Voters).
        $this->addSql(<<<'SQL'
            INSERT INTO branch_assignment (id, user_id, branch_id, assigned_at)
            SELECT gen_random_uuid(), u.id, b.id, now()
            FROM "user" u
            CROSS JOIN branch b
            WHERE u.role IN ('coach', 'staff') AND b.is_primary = true
            ON CONFLICT (user_id, branch_id) DO NOTHING
            SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE branch DROP CONSTRAINT FK_BB861B1FBD2F03');
        $this->addSql('ALTER TABLE branch_assignment DROP CONSTRAINT FK_2F1D4DE4A76ED395');
        $this->addSql('ALTER TABLE branch_assignment DROP CONSTRAINT FK_2F1D4DE4DCD6CC49');
        $this->addSql('DROP TABLE branch');
        $this->addSql('DROP TABLE branch_assignment');
    }
}
