-- Migración para PostgreSQL (Render)
-- Agregar columna puesto_trabajo_id si no existe

-- En PostgreSQL, usar esta sintaxis:
ALTER TABLE restricciones_trabajador
ADD COLUMN IF NOT EXISTS puesto_trabajo_id INTEGER;

-- Agregar la foreign key si no existe (esto es más complejo en PostgreSQL)
-- Primero verificar si ya existe:
-- SELECT constraint_name FROM information_schema.table_constraints 
-- WHERE table_name='restricciones_trabajador' AND constraint_type='FOREIGN KEY';

-- Si no existe, agregar:
ALTER TABLE restricciones_trabajador
ADD CONSTRAINT fk_restricciones_puesto 
FOREIGN KEY (puesto_trabajo_id) 
REFERENCES puestos_trabajo(id) ON DELETE SET NULL;
