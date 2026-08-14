<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260814042010 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE gym ADD whatsapp_enabled BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE gym ADD whatsapp_access_token VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE gym ADD whatsapp_phone_number_id VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE gym DROP whatsapp_enabled');
        $this->addSql('ALTER TABLE gym DROP whatsapp_access_token');
        $this->addSql('ALTER TABLE gym DROP whatsapp_phone_number_id');
    }
}
