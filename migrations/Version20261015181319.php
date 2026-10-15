<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Las categorías (y sus plantillas) dejan de tener tipo gasto/ingreso: el tipo
 * es del movimiento y una misma categoría vale para ambos.
 */
final class Version20261015181319 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Elimina el tipo (gasto/ingreso) de category y category_template';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE category DROP type');
        $this->addSql('ALTER TABLE category_template DROP type');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE category ADD type VARCHAR(10) DEFAULT 'expense' NOT NULL");
        $this->addSql("ALTER TABLE category_template ADD type VARCHAR(10) DEFAULT 'expense' NOT NULL");

        // El dato original se perdió: se reconstruye con el tipo que domina en
        // los movimientos de cada categoría (las plantillas se quedan en gasto).
        $this->addSql("UPDATE category c SET c.type = 'income' WHERE
            (SELECT COUNT(*) FROM transaction t WHERE t.category_id = c.id AND t.type = 'income') >
            (SELECT COUNT(*) FROM transaction t WHERE t.category_id = c.id AND t.type = 'expense')");

        $this->addSql('ALTER TABLE category ALTER type DROP DEFAULT');
        $this->addSql('ALTER TABLE category_template ALTER type DROP DEFAULT');
    }
}
