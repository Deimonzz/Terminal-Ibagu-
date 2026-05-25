# Diagnóstico y Fix: Restricciones Puesto Específico en Render

## 🔍 Diagnóstico Rápido

Para verificar qué está pasando en Render, ejecuta este script en tu navegador:

```
https://tu-app.onrender.com/Terminal-Ibagu-/backend/scripts/diagnostic_restricciones.php
```

Este script te dará:
- ✅ Qué columnas existen en la tabla `restricciones_trabajador`
- ✅ Cuántas restricciones de tipo `puesto_especifico` hay
- ✅ Si los valores de puesto se están guardando (NULL vs valores reales)
- ✅ Qué columna está detectando el código (`puesto_trabajo_id` vs `puesto_id`)
- ✅ Recomendaciones basadas en lo encontrado

## 🚀 Solución Paso a Paso

### Paso 1: Agregar la Columna a Render

Ejecuta el script de migración:
```
https://tu-app.onrender.com/Terminal-Ibagu-/backend/scripts/migrate_add_puesto_column.php
```

Este script:
1. Verifica si la columna ya existe
2. La agrega si no existe (compatible con PostgreSQL)
3. Intenta agregar la Foreign Key
4. Te devuelve el status

**Respuesta exitosa se ve así:**
```json
{
  "success": true,
  "message": "Columna puesto_trabajo_id agregada exitosamente a restricciones_trabajador",
  "status": "column_added",
  "driver": "PostgreSQL",
  "foreign_key_status": "added"
}
```

### Paso 2: Verificar que Funciona

Después de agregar la columna:

1. Crea una restricción de puesto específico en el panel
2. Verifica que se guarde correctamente el puesto
3. Ejecuta la asignación automática
4. Verifica que el trabajador NO reciba turnos en el puesto restringido

## 🐛 Posibles Problemas y Soluciones

### Problema 1: La columna no existe

**Síntoma**: 
- `diagnostic_restricciones.php` devuelve `"has_puesto_trabajo_id": false` y `"has_puesto_id": false`

**Causa**: 
- Base de datos en Render no tiene la columna

**Solución**:
- Ejecutar `migrate_add_puesto_column.php`

---

### Problema 2: La columna existe pero está NULL

**Síntoma**: 
- `diagnostic_restricciones.php` devuelve muchos registros con `"puesto_value": null`
- `"data_integrity": {"with_nulls": 5, "with_values": 0}`

**Causa**: 
- Los datos se están guardando pero el puesto_trabajo_id no se captura

**Solución**:
- Verificar que el formulario envía `puesto_trabajo_id` correctamente
- Revisar la consola del navegador (F12 > Network) cuando creas una restricción
- Ver qué datos se envían en el POST a `trabajadores.php?action=restriccion`

---

### Problema 3: El código usa fallback pero aún no funciona

**Síntoma**:
- La asignación automática sigue asignando trabajadores en puestos restringidos
- No hay errores en logs

**Causa**:
- La consulta alternativa falla en PostgreSQL
- O la restricción se crea pero con los datos incorrectos

**Solución**:
- Revisar logs de error en Render: `tail -f /var/log/app.log` (si está disponible)
- Ejecutar `debug_asignacion_prefetch.php` con parámetros:
  ```
  https://tu-app.onrender.com/Terminal-Ibagu-/backend/scripts/debug_asignacion_prefetch.php?mes=5&anio=2026&puesto_id=1&fecha=2026-05-25
  ```
  (Reemplazar con mes, año, puesto_id y fecha reales)

---

## 📋 Scripts Disponibles

### 1. `diagnostic_restricciones.php`
Diagnóstico completo de la BD
```
GET /backend/scripts/diagnostic_restricciones.php
```

### 2. `migrate_add_puesto_column.php`
Agrega la columna (funciona en MySQL y PostgreSQL)
```
GET /backend/scripts/migrate_add_puesto_column.php
```

### 3. `debug_asignacion_prefetch.php`
Debug de la lógica de asignación
```
GET /backend/scripts/debug_asignacion_prefetch.php?mes=5&anio=2026&puesto_id=1&fecha=2026-05-25
```

---

## 🔧 Cambios Aplicados en el Código

### Archivo: `backend/clases/AsignacionAutomatica.php`

1. **Validación NULL mejorada** en `getDisponibles()`:
   - Antes de comparar `puesto_trabajo_id`, verificar que no sea NULL
   - Esto evita que NULL == cualquier valor sea verdadero

2. **Consulta alternativa robusta**:
   - Intenta primero con `puesto_trabajo_id`
   - Si falla, intenta con `puesto_id`
   - Cada consulta es independiente para evitar errores en PostgreSQL

3. **Lo mismo en `getDisponiblesL4()`**:
   - Aplicada la misma lógica para consistencia

### Archivo: `backend/scripts/migrate_add_puesto_column.php`

- Ahora soporta PostgreSQL (Render)
- Valida qué driver se está usando
- Retorna información de diagnóstico útil

---

## ✅ Checklist Final

- [ ] Ejecutaste `diagnostic_restricciones.php` y revisaste el output
- [ ] Si falta la columna, ejecutaste `migrate_add_puesto_column.php`
- [ ] Creaste una restricción de puesto específico (prueba)
- [ ] Ejecutaste `diagnostic_restricciones.php` nuevamente para verificar
- [ ] Realizaste una asignación automática de prueba
- [ ] Verificaste que el trabajador NO tiene turnos en el puesto restringido
- [ ] Verificaste que SÍ tiene turnos en otros puestos

---

## ❓ Preguntas Frecuentes

**P: ¿Funciona tanto en local como en Render?**
R: Sí. Los scripts ahora soportan ambos drivers (MySQL y PostgreSQL).

**P: ¿Necesito hacer cambios en el frontend?**
R: No. El frontend ya está programado para enviar `puesto_trabajo_id`. Solo necesitas asegurar que la BD lo guarde.

**P: ¿Se perderán los datos existentes?**
R: No. La migración solo agrega una columna. Será NULL para registros anteriores, pero el código tiene fallback.

**P: ¿Cuál es el parámetro correctamente para `debug_asignacion_prefetch.php`?**
R: 
- `mes`: número del mes (1-12)
- `anio`: año completo (ej: 2026)
- `puesto_id`: ID del puesto de trabajo
- `fecha`: fecha en formato YYYY-MM-DD
