<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * setly-phase-exercise-media.md: replaces the gym-scoped stopgap Exercise
 * (built ahead of this phase's real spec) with the platform-wide catalog
 * shape — no gym_id/created_by, adds source_id/slug/force/level/mechanic/
 * primary_muscles/secondary_muscles/category, instructions becomes a json
 * array, poster_image_url renamed to poster_image_path.
 *
 * TRUNCATEs `exercise` (cascading to workout_schedule_exercise/
 * exercise_log, which FK to it) before the ALTERs — dev-only test rows
 * from before this phase, and several of the new columns are NOT NULL
 * with no sensible default, which Postgres rejects via ALTER TABLE ADD
 * COLUMN against a non-empty table. The real catalog is (re-)populated by
 * `app:exercise:import` after this runs, not by this migration.
 *
 * The auto-generated diff also wanted to DROP INDEX
 * workout_assignment_active_pair_unique — an artifact of that index being
 * raw SQL (Version20260818094103.php) with no Doctrine ORM attribute
 * representation, so `doctrine:migrations:diff` sees it as "extra" versus
 * the entity mapping. Removed from both up() and down() here — this
 * migration has nothing to do with that index and must not touch it.
 */
final class Version20260818231240 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'setly-phase-exercise-media.md: replace gym-scoped Exercise with the platform-wide free-exercise-db catalog shape.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('TRUNCATE exercise CASCADE');
        $this->addSql('ALTER TABLE exercise DROP CONSTRAINT fk_aedad51cde12ab56');
        $this->addSql('ALTER TABLE exercise DROP CONSTRAINT fk_aedad51cbd2f03');
        $this->addSql('DROP INDEX idx_aedad51cde12ab56');
        $this->addSql('DROP INDEX idx_aedad51cbd2f03');
        $this->addSql('ALTER TABLE exercise ADD source_id VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE exercise ADD slug VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE exercise ADD force VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE exercise ADD level VARCHAR(20) NOT NULL');
        $this->addSql('ALTER TABLE exercise ADD mechanic VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE exercise ADD primary_muscles JSON NOT NULL');
        $this->addSql('ALTER TABLE exercise ADD secondary_muscles JSON NOT NULL');
        // length 32, not 20 — free-exercise-db's category enum includes "olympic weightlifting" (22 chars)
        $this->addSql('ALTER TABLE exercise ADD category VARCHAR(32) NOT NULL');
        $this->addSql('ALTER TABLE exercise ADD detail_image_paths JSON NOT NULL');
        $this->addSql('ALTER TABLE exercise ADD updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL');
        $this->addSql('ALTER TABLE exercise DROP muscle_group');
        $this->addSql('ALTER TABLE exercise DROP gym_id');
        $this->addSql('ALTER TABLE exercise DROP created_by');
        $this->addSql('ALTER TABLE exercise ALTER instructions TYPE JSON USING instructions::json');
        $this->addSql('ALTER TABLE exercise ALTER instructions SET NOT NULL');
        $this->addSql('ALTER TABLE exercise RENAME COLUMN poster_image_url TO poster_image_path');
        $this->addSql('CREATE UNIQUE INDEX exercise_source_id_unique ON exercise (source_id)');
    }

    public function down(Schema $schema): void
    {
        // Data loss from the up() TRUNCATE is not reversible — this only restores the old column shape.
        $this->addSql('DROP INDEX exercise_source_id_unique');
        $this->addSql('ALTER TABLE exercise ADD muscle_group VARCHAR(50) NOT NULL');
        $this->addSql('ALTER TABLE exercise ADD gym_id UUID NOT NULL');
        $this->addSql('ALTER TABLE exercise ADD created_by UUID NOT NULL');
        $this->addSql('ALTER TABLE exercise DROP source_id');
        $this->addSql('ALTER TABLE exercise DROP slug');
        $this->addSql('ALTER TABLE exercise DROP force');
        $this->addSql('ALTER TABLE exercise DROP level');
        $this->addSql('ALTER TABLE exercise DROP mechanic');
        $this->addSql('ALTER TABLE exercise DROP primary_muscles');
        $this->addSql('ALTER TABLE exercise DROP secondary_muscles');
        $this->addSql('ALTER TABLE exercise DROP category');
        $this->addSql('ALTER TABLE exercise DROP detail_image_paths');
        $this->addSql('ALTER TABLE exercise DROP updated_at');
        $this->addSql('ALTER TABLE exercise ALTER instructions TYPE TEXT');
        $this->addSql('ALTER TABLE exercise ALTER instructions DROP NOT NULL');
        $this->addSql('ALTER TABLE exercise RENAME COLUMN poster_image_path TO poster_image_url');
        $this->addSql(<<<'SQL'
            ALTER TABLE
              exercise
            ADD
              CONSTRAINT fk_aedad51cde12ab56 FOREIGN KEY (created_by) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              exercise
            ADD
              CONSTRAINT fk_aedad51cbd2f03 FOREIGN KEY (gym_id) REFERENCES gym (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql('CREATE INDEX idx_aedad51cde12ab56 ON exercise (created_by)');
        $this->addSql('CREATE INDEX idx_aedad51cbd2f03 ON exercise (gym_id)');
    }
}
