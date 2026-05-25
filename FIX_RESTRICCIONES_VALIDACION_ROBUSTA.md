# Fix: Restricciones Puesto Específico - Validación Robusta

## Problema Original

Aún después de agregar la columna `puesto_trabajo_id`, la asignación automática seguía asignando algunos turnos en puestos restringidos. El usuario reportó:
- Esperaba: 0 turnos en puesto restringido
- Resultado: 3 turnos en puesto restringido

## Causa Identificada

Había un **gap en la lógica de validación**:

1. El fallback solo se ejecutaba "si no hay datos en el prefetch"
2. Pero había casos donde el prefetch sí tenía algunos datos, pero incompletos
3. La comparación en memoria no era suficiente
4. No había validación redundante en la BD

## Solución Aplicada

Cambié la lógica en dos funciones críticas de `AsignacionAutomatica.php`:

### 1. `getDisponibles()` 

**Antes**:
```php
if (!$tieneDataoPuesto) {  // Solo si NO hay datos
    // Consultar BD
}
```

**Ahora**:
```php
// SIEMPRE consultar BD como validación adicional
try {
    $stmtPuestoCheck = $this->db->prepare(
        "SELECT DISTINCT trabajador_id FROM restricciones_trabajador
         WHERE tipo_restriccion = 'puesto_especifico'
         AND activa = true
         AND fecha_inicio <= ?
         AND (fecha_fin IS NULL OR fecha_fin >= ?)
         AND (puesto_trabajo_id = ? OR puesto_id = ?)"
    );
    $stmtPuestoCheck->execute([$fecha, $fecha, $puestoId, $puestoId]);
    foreach ($stmtPuestoCheck->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $bloqueados[$row['trabajador_id']] = true;
    }
}
```

### 2. `getDisponiblesL4()`

Aplicada la misma lógica para consistencia.

### 3. Casteo de Tipos

También cambié las comparaciones para asegurar que ambos valores son enteros:

```php
// ANTES
if ($rest['puesto_trabajo_id'] !== null && $rest['puesto_trabajo_id'] == $puestoId)

// AHORA
if ($rest['puesto_trabajo_id'] !== null && (int)$rest['puesto_trabajo_id'] == (int)$puestoId)
```

## ¿Por qué funciona ahora?

1. **Validación dual**: Primero valida en memoria (con datos del prefetch), luego SIEMPRE consulta la BD
2. **Sin gaps**: Si alguna validación falla, la otra atrapa el caso
3. **Casteo seguro**: Los tipos de datos se convierten a enteros antes de comparar
4. **OR en BD**: Usa `(puesto_trabajo_id = ? OR puesto_id = ?)` para cubrir ambas columnas

## Cómo Verificar

### Opción 1: Script de Diagnóstico

```
https://tu-app.onrender.com/Terminal-Ibagu-/backend/scripts/debug_restriccion_trabajador.php?trabajador_id=5&puesto_id=2&mes=5&anio=2026
```

Reemplazar:
- `trabajador_id`: ID del trabajador con restricción
- `puesto_id`: ID del puesto restringido
- `mes`: Mes de la asignación
- `anio`: Año de la asignación

Este script mostrará:
- ✅ Restricciones del trabajador
- ✅ Turnos asignados en el mes
- ✅ Si hay turnos en el puesto restringido
- ✅ Detalles de cada turno

### Opción 2: Verificación Manual

1. Crea una restricción puesto específico
2. Ejecuta asignación automática
3. Revisa la grilla mensual
4. Busca al trabajador
5. Verifica que NO tiene turnos en el puesto restringido

## Archivos Modificados

- `backend/clases/AsignacionAutomatica.php` - Funciones `getDisponibles()` y `getDisponiblesL4()`
- `backend/scripts/debug_restriccion_trabajador.php` - Nuevo script de diagnóstico

## Nota Importante

Ahora hay **dos niveles de validación**:

1. **Validación en memoria** (del prefetch) - Rápida pero puede tener gaps
2. **Validación en BD** (consulta adicional) - Lenta pero 100% confiable

Es una estrategia de "defensa en profundidad". Si una falla, la otra lo atrapa.
