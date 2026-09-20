<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Converts gym.owner_id's plain index into a DB-level unique constraint
 * (mirrors referral_code.owner_id's existing UNIQ_6447454A7E3C61F9 index).
 *
 * Root-cause fix for an Owner's saved branding (logo/brand color) reverting
 * to defaults on reload: GymProvisioningService::ensureGymForOwner()'s
 * check-then-act (find-by-owner, then persist+flush if none found) could
 * race and silently create a second Gym row for the same Owner. Every read
 * site that uses GymRepository::findTheOnlyGym() (~25 of them — Dashboard,
 * PtSession, WorkoutSchedule, GymBrandingController::get(), etc.) relies on
 * the single-gym-product assumption (CLAUDE.md) that exactly one Gym row
 * exists globally and picks an arbitrary one via unscoped `findOneBy([])`
 * otherwise. Confirmed live: a duplicate, blank Gym row for the same Owner
 * caused GET /gym/branding to return the wrong (empty) row after PATCH had
 * correctly saved to the real one. GymProvisioningService now also catches
 * the resulting UniqueConstraintViolationException and re-fetches instead
 * of erroring, so the race is closed on both the DB and application sides.
 *
 * migrations:diff also picked up two unrelated pre-existing drift items
 * (member_sequence and a partial unique index on workout_assignment,
 * flagged the same way in Version20260919033650) which this migration
 * deliberately leaves out.
 */
final class Version20260919074810 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make gym.owner_id unique (prevents duplicate Gym rows per Owner)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_7f27dbed7e3c61f9');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_7F27DBED7E3C61F9 ON gym (owner_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_7F27DBED7E3C61F9');
        $this->addSql('CREATE INDEX idx_7f27dbed7e3c61f9 ON gym (owner_id)');
    }
}
