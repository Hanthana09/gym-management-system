<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * gym-management-member-profile-extension.md §3: memberId/gym scoping +
 * gender/address fields on MEMBER_PROFILE, plus GYM.gym_code (memberId's
 * prefix). All new member_profile columns are nullable — populated going
 * forward at invite-acceptance/manual-creation time, backfilled for
 * pre-existing rows by `app:member:backfill-ids` (not by this migration).
 *
 * `member_sequence` is hand-added below (not part of the diff) — it
 * backs MemberIdGenerator's atomic per-gym counter and is deliberately
 * not a Doctrine entity/repository, since nothing ever reads/writes it
 * except that one INSERT ... ON CONFLICT ... RETURNING statement.
 *
 * The auto-generated diff also wanted to DROP INDEX
 * workout_assignment_active_pair_unique — the same raw-SQL-partial-index
 * artifact already called out in Version20260818231240.php's docblock.
 * Removed from both up() and down() here — unrelated to this migration.
 */
final class Version20260819152901 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'gym-management-member-profile-extension.md: memberId/gym/gender/address on member_profile, gym.gym_code, member_sequence table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE gym ADD gym_code VARCHAR(8) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_7F27DBED9E844829 ON gym (gym_code)');
        $this->addSql('ALTER TABLE member_profile ADD gender VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE member_profile ADD address_line VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE member_profile ADD address_city VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE member_profile ADD address_postal_code VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE member_profile ADD member_id VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE member_profile ADD gym_id UUID DEFAULT NULL');
        $this->addSql(<<<'SQL'
            ALTER TABLE
              member_profile
            ADD
              CONSTRAINT FK_3EA31D9BBD2F03 FOREIGN KEY (gym_id) REFERENCES gym (id) NOT DEFERRABLE
        SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_3EA31D9B7597D3FE ON member_profile (member_id)');
        $this->addSql('CREATE INDEX IDX_3EA31D9BBD2F03 ON member_profile (gym_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE member_sequence (
              gym_id UUID NOT NULL,
              next_number INT NOT NULL DEFAULT 1,
              PRIMARY KEY (gym_id)
            )
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              member_sequence
            ADD
              CONSTRAINT FK_MEMBER_SEQUENCE_GYM FOREIGN KEY (gym_id) REFERENCES gym (id) NOT DEFERRABLE
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE member_sequence');
        $this->addSql('DROP INDEX UNIQ_7F27DBED9E844829');
        $this->addSql('ALTER TABLE gym DROP gym_code');
        $this->addSql('ALTER TABLE member_profile DROP CONSTRAINT FK_3EA31D9BBD2F03');
        $this->addSql('DROP INDEX UNIQ_3EA31D9B7597D3FE');
        $this->addSql('DROP INDEX IDX_3EA31D9BBD2F03');
        $this->addSql('ALTER TABLE member_profile DROP gender');
        $this->addSql('ALTER TABLE member_profile DROP address_line');
        $this->addSql('ALTER TABLE member_profile DROP address_city');
        $this->addSql('ALTER TABLE member_profile DROP address_postal_code');
        $this->addSql('ALTER TABLE member_profile DROP member_id');
        $this->addSql('ALTER TABLE member_profile DROP gym_id');
    }
}
