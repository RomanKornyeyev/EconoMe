
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

> ℹ️ MÍNIMO
> - Symfony CLI >= 5.16.1
> - Symfony >= 8.0.1
> - PHP >= 8.2.1
> - MySQL >= 8.0.3

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

### 4. Automatizar las transacciones recurrentes (cron)

`app:generate-recurring-transactions` materializa las transacciones pendientes de las recurrentes activas (nómina, alquiler, suscripciones…). En producción debe ejecutarse una vez al día.

Es idempotente y hace *catch-up*: la ventana arranca en `lastGeneratedAt` y deduplica por fecha, así que no duplica nada si se lanza dos veces y recupera los días perdidos si el servidor estuvo caído.

*Los ejemplos asumen el proyecto en `/var/www/EconoMe` y PHP corriendo como `www-data`, que debe tener escritura en `var/`.*

**Prueba el comando a mano** antes de programar nada. Debe devolver `[OK] Se generaron N transacciones.` con exit code 0:

```bash
sudo -u www-data /usr/bin/php /var/www/EconoMe/bin/console app:generate-recurring-transactions --env=prod --no-interaction
```

**Programa la tarea** a las 02:00:

```bash
sudo mkdir -p /var/log/econome && sudo chown www-data:www-data /var/log/econome

sudo crontab -u www-data -l 2>/dev/null > /tmp/cron-econome
cat >> /tmp/cron-econome <<'EOF'
MAILTO=""
0 2 * * * /usr/bin/flock -n /run/lock/econome-recurring.lock /usr/bin/php /var/www/EconoMe/bin/console app:generate-recurring-transactions --env=prod --no-interaction >> /var/log/econome/recurring.log 2>&1
EOF
sudo crontab -u www-data /tmp/cron-econome && rm /tmp/cron-econome
```

Rutas absolutas porque cron no hereda el `PATH` (no hace falta `cd`: Symfony resuelve el proyecto desde `bin/console`). `flock` evita solapamientos y `MAILTO=""` silencia los correos de cron — quítalo si prefieres recibir aviso, ya que el comando devuelve exit code `1` cuando alguna recurrente falla.

**Comprueba que se ejecuta** sin esperar a las 02:00: baja la frecuencia, espera a que pase un minuto par (el log no existe hasta la primera ejecución) y revisa la salida.

```bash
sudo crontab -u www-data -l | sed 's|^0 2 \* \* \*|*/2 * * * *|' | sudo crontab -u www-data -
tail -20 /var/log/econome/recurring.log
```

Ver `0 transacciones` es correcto: lo que se valida no es que genere algo, sino que cron encuentra el binario y llega a la base de datos. Si el log no aparece, `journalctl -u cron --since "10 min ago"`. Después **restaura el horario** y confirma que la línea vuelve a empezar por `0 2 * * *`:

```bash
sudo crontab -u www-data -l | sed 's|^\*/2 \* \* \* \*|0 2 * * *|' | sudo crontab -u www-data -
sudo crontab -u www-data -l
```

**Rota el log** para que no crezca sin límite (semanal, 8 semanas de histórico):

```bash
sudo tee /etc/logrotate.d/econome > /dev/null <<'EOF'
/var/log/econome/*.log {
    weekly
    rotate 8
    compress
    missingok
    notifempty
    create 0640 www-data www-data
}
EOF
```

*Cron dispara según la hora del sistema, pero el comando calcula las fechas con la zona horaria de PHP CLI. Comprueba que coinciden con `timedatectl` y `php -i | grep date.timezone`.*

