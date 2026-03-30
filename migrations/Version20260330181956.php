<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260330181956 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE account (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, description LONGTEXT DEFAULT NULL, currency VARCHAR(3) DEFAULT \'EUR\' NOT NULL, icon VARCHAR(50) DEFAULT NULL, color VARCHAR(7) DEFAULT NULL, created_at DATETIME NOT NULL, modified_at DATETIME DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE account_member (id INT AUTO_INCREMENT NOT NULL, role VARCHAR(20) NOT NULL, joined_at DATETIME NOT NULL, left_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, modified_at DATETIME DEFAULT NULL, account_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_57D0340B9B6B5FBA (account_id), INDEX IDX_57D0340BA76ED395 (user_id), UNIQUE INDEX unique_active_member (account_id, user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE category (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, icon VARCHAR(50) DEFAULT NULL, color VARCHAR(7) DEFAULT NULL, type VARCHAR(10) NOT NULL, created_at DATETIME NOT NULL, modified_at DATETIME DEFAULT NULL, user_id INT NOT NULL, parent_id INT DEFAULT NULL, INDEX IDX_64C19C1A76ED395 (user_id), INDEX IDX_64C19C1727ACA70 (parent_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE recurring_transaction (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, amount NUMERIC(10, 2) NOT NULL, type VARCHAR(10) NOT NULL, frequency VARCHAR(10) NOT NULL, day_of_execution INT NOT NULL, start_date DATE NOT NULL, end_date DATE DEFAULT NULL, is_active TINYINT DEFAULT 1 NOT NULL, last_generated_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, modified_at DATETIME DEFAULT NULL, account_id INT NOT NULL, category_id INT DEFAULT NULL, created_by_id INT NOT NULL, INDEX IDX_D3509AA69B6B5FBA (account_id), INDEX IDX_D3509AA612469DE2 (category_id), INDEX IDX_D3509AA6B03A8386 (created_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE transaction (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(10) NOT NULL, amount NUMERIC(10, 2) NOT NULL, date DATE NOT NULL, description VARCHAR(255) NOT NULL, notes LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, modified_at DATETIME DEFAULT NULL, account_id INT NOT NULL, category_id INT DEFAULT NULL, created_by_id INT NOT NULL, recurring_source_id INT DEFAULT NULL, INDEX IDX_723705D19B6B5FBA (account_id), INDEX IDX_723705D112469DE2 (category_id), INDEX IDX_723705D1B03A8386 (created_by_id), INDEX IDX_723705D1FF18805E (recurring_source_id), INDEX idx_transaction_date (date), INDEX idx_transaction_account_date (account_id, date), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE account_member ADD CONSTRAINT FK_57D0340B9B6B5FBA FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE account_member ADD CONSTRAINT FK_57D0340BA76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE category ADD CONSTRAINT FK_64C19C1A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE category ADD CONSTRAINT FK_64C19C1727ACA70 FOREIGN KEY (parent_id) REFERENCES category (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE recurring_transaction ADD CONSTRAINT FK_D3509AA69B6B5FBA FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE recurring_transaction ADD CONSTRAINT FK_D3509AA612469DE2 FOREIGN KEY (category_id) REFERENCES category (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE recurring_transaction ADD CONSTRAINT FK_D3509AA6B03A8386 FOREIGN KEY (created_by_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE transaction ADD CONSTRAINT FK_723705D19B6B5FBA FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE transaction ADD CONSTRAINT FK_723705D112469DE2 FOREIGN KEY (category_id) REFERENCES category (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE transaction ADD CONSTRAINT FK_723705D1B03A8386 FOREIGN KEY (created_by_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE transaction ADD CONSTRAINT FK_723705D1FF18805E FOREIGN KEY (recurring_source_id) REFERENCES recurring_transaction (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE user ADD deleted_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE account_member DROP FOREIGN KEY FK_57D0340B9B6B5FBA');
        $this->addSql('ALTER TABLE account_member DROP FOREIGN KEY FK_57D0340BA76ED395');
        $this->addSql('ALTER TABLE category DROP FOREIGN KEY FK_64C19C1A76ED395');
        $this->addSql('ALTER TABLE category DROP FOREIGN KEY FK_64C19C1727ACA70');
        $this->addSql('ALTER TABLE recurring_transaction DROP FOREIGN KEY FK_D3509AA69B6B5FBA');
        $this->addSql('ALTER TABLE recurring_transaction DROP FOREIGN KEY FK_D3509AA612469DE2');
        $this->addSql('ALTER TABLE recurring_transaction DROP FOREIGN KEY FK_D3509AA6B03A8386');
        $this->addSql('ALTER TABLE transaction DROP FOREIGN KEY FK_723705D19B6B5FBA');
        $this->addSql('ALTER TABLE transaction DROP FOREIGN KEY FK_723705D112469DE2');
        $this->addSql('ALTER TABLE transaction DROP FOREIGN KEY FK_723705D1B03A8386');
        $this->addSql('ALTER TABLE transaction DROP FOREIGN KEY FK_723705D1FF18805E');
        $this->addSql('DROP TABLE account');
        $this->addSql('DROP TABLE account_member');
        $this->addSql('DROP TABLE category');
        $this->addSql('DROP TABLE recurring_transaction');
        $this->addSql('DROP TABLE transaction');
        $this->addSql('ALTER TABLE user DROP deleted_at');
    }
}
