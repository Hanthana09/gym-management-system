<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260817103209 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 17: expense_category, expense, product_category, product, product_sale tables, plus DAILY_METRIC_SNAPSHOT retail_revenue/expense_total/expense_by_category columns.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE expense (id UUID NOT NULL, amount NUMERIC(10, 2) NOT NULL, currency VARCHAR(8) NOT NULL, description TEXT DEFAULT NULL, expense_date DATE NOT NULL, receipt_url VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, branch_id UUID NOT NULL, category_id UUID NOT NULL, recorded_by UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_2D3A8DA6DCD6CC49 ON expense (branch_id)');
        $this->addSql('CREATE INDEX IDX_2D3A8DA612469DE2 ON expense (category_id)');
        $this->addSql('CREATE INDEX IDX_2D3A8DA682D4278B ON expense (recorded_by)');
        $this->addSql('CREATE TABLE expense_category (id UUID NOT NULL, name VARCHAR(255) NOT NULL, gym_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_C02DDB38BD2F03 ON expense_category (gym_id)');
        $this->addSql('CREATE TABLE product (id UUID NOT NULL, name VARCHAR(255) NOT NULL, sku VARCHAR(100) DEFAULT NULL, unit_price NUMERIC(8, 2) NOT NULL, is_active BOOLEAN DEFAULT true NOT NULL, gym_id UUID NOT NULL, category_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_D34A04ADBD2F03 ON product (gym_id)');
        $this->addSql('CREATE INDEX IDX_D34A04AD12469DE2 ON product (category_id)');
        $this->addSql('CREATE TABLE product_category (id UUID NOT NULL, name VARCHAR(255) NOT NULL, gym_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_CDFC7356BD2F03 ON product_category (gym_id)');
        $this->addSql('CREATE TABLE product_sale (id UUID NOT NULL, quantity INT NOT NULL, unit_price_at_sale NUMERIC(8, 2) NOT NULL, total_amount NUMERIC(10, 2) NOT NULL, payment_method VARCHAR(20) NOT NULL, sale_date TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, branch_id UUID NOT NULL, product_id UUID NOT NULL, member_id UUID DEFAULT NULL, sold_by UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_68A3E2A4DCD6CC49 ON product_sale (branch_id)');
        $this->addSql('CREATE INDEX IDX_68A3E2A44584665A ON product_sale (product_id)');
        $this->addSql('CREATE INDEX IDX_68A3E2A47597D3FE ON product_sale (member_id)');
        $this->addSql('CREATE INDEX IDX_68A3E2A4483AFB6F ON product_sale (sold_by)');
        $this->addSql('ALTER TABLE expense ADD CONSTRAINT FK_2D3A8DA6DCD6CC49 FOREIGN KEY (branch_id) REFERENCES branch (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE expense ADD CONSTRAINT FK_2D3A8DA612469DE2 FOREIGN KEY (category_id) REFERENCES expense_category (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE expense ADD CONSTRAINT FK_2D3A8DA682D4278B FOREIGN KEY (recorded_by) REFERENCES "user" (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE expense_category ADD CONSTRAINT FK_C02DDB38BD2F03 FOREIGN KEY (gym_id) REFERENCES gym (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE product ADD CONSTRAINT FK_D34A04ADBD2F03 FOREIGN KEY (gym_id) REFERENCES gym (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE product ADD CONSTRAINT FK_D34A04AD12469DE2 FOREIGN KEY (category_id) REFERENCES product_category (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE product_category ADD CONSTRAINT FK_CDFC7356BD2F03 FOREIGN KEY (gym_id) REFERENCES gym (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE product_sale ADD CONSTRAINT FK_68A3E2A4DCD6CC49 FOREIGN KEY (branch_id) REFERENCES branch (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE product_sale ADD CONSTRAINT FK_68A3E2A44584665A FOREIGN KEY (product_id) REFERENCES product (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE product_sale ADD CONSTRAINT FK_68A3E2A47597D3FE FOREIGN KEY (member_id) REFERENCES member_profile (user_id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE product_sale ADD CONSTRAINT FK_68A3E2A4483AFB6F FOREIGN KEY (sold_by) REFERENCES "user" (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE daily_metric_snapshot ADD retail_revenue NUMERIC(10, 2) DEFAULT \'0.00\' NOT NULL');
        $this->addSql('ALTER TABLE daily_metric_snapshot ADD expense_total NUMERIC(10, 2) DEFAULT \'0.00\' NOT NULL');
        $this->addSql('ALTER TABLE daily_metric_snapshot ADD expense_by_category JSON DEFAULT \'{}\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE expense DROP CONSTRAINT FK_2D3A8DA6DCD6CC49');
        $this->addSql('ALTER TABLE expense DROP CONSTRAINT FK_2D3A8DA612469DE2');
        $this->addSql('ALTER TABLE expense DROP CONSTRAINT FK_2D3A8DA682D4278B');
        $this->addSql('ALTER TABLE expense_category DROP CONSTRAINT FK_C02DDB38BD2F03');
        $this->addSql('ALTER TABLE product DROP CONSTRAINT FK_D34A04ADBD2F03');
        $this->addSql('ALTER TABLE product DROP CONSTRAINT FK_D34A04AD12469DE2');
        $this->addSql('ALTER TABLE product_category DROP CONSTRAINT FK_CDFC7356BD2F03');
        $this->addSql('ALTER TABLE product_sale DROP CONSTRAINT FK_68A3E2A4DCD6CC49');
        $this->addSql('ALTER TABLE product_sale DROP CONSTRAINT FK_68A3E2A44584665A');
        $this->addSql('ALTER TABLE product_sale DROP CONSTRAINT FK_68A3E2A47597D3FE');
        $this->addSql('ALTER TABLE product_sale DROP CONSTRAINT FK_68A3E2A4483AFB6F');
        $this->addSql('DROP TABLE expense');
        $this->addSql('DROP TABLE expense_category');
        $this->addSql('DROP TABLE product');
        $this->addSql('DROP TABLE product_category');
        $this->addSql('DROP TABLE product_sale');
        $this->addSql('ALTER TABLE daily_metric_snapshot DROP retail_revenue');
        $this->addSql('ALTER TABLE daily_metric_snapshot DROP expense_total');
        $this->addSql('ALTER TABLE daily_metric_snapshot DROP expense_by_category');
    }
}
