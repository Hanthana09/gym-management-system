<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * setly-phase-exercise-media.md + setly-phase-workout-scheduling.md:
 * exercise, workout_schedule, workout_schedule_exercise, workout_assignment,
 * exercise_log tables. Auto-generated via doctrine:migrations:diff against
 * the new entities, then hand-edited to add the one thing Doctrine's
 * migration DSL can't express: a partial unique index on
 * workout_assignment(coach_id, member_id) WHERE status = 'active' —
 * workout-scheduling doc §3/§4's real concurrency safety net, not just
 * application-level checking.
 */
final class Version20260818094103 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Exercise media + workout scheduling & assignment: exercise, workout_schedule, workout_schedule_exercise, workout_assignment, exercise_log tables, plus the partial unique index on workout_assignment(coach_id, member_id) WHERE status = \'active\'.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE TABLE exercise (
              id UUID NOT NULL,
              name VARCHAR(255) NOT NULL,
              muscle_group VARCHAR(50) NOT NULL,
              equipment VARCHAR(50) DEFAULT NULL,
              instructions TEXT DEFAULT NULL,
              poster_image_url VARCHAR(255) DEFAULT NULL,
              created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
              gym_id UUID NOT NULL,
              created_by UUID NOT NULL,
              PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_AEDAD51CBD2F03 ON exercise (gym_id)');
        $this->addSql('CREATE INDEX IDX_AEDAD51CDE12AB56 ON exercise (created_by)');
        $this->addSql(<<<'SQL'
            CREATE TABLE exercise_log (
              id UUID NOT NULL,
              logged_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
              sets_completed INT NOT NULL,
              reps_completed INT NOT NULL,
              weight NUMERIC(6, 2) DEFAULT NULL,
              notes TEXT DEFAULT NULL,
              assignment_id UUID NOT NULL,
              exercise_id UUID NOT NULL,
              member_id UUID NOT NULL,
              PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_1960CDB9D19302F8 ON exercise_log (assignment_id)');
        $this->addSql('CREATE INDEX IDX_1960CDB9E934951A ON exercise_log (exercise_id)');
        $this->addSql('CREATE INDEX IDX_1960CDB97597D3FE ON exercise_log (member_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE workout_assignment (
              id UUID NOT NULL,
              status VARCHAR(20) NOT NULL,
              start_date DATE NOT NULL,
              assigned_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
              schedule_id UUID NOT NULL,
              member_id UUID NOT NULL,
              coach_id UUID NOT NULL,
              PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_5CE10B30A40BC2D5 ON workout_assignment (schedule_id)');
        $this->addSql('CREATE INDEX IDX_5CE10B307597D3FE ON workout_assignment (member_id)');
        $this->addSql('CREATE INDEX IDX_5CE10B303C105691 ON workout_assignment (coach_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE workout_schedule (
              id UUID NOT NULL,
              name VARCHAR(255) NOT NULL,
              type VARCHAR(50) NOT NULL,
              status VARCHAR(20) NOT NULL,
              created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
              updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
              gym_id UUID NOT NULL,
              coach_id UUID NOT NULL,
              PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_8249FC4DBD2F03 ON workout_schedule (gym_id)');
        $this->addSql('CREATE INDEX IDX_8249FC4D3C105691 ON workout_schedule (coach_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE workout_schedule_exercise (
              id UUID NOT NULL,
              day_number INT NOT NULL,
              "order" INT NOT NULL,
              sets INT NOT NULL,
              reps INT NOT NULL,
              rest_seconds INT DEFAULT NULL,
              notes TEXT DEFAULT NULL,
              schedule_id UUID NOT NULL,
              exercise_id UUID NOT NULL,
              PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_85E8F050A40BC2D5 ON workout_schedule_exercise (schedule_id)');
        $this->addSql('CREATE INDEX IDX_85E8F050E934951A ON workout_schedule_exercise (exercise_id)');
        $this->addSql(<<<'SQL'
            CREATE INDEX workout_schedule_exercise_schedule_exercise_idx ON workout_schedule_exercise (schedule_id, exercise_id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              exercise
            ADD
              CONSTRAINT FK_AEDAD51CBD2F03 FOREIGN KEY (gym_id) REFERENCES gym (id) NOT DEFERRABLE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              exercise
            ADD
              CONSTRAINT FK_AEDAD51CDE12AB56 FOREIGN KEY (created_by) REFERENCES "user" (id) NOT DEFERRABLE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              exercise_log
            ADD
              CONSTRAINT FK_1960CDB9D19302F8 FOREIGN KEY (assignment_id) REFERENCES workout_assignment (id) NOT DEFERRABLE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              exercise_log
            ADD
              CONSTRAINT FK_1960CDB9E934951A FOREIGN KEY (exercise_id) REFERENCES exercise (id) NOT DEFERRABLE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              exercise_log
            ADD
              CONSTRAINT FK_1960CDB97597D3FE FOREIGN KEY (member_id) REFERENCES "user" (id) NOT DEFERRABLE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              workout_assignment
            ADD
              CONSTRAINT FK_5CE10B30A40BC2D5 FOREIGN KEY (schedule_id) REFERENCES workout_schedule (id) NOT DEFERRABLE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              workout_assignment
            ADD
              CONSTRAINT FK_5CE10B307597D3FE FOREIGN KEY (member_id) REFERENCES "user" (id) NOT DEFERRABLE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              workout_assignment
            ADD
              CONSTRAINT FK_5CE10B303C105691 FOREIGN KEY (coach_id) REFERENCES "user" (id) NOT DEFERRABLE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              workout_schedule
            ADD
              CONSTRAINT FK_8249FC4DBD2F03 FOREIGN KEY (gym_id) REFERENCES gym (id) NOT DEFERRABLE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              workout_schedule
            ADD
              CONSTRAINT FK_8249FC4D3C105691 FOREIGN KEY (coach_id) REFERENCES "user" (id) NOT DEFERRABLE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              workout_schedule_exercise
            ADD
              CONSTRAINT FK_85E8F050A40BC2D5 FOREIGN KEY (schedule_id) REFERENCES workout_schedule (id) ON DELETE CASCADE NOT DEFERRABLE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              workout_schedule_exercise
            ADD
              CONSTRAINT FK_85E8F050E934951A FOREIGN KEY (exercise_id) REFERENCES exercise (id) NOT DEFERRABLE
        SQL);

        // setly-phase-workout-scheduling.md §3's real safety net — even if
        // two requests race, the DB rejects the second `active` row for
        // the same coach-member pair (WorkoutAssignmentService::assign()
        // catches the resulting UniqueConstraintViolationException as a
        // 409, per §4). Doctrine's migration DSL has no partial-index
        // construct, hence raw SQL here.
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX workout_assignment_active_pair_unique ON workout_assignment (coach_id, member_id) WHERE (status = 'active')
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX workout_assignment_active_pair_unique');
        $this->addSql('ALTER TABLE exercise DROP CONSTRAINT FK_AEDAD51CBD2F03');
        $this->addSql('ALTER TABLE exercise DROP CONSTRAINT FK_AEDAD51CDE12AB56');
        $this->addSql('ALTER TABLE exercise_log DROP CONSTRAINT FK_1960CDB9D19302F8');
        $this->addSql('ALTER TABLE exercise_log DROP CONSTRAINT FK_1960CDB9E934951A');
        $this->addSql('ALTER TABLE exercise_log DROP CONSTRAINT FK_1960CDB97597D3FE');
        $this->addSql('ALTER TABLE workout_assignment DROP CONSTRAINT FK_5CE10B30A40BC2D5');
        $this->addSql('ALTER TABLE workout_assignment DROP CONSTRAINT FK_5CE10B307597D3FE');
        $this->addSql('ALTER TABLE workout_assignment DROP CONSTRAINT FK_5CE10B303C105691');
        $this->addSql('ALTER TABLE workout_schedule DROP CONSTRAINT FK_8249FC4DBD2F03');
        $this->addSql('ALTER TABLE workout_schedule DROP CONSTRAINT FK_8249FC4D3C105691');
        $this->addSql('ALTER TABLE workout_schedule_exercise DROP CONSTRAINT FK_85E8F050A40BC2D5');
        $this->addSql('ALTER TABLE workout_schedule_exercise DROP CONSTRAINT FK_85E8F050E934951A');
        $this->addSql('DROP TABLE exercise');
        $this->addSql('DROP TABLE exercise_log');
        $this->addSql('DROP TABLE workout_assignment');
        $this->addSql('DROP TABLE workout_schedule');
        $this->addSql('DROP TABLE workout_schedule_exercise');
    }
}
