<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260809014633 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE pt_session (id UUID NOT NULL, scheduled_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, duration_minutes INT NOT NULL, status VARCHAR(20) NOT NULL, notes TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, coach_id UUID NOT NULL, member_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_79A294F73C105691 ON pt_session (coach_id)');
        $this->addSql('CREATE INDEX IDX_79A294F77597D3FE ON pt_session (member_id)');
        $this->addSql('ALTER TABLE pt_session ADD CONSTRAINT FK_79A294F73C105691 FOREIGN KEY (coach_id) REFERENCES coach_profile (user_id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE pt_session ADD CONSTRAINT FK_79A294F77597D3FE FOREIGN KEY (member_id) REFERENCES member_profile (user_id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE pt_session DROP CONSTRAINT FK_79A294F73C105691');
        $this->addSql('ALTER TABLE pt_session DROP CONSTRAINT FK_79A294F77597D3FE');
        $this->addSql('DROP TABLE pt_session');
    }
}
