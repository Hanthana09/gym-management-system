<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260809034815 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE announcement (id UUID NOT NULL, body TEXT NOT NULL, audience VARCHAR(20) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, gym_id UUID NOT NULL, created_by UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_4DB9D91CBD2F03 ON announcement (gym_id)');
        $this->addSql('CREATE INDEX IDX_4DB9D91CDE12AB56 ON announcement (created_by)');
        $this->addSql('CREATE TABLE notification (id UUID NOT NULL, title VARCHAR(255) NOT NULL, body TEXT NOT NULL, type VARCHAR(20) NOT NULL, source_role VARCHAR(20) DEFAULT NULL, read BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_BF5476CAA76ED395 ON notification (user_id)');
        $this->addSql('ALTER TABLE announcement ADD CONSTRAINT FK_4DB9D91CBD2F03 FOREIGN KEY (gym_id) REFERENCES gym (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE announcement ADD CONSTRAINT FK_4DB9D91CDE12AB56 FOREIGN KEY (created_by) REFERENCES "user" (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CAA76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE announcement DROP CONSTRAINT FK_4DB9D91CBD2F03');
        $this->addSql('ALTER TABLE announcement DROP CONSTRAINT FK_4DB9D91CDE12AB56');
        $this->addSql('ALTER TABLE notification DROP CONSTRAINT FK_BF5476CAA76ED395');
        $this->addSql('DROP TABLE announcement');
        $this->addSql('DROP TABLE notification');
    }
}
