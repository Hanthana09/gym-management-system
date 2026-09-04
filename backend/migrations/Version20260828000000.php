<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * WhatsApp Cloud API access tokens (System User / long-lived tokens) can
 * exceed VARCHAR(255) — writing one produced SQLSTATE[22001] "value too
 * long for type character varying(255)". Widen gym.whatsapp_access_token
 * to TEXT. Nothing round-trips the value to the browser, so length is
 * unconstrained here.
 */
final class Version20260828000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Widen gym.whatsapp_access_token from VARCHAR(255) to TEXT.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE gym ALTER whatsapp_access_token TYPE TEXT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE gym ALTER whatsapp_access_token TYPE VARCHAR(255)');
    }
}
