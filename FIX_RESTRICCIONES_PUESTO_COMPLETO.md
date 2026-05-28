# FIX: Restricciones de Puesto Específico - Validación Robusta

**Fecha**: 26 de Mayo de 2026  
**Estado**: ✅ IMPLEMENTADO  
**Versión**: 4 (Con validación en asignarDirecto)

## El Problema - Análisis Completo

Aunque las restricciones de puesto específico se guardaban en la BD, **la asignación automática NO las respetaba** y seguía asignando trabajadores a puestos restringidos.

### Síntomas
- Crear restricción de puesto específico para un trabajador (ej: "no puede estar en puesto F5")
- Ejecutar asignación automática del mes
- Trabajador recibe 3+ turnos en el puesto restringido (debería tener 0)

### Causa Raíz - La Verdadera Culpable 🎯

**`asignarDirecto()` en TurnosAsignados NO VALIDABA RESTRICCIONES**

```php
// ❌ PROBLEMA: asignarDirecto() saltaba TODAS las validaciones
public function asignarDirecto($datos) {
    // ... INSERTABA DIRECTAMENTE SIN VALIDAR ...
    $stmt->execute($datos_finales);  // ← Sin validarAsignacion()
}

// ✅ OK: asignar() SÍ validaba
public function asignar($datos) {
    $validacion = $this->validarAsignacion(...);  // ← Validaba aquí
    if (!$validacion['valido']) return error;
    // ... INSERTABA ...
}
```

**Y AsignacionAutomatica usaba asignarDirecto()**:
```php
// En AsignacionAutomatica.php línea 558, 635:
$resultado = $this->turnosAsignados->asignarDirecto([
    'trabajador_id' => $sel['id'],
    'puesto_trabajo_id' => $puesto['id'],
    'turno_id' => $turnoIdReal,
    'fecha' => $fecha
]);
// ↑ Esto saltaba validarAsignacion() completamente
```

### Causas Secundarias

1. **Prefetch cargaba restricciones como NULL**:
   - Si no se detectaba columna `puesto_trabajo_id`, se cargaba: `NULL as puesto_trabajo_id`
   - En memoria: comparación `NULL == $puestoId` siempre falsa
   - Trabajadores nunca se marcaban como bloqueados

2. **Consultas SQL con OR falso**:
   - `(puesto_trabajo_id = ? OR puesto_id = ?)` no maneja NULL correctamente
   - `NULL OR FALSE` = `NULL` (no coincide)

## Soluciones Implementadas

### 1. TurnosAsignados.php - asignarDirecto() Ahora Valida ⭐ (CRÍTICO)

**Antes**: 
```php
public function asignarDirecto($datos) {
    // ... INSERTABA DIRECTAMENTE ...
}
```

**Después**:
```php
public function asignarDirecto($datos) {
    // ✅ VALIDACIÓN CRÍTICA: Verificar restricciones ANTES de asignar
    $validacion = $this->validarAsignacion(
        $datos['trabajador_id'],
        $datos['puesto_trabajo_id'] ?? null,
        $datos['turno_id'] ?? null,
        $datos['fecha'] ?? null
    );
    
    if (!$validacion['valido']) {
        return ['success' => false, 'errores' => $validacion['errores']];
    }
    
    // ... SOLO INSERTA SI VALIDACIÓN PASÓ ...
}
```

**Impacto**: 
- AsignacionAutomatica ahora respeta TODAS las restricciones
- Si un trabajador tiene restricción de puesto_especifico, la validación lo rechaza
- El turno no se asigna ✅

### 2. AsignacionAutomatica.php - Prefetch Robusto (Línea 82-92)

### 2. AsignacionAutomatica.php - Prefetch Robusto (Línea 82-92)

**Antes**:
```php
$selectPuesto = "NULL as puesto_trabajo_id";  // ← Si no detecta columna
```

**Después**:
```php
// Usar COALESCE para manejar dinámicamente ambas columnas
COALESCE(puesto_trabajo_id, puesto_id) as puesto_trabajo_id
```

**Beneficio**: Restricciones se cargan con ID real, no NULL

### 3. AsignacionAutomatica.php - Validaciones en Memoria (Líneas 205-240, 309-344)

Cambiar en `getDisponibles()` y `getDisponiblesL4()`:
```php
// De: (puesto_trabajo_id = ? OR puesto_id = ?)
// A: COALESCE(puesto_trabajo_id, puesto_id) = ?
```

Con fallback robusto en caso de error SQL.

### 4. Trabajadores.php - Asegurar Columna Existe (Líneas 428-510)

Nueva función:
```php
private function asegurarColumnaRestricciones() {
    // Crea puesto_trabajo_id si no existe
}
```

Se llama automáticamente al guardar restricción de puesto_especifico.

### 5. TurnosAsignados.php - Validación en Asignación Manual (Líneas 231-278)

Validación con COALESCE + fallback igual a AsignacionAutomatica.

## Archivos Modificados

| Archivo | Líneas | Cambio |
|---------|--------|--------|
| `backend/clases/TurnosAsignados.php` | 388-450 | **asignarDirecto() ahora valida** ⭐ CRÍTICO |
| `backend/clases/AsignacionAutomatica.php` | 82-92 | Prefetch con COALESCE |
| `backend/clases/AsignacionAutomatica.php` | 205-240 | getDisponibles con COALESCE + fallback |
| `backend/clases/AsignacionAutomatica.php` | 309-344 | getDisponiblesL4 con COALESCE + fallback |
| `backend/clases/Trabajadores.php` | 428-510 | agregarRestriccion + asegurarColumnaRestricciones |
| `backend/clases/Trabajadores.php` | 511-550 | actualizarRestriccion mejorado |
| `backend/clases/TurnosAsignados.php` | 231-278 | Validación puesto_especifico con COALESCE |

## Ventajas del Fix Completo

✅ **asignarDirecto() ahora VALIDA** (fix crítico) - Sin esto, nada funciona  
✅ **Maneja NULL values correctamente** con COALESCE  
✅ **Funciona si columna no existe** (crea dinámicamente)  
✅ **Compatible MySQL + PostgreSQL**  
✅ **Logging diagnóstico** para troubleshooting  
✅ **Aplica a ambas asignaciones**: automática + manual  

## Testing

### Paso 1: Crear Restricción
1. Ir a Gestión > Trabajadores > Restricciones
2. Crear restricción "Puesto específico"
3. Seleccionar trabajador y puesto restringido
4. Guardar

### Paso 2: Asignar Automáticamente
1. Ejecutar asignación automática para el mes

### Paso 3: Verificar Resultado
```sql
-- Ver si trabajador tiene turnos en puesto restringido
SELECT COUNT(*) FROM turnos_asignados ta
INNER JOIN restricciones_trabajador r ON ta.trabajador_id = r.trabajador_id
INNER JOIN puestos_trabajo pt ON ta.puesto_trabajo_id = pt.id
WHERE r.tipo_restriccion = 'puesto_especifico'
AND COALESCE(r.puesto_trabajo_id, r.puesto_id) = ta.puesto_trabajo_id
AND r.activa = true;

-- Resultado esperado: 0 (CERO) conflictos ✅
```

## Próximos Pasos (Opcionales)

1. Crear tests unitarios para validación de restricciones
2. Dashboard de auditoría de restricciones violadas
3. Alertas automáticas si se detectan conflictos
