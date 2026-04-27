# DentalSys — Instrucciones de instalación

## 1. Subir archivos
```
/var/www/html/dental/
```

## 2. Crear base de datos
```sql
CREATE DATABASE dental CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```
Importar: `/dental/database/dental.sql`

## 3. Configurar conexión
Editar `/dental/includes/config.php`:
```php
define('DB_HOST',    'localhost');
define('DB_NAME',    'dental');
define('DB_USER',    'tu_usuario');
define('DB_PASS',    'tu_contraseña');
define('BASE_URL',   '/dental');
```

## 4. Configurar Apache (agregar al VirtualHost)
```apache
Alias /dental /var/www/html/dental
<Directory /var/www/html/dental>
    AllowOverride All
    Require all granted
    Options -Indexes
</Directory>
```

## 5. Permisos de carpetas
```bash
chmod -R 755 /var/www/html/dental/
chmod -R 775 /var/www/html/dental/uploads/
chown -R apache:apache /var/www/html/dental/uploads/
```

## 6. Acceso al sistema
URL: https://magus-ecommerce.com/dental/

### Usuarios por defecto (contraseña: password)
| Email | Rol |
|---|---|
| admin@dental.com | Administrador |
| doctor@dental.com | Doctor |
| recepcion@dental.com | Recepción |

**¡IMPORTANTE:** Cambiar contraseñas en producción desde el módulo Usuarios.

## Módulos incluidos
- ✅ Dashboard con KPIs en tiempo real
- ✅ Gestión de pacientes (completa NT N°022-MINSA)
- ✅ Agenda de citas con estados
- ✅ Historia Clínica Electrónica
- ✅ Odontograma FDI interactivo (RM 593-2006)
- ✅ Catálogo de tratamientos (25+ base)
- ✅ Plan de tratamiento con presupuesto
- ✅ Caja y pagos (Yape/Efectivo/Tarjeta)
- ✅ Inventario con kardex y alertas
- ✅ WhatsApp recordatorios
- ✅ Reportes y analítica con gráficos
- ✅ Pantalla de turnos para TV
- ✅ Auditoría SIHCE
- ✅ Gestión de usuarios y roles
