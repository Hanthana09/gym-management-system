<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds MemberProfile::$assignedBranch — an Owner-set "home branch" tag
 * for a Member (BranchController's new member-assign endpoints), separate
 * from BranchAssignment (Coach/Staff access-scoping) and purely
 * informational, same as the pre-existing membership-plan-derived
 * enrolling branch it now takes priority over in the roster response.
 *
 * migrations:diff also picked up two unrelated pre-existing drift items
 * (member_sequence — a plain, non-entity-mapped counter table
 * MemberIdGenerator manages via raw SQL — and a partial unique index on
 * workout_assignment) neither of which this change touches; both were
 * left out of this migration deliberately.
 */
final class Version20260919033650 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Add MemberProfile.assignedBranch (Owner-set home branch tag for a Member)";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE member_profile ADD assigned_branch_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE member_profile ADD CONSTRAINT FK_3EA31D9BA57779EB FOREIGN KEY (assigned_branch_id) REFERENCES branch (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_3EA31D9BA57779EB ON member_profile (assigned_branch_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE member_profile DROP CONSTRAINT FK_3EA31D9BA57779EB');
        $this->addSql('DROP INDEX IDX_3EA31D9BA57779EB');
        $this->addSql('ALTER TABLE member_profile DROP assigned_branch_id');
    }
}
