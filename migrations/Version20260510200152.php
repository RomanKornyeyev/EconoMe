<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260510200152 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE category_template (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, icon VARCHAR(50) DEFAULT NULL, color VARCHAR(7) DEFAULT NULL, type VARCHAR(10) NOT NULL, created_at DATETIME NOT NULL, modified_at DATETIME DEFAULT NULL, user_id INT NOT NULL, INDEX IDX_201A9BF8A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE category_template ADD CONSTRAINT FK_201A9BF8A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE category DROP FOREIGN KEY `FK_64C19C1A76ED395`');
        $this->addSql('DROP INDEX IDX_64C19C1A76ED395 ON category');
        $this->addSql('ALTER TABLE category CHANGE user_id account_id INT NOT NULL');
        $this->addSql('ALTER TABLE category ADD CONSTRAINT FK_64C19C19B6B5FBA FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_64C19C19B6B5FBA ON category (account_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE category_template DROP FOREIGN KEY FK_201A9BF8A76ED395');
        $this->addSql('DROP TABLE category_template');
        $this->addSql('ALTER TABLE category DROP FOREIGN KEY FK_64C19C19B6B5FBA');
        $this->addSql('DROP INDEX IDX_64C19C19B6B5FBA ON category');
        $this->addSql('ALTER TABLE category CHANGE account_id user_id INT NOT NULL');
        $this->addSql('ALTER TABLE category ADD CONSTRAINT `FK_64C19C1A76ED395` FOREIGN KEY (user_id) REFERENCES user (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_64C19C1A76ED395 ON category (user_id)');
    }
}
