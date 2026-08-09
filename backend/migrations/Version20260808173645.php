<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260808173645 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE attendance_log (id UUID NOT NULL, check_in TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, check_out TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, method VARCHAR(20) NOT NULL, member_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_8920D9677597D3FE ON attendance_log (member_id)');
        $this->addSql('ALTER TABLE attendance_log ADD CONSTRAINT FK_8920D9677597D3FE FOREIGN KEY (member_id) REFERENCES member_profile (user_id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE attendance_log DROP CONSTRAINT FK_8920D9677597D3FE');
        $this->addSql('DROP TABLE attendance_log');
    }
}
