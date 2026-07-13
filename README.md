
<p align="center">
    <a href="https://symfony.com" target="_blank">
        <img src="https://symfony.com/logos/symfony_dynamic_01.svg" alt="Symfony Logo">
    </a>
    <br>
    <a href="https://getbootstrap.com" target="_blank">
        <img src="https://getbootstrap.com/docs/5.3/assets/brand/bootstrap-logo-shadow.png" alt="Bootstrap Logo" width=100>
    </a>
</p>

# EconoMe

**EconoMe** es una aplicación web de finanzas personales que te ayuda a tomar el control de tu economía de forma simple y visual. Registra tus ingresos y gastos en segundos, organízalos con categorías personalizables, visualiza en qué se te va el dinero con gráficos, y automatiza los movimientos recurrentes (nómina, alquiler, suscripciones…). Además, puedes compartir cuentas con amigos, pareja o compañeros de piso para gestionar los gastos comunes juntos.

---

## Requisitos

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

Dependencias del sistema (Linux/Debian):

```bash
sudo apt install php-mysql php-mbstring php-xml php-curl php-zip php-intl php-bcmath
```

```bash
sudo apt install mysql-server
```

## Instalación (quick start)

### 1. Fork / clonar

```bash
git clone https://github.com/RomanKornyeyev/EconoMe.git && cd EconoMe
```

### 2. Configurar variables de entorno (credenciales)

Generamos un `.env.local` y metemos las credenciales: ``cp .env.local.example .env.local``

```bash
APP_ENV=dev

DATABASE_URL="mysql://usuario:password@127.0.0.1:3306/nombre_bd?serverVersion=8.0"

MAILER_DSN=smtp://usuario:password@smtp.servidor.com:587

APP_SECRET=tu_secreto_aqui

MAILER_FROM="TuNombre <tu@email.com>"
```

### 3. Instalar dependencias

```bash
composer install
```

### 4. Crear la base de datos y ejecutar migraciones

*Si no tienes schema, ejecuta ``php bin/console doctrine:database:create``*

```bash
php bin/console doctrine:migrations:migrate
```

### 5. Lanzar la app

```bash
symfony server:start
```

¡Listo! La app estará disponible en `https://localhost:8000`.

## Despliegue en producción

Deploy simple con Apache (para Docker habría que adaptar la config). En el `.env.local`, usa `APP_ENV=prod`.

### 1. Instalar dependencias (sin dev)

```bash
composer install --no-dev --optimize-autoloader
```

### 2. Compilar assets

Genera `public/assets/` (importmap + JS/CSS versionados):

```bash
php bin/console asset-map:compile
```

*Cada vez que cambies un fichero de `assets/` hay que volver a ejecutar `asset-map:compile` (los nombres llevan hash de versión, así que la caché del navegador no da problemas).*

### 3. Limpiar caché

```bash
php bin/console cache:clear
```
