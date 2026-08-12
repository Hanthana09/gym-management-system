<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260811074959 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE daily_metric_snapshot (id UUID NOT NULL, snapshot_date DATE NOT NULL, checkins_count INT NOT NULL, active_members_count INT NOT NULL, new_members_count INT NOT NULL, cancelled_members_count INT NOT NULL, revenue NUMERIC(10, 2) NOT NULL, at_risk_members_count INT NOT NULL, gym_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_8644BDEEBD2F03 ON daily_metric_snapshot (gym_id)');
        $this->addSql('CREATE UNIQUE INDEX gym_snapshot_date_unique ON daily_metric_snapshot (gym_id, snapshot_date)');
        $this->addSql('ALTER TABLE daily_metric_snapshot ADD CONSTRAINT FK_8644BDEEBD2F03 FOREIGN KEY (gym_id) REFERENCES gym (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE membership ADD cancelled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE referral_code ALTER credits_available DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE daily_metric_snapshot DROP CONSTRAINT FK_8644BDEEBD2F03');
        $this->addSql('DROP TABLE daily_metric_snapshot');
        $this->addSql('ALTER TABLE membership DROP cancelled_at');
        $this->addSql('ALTER TABLE referral_code ALTER credits_available SET DEFAULT 0');
    }
}
