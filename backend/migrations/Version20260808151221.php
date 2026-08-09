<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260808151221 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE coach_profile (specialty VARCHAR(255) DEFAULT NULL, bio TEXT DEFAULT NULL, hourly_rate NUMERIC(8, 2) DEFAULT NULL, user_id UUID NOT NULL, PRIMARY KEY (user_id))');
        $this->addSql('CREATE TABLE gym (id UUID NOT NULL, name VARCHAR(255) NOT NULL, address VARCHAR(255) NOT NULL, owner_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_7F27DBED7E3C61F9 ON gym (owner_id)');
        $this->addSql('CREATE TABLE invitation (id UUID NOT NULL, email VARCHAR(255) DEFAULT NULL, phone VARCHAR(32) DEFAULT NULL, role VARCHAR(20) NOT NULL, status VARCHAR(20) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, responded_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, gym_id UUID NOT NULL, invited_by UUID NOT NULL, user_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_F11D61A2BD2F03 ON invitation (gym_id)');
        $this->addSql('CREATE INDEX IDX_F11D61A2421FF255 ON invitation (invited_by)');
        $this->addSql('CREATE INDEX IDX_F11D61A2A76ED395 ON invitation (user_id)');
        $this->addSql('CREATE TABLE member_profile (date_of_birth DATE DEFAULT NULL, emergency_contact VARCHAR(255) DEFAULT NULL, health_notes TEXT DEFAULT NULL, goals TEXT DEFAULT NULL, user_id UUID NOT NULL, PRIMARY KEY (user_id))');
        $this->addSql('ALTER TABLE coach_profile ADD CONSTRAINT FK_3A874247A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE gym ADD CONSTRAINT FK_7F27DBED7E3C61F9 FOREIGN KEY (owner_id) REFERENCES "user" (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE invitation ADD CONSTRAINT FK_F11D61A2BD2F03 FOREIGN KEY (gym_id) REFERENCES gym (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE invitation ADD CONSTRAINT FK_F11D61A2421FF255 FOREIGN KEY (invited_by) REFERENCES "user" (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE invitation ADD CONSTRAINT FK_F11D61A2A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE member_profile ADD CONSTRAINT FK_3EA31D9BA76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE "user" ALTER email DROP NOT NULL');
        $this->addSql('ALTER TABLE "user" ALTER phone DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE coach_profile DROP CONSTRAINT FK_3A874247A76ED395');
        $this->addSql('ALTER TABLE gym DROP CONSTRAINT FK_7F27DBED7E3C61F9');
        $this->addSql('ALTER TABLE invitation DROP CONSTRAINT FK_F11D61A2BD2F03');
        $this->addSql('ALTER TABLE invitation DROP CONSTRAINT FK_F11D61A2421FF255');
        $this->addSql('ALTER TABLE invitation DROP CONSTRAINT FK_F11D61A2A76ED395');
        $this->addSql('ALTER TABLE member_profile DROP CONSTRAINT FK_3EA31D9BA76ED395');
        $this->addSql('DROP TABLE coach_profile');
        $this->addSql('DROP TABLE gym');
        $this->addSql('DROP TABLE invitation');
        $this->addSql('DROP TABLE member_profile');
        $this->addSql('ALTER TABLE "user" ALTER email SET NOT NULL');
        $this->addSql('ALTER TABLE "user" ALTER phone SET NOT NULL');
    }
}
