-- Migración: Agregar columna puesto_trabajo_id a restricciones_trabajador
-- Descripción: Esta columna es necesaria para que las restricciones de puesto específico funcionen en la asignación automática

-- Verificar si la columna ya existe (MySQL)
-- Si no existe, agregarla
ALTER TABLE `restricciones_trabajador` 
ADD COLUMN `puesto_trabajo_id` INT NULL 
AFTER `tipo_restriccion`;

-- Agregar foreign key si no existe (esto puede variar según la BD)
-- Nota: Ejecutar manualmente si es necesario
-- ALTER TABLE `restricciones_trabajador`
-- ADD CONSTRAINT `fk_restricciones_puesto` 
-- FOREIGN KEY (`puesto_trabajo_id`) 
-- REFERENCES `puestos_trabajo`(`id`) ON DELETE SET NULL;
