# Configuración de Dos Bases de Datos MySQL

## Overview
El sistema ahora soporta **dos bases de datos MySQL** - una para desarrollo local y otra para producción. Render ha sido completamente eliminado del proyecto.

---

## 1. Configuración del Archivo `config/database.php`

El archivo ahora detecta automáticamente el entorno usando la variable `APP_ENV`:

### Parámetros por Entorno

#### LOCAL (Desarrollo)
- **APP_ENV**: `LOCAL` (valor por defecto)
- **DB_HOST**: `localhost` (modificable con `DB_HOST`)
- **DB_NAME**: `gestion_turnos` (modificable con `DB_NAME`)
- **DB_USER**: `root` (modificable con `DB_USER`)
- **DB_PASS**: `''` vacío (modificable con `DB_PASS`)
- **DB_PORT**: `3306`

#### PRODUCCIÓN (Despliegue)
- **APP_ENV**: `PRODUCTION`
- **DB_HOST**: `sql300.infinityfree.com` (modificable con `DB_HOST`)
- **DB_NAME**: `if0_42018119_gestion_turnos` (modificable con `DB_NAME`)
- **DB_USER**: `if0_42018119` (modificable con `DB_USER`)
- **DB_PASS**: `UObmwULK5bhn` (modificable con `DB_PASS`)
- **DB_PORT**: `3306`

---

## 2. Cómo Usar Las Dos Bases de Datos

### Opción A: Usar Variables de Entorno del Sistema
Para cambiar entre LOCAL y PRODUCCIÓN, establece la variable de entorno `APP_ENV`:

**Windows (PowerShell):**
```powershell
# Para LOCAL
$env:APP_ENV = "LOCAL"

# Para PRODUCCIÓN
$env:APP_ENV = "PRODUCTION"
```

**Windows (CMD):**
```cmd
# Para LOCAL
set APP_ENV=LOCAL

# Para PRODUCCIÓN
set APP_ENV=PRODUCTION
```

**Linux/Mac:**
```bash
# Para LOCAL
export APP_ENV=LOCAL

# Para PRODUCCIÓN
export APP_ENV=PRODUCTION
```

### Opción B: Usar Archivo `.env` (Si usas phpdotenv)
Crea un archivo `.env` en la raíz del proyecto:

```
APP_ENV=LOCAL
DB_HOST=localhost
DB_NAME=gestion_turnos
DB_USER=root
DB_PASS=
```

### Opción C: Configurar en Apache/PHP-FPM
En Apache, agrega a tu VirtualHost o `.htaccess`:

```apache
SetEnv APP_ENV LOCAL
SetEnv DB_HOST localhost
SetEnv DB_NAME gestion_turnos
SetEnv DB_USER root
SetEnv DB_PASS ""
```

---

## 3. Configuración de Credenciales

### Para BASE DE DATOS LOCAL (XAMPP)
1. Abre **phpMyAdmin** en `http://localhost/phpmyadmin`
2. Crea una base de datos llamada `gestion_turnos`:
   ```sql
   CREATE DATABASE gestion_turnos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```
3. Importa tu schema SQL en la base de datos

### Para BASE DE DATOS PRODUCCIÓN
1. Accede a tu panel de control del hosting
2. Crea/verifica la base de datos `if0_42018119_gestion_turnos`
3. Verifica que el usuario `if0_42018119` tenga permisos completos

---

## 4. Cambios en el Código

### Eliminado:
- ❌ Todas las referencias a `RENDER`
- ❌ Todas las referencias a `isRender`
- ❌ Toda la lógica de PostgreSQL
- ❌ Variables de entorno `DB_HOST_RENDER`, `DB_NAME_RENDER`, `DB_USER_RENDER`, `DB_PASS_RENDER`

### Simplificado:
- ✅ Todos los métodos de la clase `Database` ahora usan solo sintaxis MySQL
- ✅ Detección de entorno mediante `APP_ENV`
- ✅ Manejo automático de errores según el entorno

---

## 5. Prueba de Conexión

Para verificar que todo está funcionando:

```php
<?php
require 'config/database.php';

$db = Database::getInstance();
$conn = $db->getConnection();

if ($conn) {
    echo json_encode([
        'success' => true,
        'message' => 'Conectado correctamente',
        'database' => DB_NAME,
        'host' => DB_HOST,
        'environment' => getenv('APP_ENV') ?: 'LOCAL'
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Error de conexión'
    ]);
}
?>
```

---

## 6. Despliegue en Producción

Antes de desplegar, establece estas variables de entorno en tu servidor:

```
APP_ENV=PRODUCTION
DB_HOST=sql300.infinityfree.com
DB_NAME=if0_42018119_gestion_turnos
DB_USER=if0_42018119
DB_PASS=UObmwULK5bhn
DB_PORT=3306
```

**Nota**: Los errores detallados solo se muestran en LOCAL. En PRODUCCIÓN, se oculta la información sensible.

---

## 7. Estructura de Migración de Datos

Si necesitas migrar datos de la base de datos antigua a la nueva:

```php
// Script de migración
require 'config/database.php';

// Conectar a ambas bases de datos...
// (Ver archivos en backend/scripts/)
```

Ver: `backend/scripts/` para scripts de migración disponibles.

---

## 8. Notas Importantes

1. **Charset**: Ambas bases de datos usan `utf8mb4` para soportar caracteres especiales
2. **Zona Horaria**: Sistema configurado para Colombia (`America/Bogota`)
3. **PDO**: Usa prepared statements para prevenir SQL injection
4. **Errores**: En producción se muestran mensajes genéricos; en local se muestran detalles completos

---

## 9. Soporte Para Variables de Entorno

Puedes sobrescribir cualquier parámetro usando variables de entorno:

```
DB_HOST=custom.host.com
DB_NAME=custom_database
DB_USER=custom_user
DB_PASS=custom_password
DB_PORT=3306
APP_ENV=PRODUCTION
```

---

Última actualización: 2026-06-05
