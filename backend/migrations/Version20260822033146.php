<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * gym-management-password-auth.md §2: User.requiresPasswordChange /
 * passwordSetBy / passwordSetAt (all additive, no backfill — every
 * existing row gets requires_password_change = true by column default,
 * harmless since it's only ever consulted alongside a non-null password),
 * plus the new password_reset_token table.
 *
 * The auto-generated diff also wanted to DROP TABLE member_sequence and
 * DROP INDEX workout_assignment_active_pair_unique — both are raw-SQL
 * structures with no Doctrine ORM mapping (same pre-existing artifacts
 * called out in Version20260818231240.php's, Version20260819152901.php's,
 * and Version20260820020218.php's own docblocks). Removed from both up()
 * and down() here — unrelated to this migration.
 */
final class Version20260822033146 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'gym-management-password-auth.md §2: User password-admin fields + password_reset_token table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE password_reset_token (id UUID NOT NULL, token_hash VARCHAR(255) NOT NULL, channel VARCHAR(20) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, request_ip VARCHAR(64) DEFAULT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_6B7BA4B6A76ED395 ON password_reset_token (user_id)');
        $this->addSql('CREATE INDEX IDX_6B7BA4B6A76ED39577241BA ON password_reset_token (user_id, used_at)');
        $this->addSql('ALTER TABLE password_reset_token ADD CONSTRAINT FK_6B7BA4B6A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE "user" ADD requires_password_change BOOLEAN DEFAULT true NOT NULL');
        $this->addSql('ALTER TABLE "user" ADD password_set_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD password_set_by_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD CONSTRAINT FK_8D93D649A5E28940 FOREIGN KEY (password_set_by_id) REFERENCES "user" (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_8D93D649A5E28940 ON "user" (password_set_by_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE password_reset_token DROP CONSTRAINT FK_6B7BA4B6A76ED395');
        $this->addSql('DROP TABLE password_reset_token');
        $this->addSql('ALTER TABLE "user" DROP CONSTRAINT FK_8D93D649A5E28940');
        $this->addSql('DROP INDEX IDX_8D93D649A5E28940');
        $this->addSql('ALTER TABLE "user" DROP requires_password_change');
        $this->addSql('ALTER TABLE "user" DROP password_set_at');
        $this->addSql('ALTER TABLE "user" DROP password_set_by_id');
    }
}
