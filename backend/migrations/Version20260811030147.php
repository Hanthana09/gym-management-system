<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260811030147 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE audit_log (id UUID NOT NULL, action VARCHAR(100) NOT NULL, entity_type VARCHAR(100) NOT NULL, entity_id UUID NOT NULL, metadata JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, actor_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_F6E1C0F510DAF24A ON audit_log (actor_id)');
        $this->addSql('CREATE TABLE invoice (id UUID NOT NULL, amount NUMERIC(8, 2) NOT NULL, status VARCHAR(20) NOT NULL, payment_method VARCHAR(20) DEFAULT NULL, issued_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, paid_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, membership_id UUID NOT NULL, recorded_by UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_906517441FB354CD ON invoice (membership_id)');
        $this->addSql('CREATE INDEX IDX_9065174482D4278B ON invoice (recorded_by)');
        $this->addSql('ALTER TABLE audit_log ADD CONSTRAINT FK_F6E1C0F510DAF24A FOREIGN KEY (actor_id) REFERENCES "user" (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE invoice ADD CONSTRAINT FK_906517441FB354CD FOREIGN KEY (membership_id) REFERENCES membership (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE invoice ADD CONSTRAINT FK_9065174482D4278B FOREIGN KEY (recorded_by) REFERENCES "user" (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE referral_code ADD credits_available INT NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE audit_log DROP CONSTRAINT FK_F6E1C0F510DAF24A');
        $this->addSql('ALTER TABLE invoice DROP CONSTRAINT FK_906517441FB354CD');
        $this->addSql('ALTER TABLE invoice DROP CONSTRAINT FK_9065174482D4278B');
        $this->addSql('DROP TABLE audit_log');
        $this->addSql('DROP TABLE invoice');
        $this->addSql('ALTER TABLE referral_code DROP credits_available');
    }
}
