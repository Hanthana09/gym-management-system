<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260808162508 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE membership (id UUID NOT NULL, start_date DATE NOT NULL, end_date DATE NOT NULL, status VARCHAR(20) NOT NULL, auto_renew BOOLEAN NOT NULL, member_id UUID NOT NULL, plan_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_86FFD2857597D3FE ON membership (member_id)');
        $this->addSql('CREATE INDEX IDX_86FFD285E899029B ON membership (plan_id)');
        $this->addSql('CREATE TABLE membership_plan (id UUID NOT NULL, name VARCHAR(255) NOT NULL, price NUMERIC(8, 2) NOT NULL, duration_days INT NOT NULL, features JSON NOT NULL, gym_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_A6656EB6BD2F03 ON membership_plan (gym_id)');
        $this->addSql('ALTER TABLE membership ADD CONSTRAINT FK_86FFD2857597D3FE FOREIGN KEY (member_id) REFERENCES member_profile (user_id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE membership ADD CONSTRAINT FK_86FFD285E899029B FOREIGN KEY (plan_id) REFERENCES membership_plan (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE membership_plan ADD CONSTRAINT FK_A6656EB6BD2F03 FOREIGN KEY (gym_id) REFERENCES gym (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE membership DROP CONSTRAINT FK_86FFD2857597D3FE');
        $this->addSql('ALTER TABLE membership DROP CONSTRAINT FK_86FFD285E899029B');
        $this->addSql('ALTER TABLE membership_plan DROP CONSTRAINT FK_A6656EB6BD2F03');
        $this->addSql('DROP TABLE membership');
        $this->addSql('DROP TABLE membership_plan');
    }
}
