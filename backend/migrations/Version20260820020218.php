<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Follow-up feature: "editable/manual Member ID mode" — Gym.memberIdMode
 * (default 'auto', every existing gym keeps today's behavior).
 *
 * The auto-generated diff also wanted to DROP TABLE member_sequence and
 * DROP INDEX workout_assignment_active_pair_unique — both are raw-SQL
 * structures with no Doctrine ORM mapping (member_sequence is
 * intentionally never entity-mapped, see MemberIdGenerator's docblock;
 * the workout_assignment index is the same pre-existing artifact called
 * out in Version20260818231240.php's and Version20260819152901.php's own
 * docblocks). Removed from both up() and down() here — unrelated to
 * this migration.
 */
final class Version20260820020218 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Follow-up feature: Gym.member_id_mode (auto|manual), default 'auto'.";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE gym ADD member_id_mode VARCHAR(10) DEFAULT \'auto\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE gym DROP member_id_mode');
    }
}
