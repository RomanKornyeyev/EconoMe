
<p align="center">
    <a href="https://symfony.com" target="_blank">
        <img src="https://symfony.com/logos/symfony_dynamic_01.svg" alt="Symfony Logo">
    </a>
    <br>
    <a href="https://symfony.com" target="_blank">
        <img src="https://getbootstrap.com/docs/5.3/assets/brand/bootstrap-logo-shadow.png" alt="Bootstrap Logo" width=100>
    </a>
</p>

# EconoMe

<p>descripción</p>

---

## Instalación y Configuración

> ℹ️ RECOMENDADO:
> - Symfony CLI: 5.16.1
> - Symfony: 8.0.3
> - PHP: 8.4.3
> - MySQL: 8.4
>
> ℹ️ MÍNIMO
> - Symfony CLI >= 5.10
> - Symfony >= 7.0
> - PHP >= 8.2
> - MySQL: >= 8.0

## Comandos rápidos para Linux

```bash
sudo apt install php-mysql php-mbstring php-xml php-curl php-zip php-intl php-bcmath
```

### 1 - CLONAR E INSTALAR DEPENDENCIAS

#### 1.1 Instalar dependencias
```bash
composer install
```

Para prod:
```bash
composer install --no-dev --optimize-autoloader
```

#### 1.2 Compilar assets (prod)

Compilar assets => genera public/assets/ (importmap + JS/CSS versionados)

```bash
php bin/console asset-map:compile
```

<i>*Cada vez que cambies un fichero de `assets/` hay que volver a ejecutar `asset-map:compile` (los nombres llevan hash de versión, así que la caché del navegador no da problemas).</i>

#### 1.3 Limpiar caché

```bash
php bin/console cache:clear
```

#### 1.4 Configurar variables de entorno (credenciales)
Generamos un .env.local y metemos las credenciales: ``cp .env.local.example .env.local``
```bash
APP_ENV=dev

DATABASE_URL="mysql://usuario:password@127.0.0.1:3306/nombre_bd?serverVersion=8.0"

MAILER_DSN=smtp://usuario:password@smtp.servidor.com:587

APP_SECRET=tu_secreto_aqui

MAILER_FROM="TuNombre <tu@email.com>"
```

#### 1.5 Crear la base de datos y ejecutar migraciones

<i>*Si no tienes schema, ejecuta ``php bin/console doctrine:database:create``</i>

```bash
php bin/console doctrine:migrations:migrate
```

#### Listo!