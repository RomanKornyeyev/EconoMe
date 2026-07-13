
<p align="center">
    <a href="https://symfony.com" target="_blank">
        <img src="https://symfony.com/logos/symfony_dynamic_01.svg" alt="Symfony Logo">
    </a>
    <br>
    <a href="https://symfony.com" target="_blank">
        <img src="https://getbootstrap.com/docs/5.3/assets/brand/bootstrap-logo-shadow.png" alt="Bootstrap Logo" width=100>
    </a>
</p>

# Template de autentificación y configuración de perfil para Symfony MVC 

> ℹ️ Versión del proyecto:
> - Symfony CLI: 5.16.1
> - Symfony: 8.0.3
> - PHP: 8.4.3
>
> ℹ️ REQUISITOS MÍNIMOS
> - Symfony CLI >= 5.10
> - Symfony >= 7.0
> - PHP >= 8.2

---

Aplicación web con un **sistema de autenticación completo**, incluyendo: 

##### AUTH
✅ **Inicio de sesión con "Recuérdame"**  
✅ **Registro con confirmación por email**  
✅ **Recuperación y restablecimiento de contraseña**  

##### GESTIÓN DE PERFIL (DASHBOARD)
✅ **edición de datos**  
✅ **edición de password con throttling y confirmación por email**  
✅ **cambio de email con autorización + confirmación por email**  

##### INTERFAZ
✅ Interfaz sencilla con **Bootstrap**, 100% **responsive** y con **modo claro/oscuro automático**.

<p align="center">
    <a href="https://i.imgur.com/S8GQuFm.png" target="_blank">
        <img src="https://i.imgur.com/S8GQuFm.png" alt="Project Screenshot">
    </a>
</p>

---

## Instalación y Configuración

### 1 - CLONAR E INSTALAR DEPENDENCIAS

#### 1.1 Instalar dependencias
```bash
composer install
```

#### 1.2 Configurar variables de entorno (credenciales)
Generamos un .env.local y metemos las credenciales: ``cp .env.local.example .env.local``
```bash
APP_ENV=dev

DATABASE_URL="mysql://usuario:password@127.0.0.1:3306/nombre_bd?serverVersion=8.0"

MAILER_DSN=smtp://usuario:password@smtp.servidor.com:587

APP_SECRET=tu_secreto_aqui
```

#### 1.3 Crear la base de datos y ejecutar migraciones
```bash
php bin/console doctrine:migrations:migrate
```
<i>*Si no tienes schema, ejecuta ``php bin/console doctrine:database:create``</i>

---

## Despliegue a producción

> ⚠️ **IMPORTANTE:** con `APP_ENV=prod` los assets (Stimulus, importmap, driver.js, CSS…)
> **NO se sirven dinámicamente** como en dev. Es **obligatorio compilarlos** en cada
> despliegue con `asset-map:compile`, o todos los ficheros de `/assets/*` devolverán 404
> y se romperá todo el JavaScript (validación de formularios, color picker, tours, CSRF…).

Checklist de despliegue (en el servidor, con `APP_ENV=prod` en `.env.local`):

```bash
# 1. Dependencias PHP (ejecuta también importmap:install, que descarga assets/vendor/)
composer install --no-dev --optimize-autoloader

# 2. Migraciones
php bin/console doctrine:migrations:migrate --no-interaction

# 3. Compilar assets => genera public/assets/ (importmap + JS/CSS versionados)
php bin/console asset-map:compile

# 4. Limpiar y precalentar caché de prod
php bin/console cache:clear
```

Notas:
- `public/assets/` y `assets/vendor/` están en el `.gitignore`: **no llegan por git**,
  se generan con los comandos de arriba. Si despliegas por FTP/rsync sin ejecutar
  comandos en el servidor, ejecuta los pasos 1 y 3 en local (con `APP_ENV=prod`) y
  sube también `vendor/`, `assets/vendor/` y `public/assets/`.
- Cada vez que cambies un fichero de `assets/` hay que volver a ejecutar
  `asset-map:compile` (los nombres llevan hash de versión, así que la caché del
  navegador no da problemas).
- Para volver a desarrollar en local: pon `APP_ENV=dev` en `.env.local` y **borra
  `public/assets/`** (si existe, los assets compilados "tapan" a los de `assets/` y
  verás versiones antiguas de tu JS/CSS).