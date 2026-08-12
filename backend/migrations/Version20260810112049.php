<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260810112049 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE referral_code (id UUID NOT NULL, code VARCHAR(32) NOT NULL, usage_count INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, owner_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_6447454A77153098 ON referral_code (code)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_6447454A7E3C61F9 ON referral_code (owner_id)');
        $this->addSql('CREATE TABLE referral_lead (id UUID NOT NULL, prospect_gym_name VARCHAR(255) NOT NULL, contact_name VARCHAR(255) DEFAULT NULL, contact_email VARCHAR(255) DEFAULT NULL, contact_phone VARCHAR(32) DEFAULT NULL, status VARCHAR(20) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, referred_by UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_3BC314198C0C9F8A ON referral_lead (referred_by)');
        $this->addSql('ALTER TABLE referral_code ADD CONSTRAINT FK_6447454A7E3C61F9 FOREIGN KEY (owner_id) REFERENCES "user" (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE referral_lead ADD CONSTRAINT FK_3BC314198C0C9F8A FOREIGN KEY (referred_by) REFERENCES "user" (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE referral_code DROP CONSTRAINT FK_6447454A7E3C61F9');
        $this->addSql('ALTER TABLE referral_lead DROP CONSTRAINT FK_3BC314198C0C9F8A');
        $this->addSql('DROP TABLE referral_code');
        $this->addSql('DROP TABLE referral_lead');
    }
}
