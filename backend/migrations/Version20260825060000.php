<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * gym-management-billing-v1.md — recurring billing + check-in gating,
 * merged into the existing Membership/Invoice entities per this phase's
 * plan (extend in place rather than a parallel MemberSubscription/Invoice
 * system): membership.billing_anchor_day/next_billing_date, invoice.
 * period_start/period_end/due_date/marked_absent_at/marked_absent_by
 * (all nullable, no backfill — existing rows are simply not on the
 * recurring engine), a unique (membership_id, period_start) index that
 * makes invoice generation idempotent, and the new payment table.
 *
 * The auto-generated diff also wanted to DROP TABLE member_sequence and
 * DROP INDEX workout_assignment_active_pair_unique — both are the same
 * pre-existing raw-SQL artifacts with no Doctrine ORM mapping already
 * called out in several earlier migrations' docblocks (e.g.
 * Version20260822033146.php). Removed from both up() and down() here —
 * unrelated to this migration.
 */
final class Version20260825060000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'gym-management-billing-v1.md: recurring billing fields on Membership/Invoice + new Payment table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE payment (id UUID NOT NULL, amount NUMERIC(8, 2) NOT NULL, method VARCHAR(20) NOT NULL, paid_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, reset_billing_cycle BOOLEAN NOT NULL, note TEXT DEFAULT NULL, invoice_id UUID NOT NULL, recorded_by UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_6D28840D2989F1FD ON payment (invoice_id)');
        $this->addSql('CREATE INDEX IDX_6D28840D82D4278B ON payment (recorded_by)');
        $this->addSql('ALTER TABLE payment ADD CONSTRAINT FK_6D28840D2989F1FD FOREIGN KEY (invoice_id) REFERENCES invoice (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE payment ADD CONSTRAINT FK_6D28840D82D4278B FOREIGN KEY (recorded_by) REFERENCES "user" (id) NOT DEFERRABLE');

        $this->addSql('ALTER TABLE invoice ADD period_start DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE invoice ADD period_end DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE invoice ADD due_date DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE invoice ADD marked_absent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE invoice ADD marked_absent_by UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE invoice ADD CONSTRAINT FK_906517445A6F1A5B FOREIGN KEY (marked_absent_by) REFERENCES "user" (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_906517445A6F1A5B ON invoice (marked_absent_by)');
        // Postgres treats NULL as distinct in a unique index, so every
        // existing one-time invoice (period_start NULL) is unaffected —
        // this is what makes InvoiceGenerationService idempotent.
        $this->addSql('CREATE UNIQUE INDEX invoice_membership_period_start_unique ON invoice (membership_id, period_start)');

        $this->addSql('ALTER TABLE membership ADD billing_anchor_day INT DEFAULT NULL');
        $this->addSql('ALTER TABLE membership ADD next_billing_date DATE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE payment DROP CONSTRAINT FK_6D28840D2989F1FD');
        $this->addSql('ALTER TABLE payment DROP CONSTRAINT FK_6D28840D82D4278B');
        $this->addSql('DROP TABLE payment');

        $this->addSql('ALTER TABLE invoice DROP CONSTRAINT FK_906517445A6F1A5B');
        $this->addSql('DROP INDEX IDX_906517445A6F1A5B');
        $this->addSql('DROP INDEX invoice_membership_period_start_unique');
        $this->addSql('ALTER TABLE invoice DROP period_start');
        $this->addSql('ALTER TABLE invoice DROP period_end');
        $this->addSql('ALTER TABLE invoice DROP due_date');
        $this->addSql('ALTER TABLE invoice DROP marked_absent_at');
        $this->addSql('ALTER TABLE invoice DROP marked_absent_by');

        $this->addSql('ALTER TABLE membership DROP billing_anchor_day');
        $this->addSql('ALTER TABLE membership DROP next_billing_date');
    }
}
