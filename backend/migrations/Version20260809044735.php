<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260809044735 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE body_metric (id UUID NOT NULL, date DATE NOT NULL, weight_kg NUMERIC(5, 2) NOT NULL, body_fat_pct NUMERIC(4, 1) DEFAULT NULL, member_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_12CA8DE57597D3FE ON body_metric (member_id)');
        $this->addSql('CREATE TABLE workout_log (id UUID NOT NULL, date DATE NOT NULL, type VARCHAR(255) NOT NULL, duration_minutes INT NOT NULL, metrics JSON NOT NULL, member_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_6F5B68D7597D3FE ON workout_log (member_id)');
        $this->addSql('ALTER TABLE body_metric ADD CONSTRAINT FK_12CA8DE57597D3FE FOREIGN KEY (member_id) REFERENCES member_profile (user_id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE workout_log ADD CONSTRAINT FK_6F5B68D7597D3FE FOREIGN KEY (member_id) REFERENCES member_profile (user_id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE body_metric DROP CONSTRAINT FK_12CA8DE57597D3FE');
        $this->addSql('ALTER TABLE workout_log DROP CONSTRAINT FK_6F5B68D7597D3FE');
        $this->addSql('DROP TABLE body_metric');
        $this->addSql('DROP TABLE workout_log');
    }
}
