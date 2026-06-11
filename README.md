# Comunikate Backend

API REST Laravel para gestión académica y de servicios de Comunikate.

---

## Requisitos previos del servidor

- PHP 8.3+
- Composer 2.x
- PostgreSQL 16+
- Extensiones PHP: pgsql, bcmath, curl, mbstring, xml, zip, gd

---

## Variables de entorno necesarias

| Variable | Descripción | Ejemplo |
|----------|-------------|---------|
| `DB_CONNECTION` | Driver de BD | `pgsql` |
| `DB_HOST` | Host de BD | `127.0.0.1` |
| `DB_PORT` | Puerto de BD | `5432` |
| `DB_DATABASE` | Nombre de BD | `DBCK` |
| `DB_USERNAME` | Usuario de BD | `postgres` |
| `DB_PASSWORD` | Contraseña de BD | |
| `ADMIN_USERNAME` | Usuario admin inicial | `admin` |
| `ADMIN_PASSWORD` | Contraseña admin inicial | `admin123` |
| `ADMIN_EMAIL` | Correo del admin | `admin@comunikate.com` |
| `SANCTUM_STATEFUL_DOMAINS` | Dominios SPA permitidos | `localhost,localhost:3000` |

Variables completas en `.env.example`.

---

## Seeders del proyecto

| Seeder | Idempotente | Entorno | Propósito |
|--------|-------------|---------|-----------|
| `RolesAndPermissionsSeeder` | Sí | Todos | Crea roles (`Administrador`, `Instructor`, `Staff`) y permisos del sistema |
| `AdminUserSeeder` | Sí | `local`, `staging` | Crea usuario admin por defecto |

**Orden de ejecución**: `RolesAndPermissionsSeeder` → `AdminUserSeeder`

---

## Escenarios de despliegue

### A) Instalación desde cero (servidor nuevo)

```bash
cp .env.example .env
# Editar .env con credenciales reales (BD, admin, etc.)
composer install
php artisan key:generate
php artisan migrate
php artisan db:seed
```

### B) Deploy de actualizaciones (datos existentes)

```bash
php artisan migrate --force
php artisan db:seed --class=RolesAndPermissionsSeeder --force
```

### C) Reset completo (solo local/desarrollo)

```bash
php artisan migrate:fresh --seed
```

### D) Actualizar solo roles y permisos

```bash
php artisan db:seed --class=RolesAndPermissionsSeeder --force
```

### E) Pipeline CI/CD

```bash
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan db:seed --class=RolesAndPermissionsSeeder --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

---

## Solución a errores comunes durante deploy

| Error | Causa | Solución |
|-------|-------|----------|
| `SQLSTATE[42P07]: Duplicate table` | Tabla ya existe | `php artisan migrate:fresh` (solo local) |
| `Class "Role" not found` | Vendor no instalado | `composer install` |
| `No application encryption key` | `APP_KEY` vacío | `php artisan key:generate` |
| `403 Forbidden` al autenticar | Roles no asignados | `php artisan db:seed --class=RolesAndPermissionsSeeder --force` |
| `Target class [...] does not exist` | Autoload desactualizado | `composer dump-autoload` |
| `Connection refused` (BD) | PostgreSQL no corre | Verificar `systemctl status postgresql` |
