<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * roadmap Phase 16.2 (Phase 11 retrofit): `DailyMetricSnapshot.branch_id`,
 * nullable — no backfill needed, same reasoning as the Announcement
 * migration: every pre-existing row was already the gym-wide rollup (the
 * only kind that existed before this phase), so NULL is the correct value
 * for it, not a placeholder. The unique constraint widens to include
 * branch_id so a per-branch row and the gym-wide row for the same date
 * can coexist.
 */
final class Version20260814154702 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 16.2: DailyMetricSnapshot.branch_id (nullable — null means the gym-wide rollup)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX gym_snapshot_date_unique');
        $this->addSql('ALTER TABLE daily_metric_snapshot ADD branch_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE daily_metric_snapshot ADD CONSTRAINT FK_8644BDEEDCD6CC49 FOREIGN KEY (branch_id) REFERENCES branch (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_8644BDEEDCD6CC49 ON daily_metric_snapshot (branch_id)');
        $this->addSql('CREATE UNIQUE INDEX gym_snapshot_date_unique ON daily_metric_snapshot (gym_id, snapshot_date, branch_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE daily_metric_snapshot DROP CONSTRAINT FK_8644BDEEDCD6CC49');
        $this->addSql('DROP INDEX IDX_8644BDEEDCD6CC49');
        $this->addSql('DROP INDEX gym_snapshot_date_unique');
        $this->addSql('ALTER TABLE daily_metric_snapshot DROP branch_id');
        $this->addSql('CREATE UNIQUE INDEX gym_snapshot_date_unique ON daily_metric_snapshot (gym_id, snapshot_date)');
    }
}
