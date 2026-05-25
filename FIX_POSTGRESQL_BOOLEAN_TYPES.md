# Fix: Error SQLSTATE[42804] - Type Mismatch en PostgreSQL (Render)

## Problema Identificado

**Error**: 
```
SQLSTATE[42804]: Datatype mismatch: 7 ERROR: column "activa" is of type boolean 
but expression is of type integer
```

**Causa**: 
PostgreSQL es más estricto que MySQL con los tipos de datos. Cuando intentas hacer:
```sql
UPDATE restricciones_trabajador SET activa = 0 WHERE id = 1
```

PostgreSQL rechaza esto porque `activa` es BOOLEAN pero le estás enviando 0 (INTEGER).

**Por qué funciona en local**: MySQL acepta la conversión implícita de 0/1 a BOOLEAN.

---

## Solución Aplicada

Se corrigieron todos los lugares donde se asignaban valores booleanos usando 0/1:

### 1. En `backend/clases/Trabajadores.php`

```php
// ANTES (error en PostgreSQL)
$sql = "UPDATE trabajadores SET activo = 0 WHERE id = :id";

// AHORA (funciona en ambos)
$sql = "UPDATE trabajadores SET activo = false WHERE id = :id";
```

Cambios específicos:
- Línea 305: `activo = 1` → `activo = true` en `activar()`
- Línea 316: `activo = 0` → `activo = false` en `desactivar()`  
- Línea 512: `activa = 0` → `activa = false` en `eliminarRestriccion()`

### 2. En `backend/clases/Incapacidades.php`

```php
// ANTES
':activa' => 1

// AHORA
':activa' => true
```

Línea 132: INSERT de restricciones ahora usa `true` en lugar de `1`.

También se corrigió:
- Línea 110: Removida conversión a `(int)` para `genera_restriccion`
- Línea 111: Removida conversión a `(int)` para `restriccion_permanente`

Ahora usa:
```php
':genera_restriccion' => filter_var($datos['genera_restriccion'] ?? false, FILTER_VALIDATE_BOOLEAN),
':restriccion_permanente' => filter_var($datos['restriccion_permanente'] ?? false, FILTER_VALIDATE_BOOLEAN),
```

---

## ¿Por qué esto funciona en ambos entornos?

| Base de Datos | Antes | Ahora |
|---|---|---|
| **MySQL** | Acepta `0`/`1` y convierte automáticamente | Acepta `true`/`false` nativamente |
| **PostgreSQL** | ❌ Rechaza entero para booleano | ✅ Acepta `true`/`false` nativamente |

Usar `true`/`false` en SQL es el estándar SQL y funciona en ambos drivers.

---

## Testing

Para verificar que el fix funciona:

1. En Render, intenta nuevamente eliminar una restricción
2. Debería funcionar sin error
3. Luego intenta en local (MySQL) para verificar compatibilidad

El error debería estar resuelto.

---

## Archivos Modificados

- [backend/clases/Trabajadores.php](backend/clases/Trabajadores.php) - 3 cambios
- [backend/clases/Incapacidades.php](backend/clases/Incapacidades.php) - 3 cambios

**Total**: 6 cambios de compatibilidad PostgreSQL/MySQL
