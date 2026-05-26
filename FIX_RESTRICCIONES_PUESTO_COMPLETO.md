# FIX: Restricciones de Puesto Específico - Validación Robusta

**Fecha**: 26 de Mayo de 2026  
**Estado**: ✅ IMPLEMENTADO  
**Versión**: 3 (Robusto con fallbacks)

## El Problema

Aunque las restricciones de área específica se guardaban en la BD, **la asignación automática NO las respetaba** y seguía asignando trabajadores a puestos restringidos.

### Síntomas
- Crear restricción de puesto específico para un trabajador
- Ejecutar asignación automática
- Trabajador recibe turnos en el puesto restringido (debería no recibirlos)

### Causa Raíz Identificada

Las consultas SQL usaban:
```sql
AND (puesto_trabajo_id = ? OR puesto_id = ?)
```

Esto fallaba cuando:
1. **Ambas columnas existen pero tienen NULL**: `NULL = 5` retorna `NULL` (falsy) en SQL
2. **Una columna no existe**: La consulta falla silenciosamente o se ejecuta parcialmente
3. **OR con NULL**: `NULL OR FALSE` = `NULL` (no hace match)

Resultado: **Los trabajadores NUNCA se bloqueaban**, aunque la restricción estuviera en la BD.

## Soluciones Implementadas

### 1. AsignacionAutomatica.php (Líneas 205-240, 309-344)

**Antes**: Consultaba `(puesto_trabajo_id = ? OR puesto_id = ?)`  
**Ahora**: Usa `COALESCE(puesto_trabajo_id, puesto_id) = ?` con fallback

```php
// Versión mejorada
try {
    $sql = "SELECT DISTINCT trabajador_id FROM restricciones_trabajador
            WHERE tipo_restriccion = 'puesto_especifico'
            AND activa = true
            AND fecha_inicio <= ?
            AND (fecha_fin IS NULL OR fecha_fin >= ?)
            AND COALESCE(puesto_trabajo_id, puesto_id) = ?";
    
    $stmtPuestoCheck = $this->db->prepare($sql);
    $stmtPuestoCheck->execute([$fecha, $fecha, $puestoId]);
    // ... procesar resultados
} catch (Exception $e) {
    // Si falla COALESCE, intentar fallback
    // ... fallback a OR
}
```

**Cambios en funciones**:
- `getDisponibles()` (línea ~206)
- `getDisponiblesL4()` (línea ~310)

### 2. Trabajadores.php (Líneas 428-510)

**Nuevo**: Función `asegurarColumnaRestricciones()` que crea la columna dinámicamente

```php
private function asegurarColumnaRestricciones() {
    $columnExists = Database::hasColumn('restricciones_trabajador', 'puesto_trabajo_id') ||
                   Database::hasColumn('restricciones_trabajador', 'puesto_id');
    
    if ($columnExists) return true;
    
    // Crear columna si no existe
    if (DB_DRIVER === 'pgsql') {
        $sql = "ALTER TABLE restricciones_trabajador 
                ADD COLUMN IF NOT EXISTS puesto_trabajo_id INTEGER";
    } else {
        $sql = "ALTER TABLE `restricciones_trabajador` 
                ADD COLUMN `puesto_trabajo_id` INT NULL 
                AFTER `tipo_restriccion`";
    }
    
    $this->db->exec($sql);
    return true;
}
```

**Cambios en funciones**:
- `agregarRestriccion()`: Llama a `asegurarColumnaRestricciones()` si tipo_restriccion = 'puesto_especifico'
- `actualizarRestriccion()`: Mismo tratamiento

**Logging**:
```php
// Cuando se guarda exitosamente
error_log("[Trabajadores::agregarRestriccion] ✅ Restricción puesto_especifico guardada: 
           trabajador={$datos['trabajador_id']}, puesto=$puestoTrabId en columna=$columnName");
```

### 3. TurnosAsignados.php (Líneas 231-278)

**Antes**: Solo validaba si `$columnName` existía (podía saltarse la validación)  
**Ahora**: SIEMPRE intenta validar usando COALESCE + fallback

```php
try {
    $sql = "SELECT COUNT(*) as count FROM restricciones_trabajador
            WHERE trabajador_id = :trabajador_id
            AND tipo_restriccion = 'puesto_especifico'
            AND COALESCE(puesto_trabajo_id, puesto_id) = :puesto_id
            ...";
    $stmt = $this->db->prepare($sql);
    $stmt->execute(...);
} catch (Exception $e) {
    // Fallback con OR si COALESCE no funciona
    // ... retry
}
```

## Archivos Modificados

| Archivo | Líneas | Cambio |
|---------|--------|--------|
| `backend/clases/AsignacionAutomatica.php` | 205-240, 309-344 | COALESCE + fallback en getDisponibles/getDisponiblesL4 |
| `backend/clases/Trabajadores.php` | 428-510 | agregarRestriccion + actualizarRestriccion mejorados |
| `backend/clases/Trabajadores.php` | 485-510 | Nueva función asegurarColumnaRestricciones() |
| `backend/clases/TurnosAsignados.php` | 231-278 | Validación puesto_especifico con COALESCE + fallback |

## Ventajas del Fix

✅ **Robusto**: Funciona incluso si la columna no existe  
✅ **NULL-safe**: Maneja NULL values correctamente con COALESCE  
✅ **Multi-DB**: Compatible con MySQL (local) y PostgreSQL (Render)  
✅ **Fallback**: Si COALESCE falla, intenta método alternativo  
✅ **Logging**: Diagnóstico claro en error_log  
✅ **Completo**: Aplica a asignación automática Y asignación manual  

## Testing

### Paso 1: Verificar Columna
```php
// Ejecutar en navegador
// http://localhost/Terminal-Ibagu-/backend/scripts/diagnostic_restricciones_puesto.php
```

### Paso 2: Crear Restricción
1. Ir a Gestión > Trabajadores > Restricciones
2. Crear restricción de "Puesto específico"
3. Seleccionar trabajador y puesto
4. Guardar

### Paso 3: Verificar Guardado
Ver en logs (debug_restriccion_trabajador.php o error_log):
```
✅ Restricción puesto_especifico guardada: trabajador=5, puesto=10 en columna=puesto_trabajo_id
```

### Paso 4: Asignar y Verificar
1. Ejecutar asignación automática para el mes
2. Verificar que el trabajador NO tiene turnos en el puesto restringido
3. Debería haber 0 turnos en ese puesto (no 3 o más)

## Rollback (si es necesario)

```sql
-- MySQL
ALTER TABLE `restricciones_trabajador` DROP COLUMN `puesto_trabajo_id`;

-- PostgreSQL
ALTER TABLE restricciones_trabajador DROP COLUMN IF EXISTS puesto_trabajo_id;
```

## Notas Importantes

- La columna se crea **automáticamente** al guardar una restricción de puesto_especifico
- No es necesario ejecutar migración manual
- Funciona en ambos entornos (local + Render) automáticamente
- Los logs ayudan a diagnosticar si algo no funciona

## Próximos Pasos (Opcionales)

1. Agregar tests unitarios para validación de restricciones
2. Crear dashboard de auditoría de restricciones
3. Notificaciones cuando un trabajador recibe turno en puesto anteriormente restringido
