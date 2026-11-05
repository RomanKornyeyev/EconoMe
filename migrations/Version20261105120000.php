<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * user_token.token pasa a guardar el sha256 del token del enlace en vez del
 * token en claro (ver UserToken::hash). Se convierten los existentes para que
 * los enlaces ya enviados sigan funcionando. Ambos ocupan 64 caracteres hex,
 * así que no cambia el esquema.
 */
final class Version20261105120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Hashea (sha256) los tokens existentes de user_token';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE user_token SET token = SHA2(token, 256)');
    }

    public function down(Schema $schema): void
    {
        // Sin cambio de esquema que deshacer, así que no bloquea bajar a migraciones
        // anteriores. Los tokens siguen hasheados: con el código anterior, los enlaces
        // pendientes dejarán de funcionar (caducan en 24 h como mucho).
        $this->warnIf(true, 'Los tokens hasheados no se pueden recuperar en claro; los enlaces pendientes dejarán de valer.');
    }
}
