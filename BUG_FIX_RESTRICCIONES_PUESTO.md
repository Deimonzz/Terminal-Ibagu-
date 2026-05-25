# Bug Fix: Restricciones de Puesto Específico en Asignación Automática

## Problema Identificado

La asignación automática de turnos NO respeta las restricciones de **puesto específico**. Un trabajador con restricción de puesto específico puede recibir asignaciones en ese puesto que debería tener restringido.

## Causa Raíz

La tabla `restricciones_trabajador` probablemente no tenía la columna `puesto_trabajo_id` (o `puesto_id`), que es necesaria para almacenar cuál puesto tiene restringido cada trabajador.

Cuando esta columna no existe:
1. El método `Database::getColumnName()` retorna `null`
2. El prefetch en `AsignacionAutomatica::prefetchDisponibilidadMes()` selecciona `NULL as puesto_trabajo_id`
3. Todas las restricciones tienen `puesto_trabajo_id = NULL`
4. La comparación `NULL == $puestoId` en `getDisponibles()` siempre falla
5. Los trabajadores con restricción nunca se bloquean

## Solución Implementada

### 1. Agregar la Columna `puesto_trabajo_id` a la Tabla

**Método A - Ejecución automática (ejecutar el script PHP):**
```
GET http://localhost/Terminal-Ibagu-/backend/scripts/migrate_add_puesto_column.php
```

**Método B - SQL directo (ejecutar en phpMyAdmin o similar):**
```sql
ALTER TABLE `restricciones_trabajador` 
ADD COLUMN `puesto_trabajo_id` INT NULL 
AFTER `tipo_restriccion`;

ALTER TABLE `restricciones_trabajador`
ADD CONSTRAINT `fk_restricciones_puesto` 
FOREIGN KEY (`puesto_trabajo_id`) 
REFERENCES `puestos_trabajo`(`id`) ON DELETE SET NULL;
```

### 2. Mejoras en el Código

Archivos modificados:

#### [backend/clases/AsignacionAutomatica.php](backend/clases/AsignacionAutomatica.php)

- **Función `prefetchDisponibilidadMes()`**: Agregado log de advertencia si la columna no existe
- **Función `getDisponibles()`**: 
  - Validación NULL antes de comparar `puesto_trabajo_id`
  - Fallback alternativo que consulta la BD directamente si `puesto_trabajo_id` es NULL
- **Función `getDisponiblesL4()`**:
  - Validación mejorada para manejo de NULL
  - Fallback alternativo para obtener restricciones

### 3. Nuevos Archivos de Migración

- [backend/scripts/001_add_puesto_trabajo_id_column.sql](backend/scripts/001_add_puesto_trabajo_id_column.sql) - Script SQL
- [backend/scripts/migrate_add_puesto_column.php](backend/scripts/migrate_add_puesto_column.php) - Script PHP ejecutable

## Validación

Después de aplicar los cambios, las restricciones de puesto específico deberían funcionar correctamente:

1. Un trabajador con restricción "Puesto específico → Puesto X" NO recibirá asignaciones en el Puesto X
2. Ese mismo trabajador SÍ podrá recibir asignaciones en otros puestos
3. La asignación automática respetará las restricciones al seleccionar trabajadores disponibles

## Testing

Para verificar que funciona correctamente:

1. Crear una restricción de puesto específico para un trabajador
2. Ejecutar la asignación automática
3. Verificar en la grilla mensual que el trabajador NO tiene turnos en el puesto restringido
4. Verificar que TIENE turnos en otros puestos disponibles
