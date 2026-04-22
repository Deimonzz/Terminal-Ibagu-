<?php
// instalar_bd.php - ¡Ejecutar UNA SOLA VEZ!

// Datos de conexión a tu base de datos en Render
$host = 'dpg-d7keg17avr4c73c8au2g-a';
$port = '5432';
$dbname = 'gestion_turnos_vxle';
$user = 'gestion_turnos_vxle_user';
$password = 'mB1w3VK1c8NHwr500RzPOlr34sw1cb4g';

echo "<h1>Instalando Base de Datos en Render</h1>";

try {
    // Conectar a PostgreSQL
    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
    $conn = new PDO($dsn, $user, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<p style='color:green'>✅ Conectado a PostgreSQL exitosamente!</p>";
    
    // ============================================
    // CREAR TABLAS
    // ============================================
    
    echo "<h3>Creando tablas...</h3>";
    
    // Tabla: trabajadores
    $conn->exec("
        CREATE TABLE IF NOT EXISTS trabajadores (
            id SERIAL PRIMARY KEY,
            nombre VARCHAR(100) NOT NULL,
            cedula VARCHAR(20) NOT NULL UNIQUE,
            cargo VARCHAR(50),
            area VARCHAR(50),
            telefono VARCHAR(10),
            email VARCHAR(100),
            restricciones TEXT,
            fecha_ingreso DATE,
            activo BOOLEAN DEFAULT true,
            foto VARCHAR(255),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    echo "<p>✅ Tabla 'trabajadores' creada</p>";
    
    // Tabla: puestos_trabajo
    $conn->exec("
        CREATE TABLE IF NOT EXISTS puestos_trabajo (
            id SERIAL PRIMARY KEY,
            codigo VARCHAR(10) NOT NULL UNIQUE,
            nombre VARCHAR(100) NOT NULL,
            ubicacion VARCHAR(200),
            area VARCHAR(20) NOT NULL,
            descripcion TEXT,
            tiene_horario_especial BOOLEAN DEFAULT false,
            requiere_fuerza_fisica BOOLEAN DEFAULT false,
            requiere_movilidad BOOLEAN DEFAULT false,
            requiere_vision BOOLEAN DEFAULT false,
            activo BOOLEAN DEFAULT true,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    echo "<p>✅ Tabla 'puestos_trabajo' creada</p>";
    
    // Tabla: configuracion_turnos
    $conn->exec("
        CREATE TABLE IF NOT EXISTS configuracion_turnos (
            id SERIAL PRIMARY KEY,
            numero_turno INTEGER NOT NULL,
            nombre VARCHAR(50) NOT NULL,
            hora_inicio TIME NOT NULL,
            hora_fin TIME NOT NULL,
            horas_laborales DECIMAL(3,1) NOT NULL,
            es_nocturno BOOLEAN DEFAULT false,
            descripcion TEXT,
            activo BOOLEAN DEFAULT true,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    echo "<p>✅ Tabla 'configuracion_turnos' creada</p>";
    
    // Tabla: turnos_asignados
    $conn->exec("
        CREATE TABLE IF NOT EXISTS turnos_asignados (
            id SERIAL PRIMARY KEY,
            trabajador_id INTEGER NOT NULL REFERENCES trabajadores(id) ON DELETE CASCADE,
            puesto_trabajo_id INTEGER NOT NULL REFERENCES puestos_trabajo(id) ON DELETE CASCADE,
            turno_id INTEGER NOT NULL REFERENCES configuracion_turnos(id) ON DELETE CASCADE,
            tipo_especial VARCHAR(10),
            fecha DATE NOT NULL,
            estado VARCHAR(20) NOT NULL DEFAULT 'programado',
            observaciones TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            created_by INTEGER,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(trabajador_id, fecha)
        )
    ");
    echo "<p>✅ Tabla 'turnos_asignados' creada</p>";
    
    // Tabla: cambios_turno
    $conn->exec("
        CREATE TABLE IF NOT EXISTS cambios_turno (
            id SERIAL PRIMARY KEY,
            turno_original_id INTEGER NOT NULL REFERENCES turnos_asignados(id) ON DELETE CASCADE,
            trabajador_solicitante_id INTEGER NOT NULL REFERENCES trabajadores(id) ON DELETE CASCADE,
            trabajador_reemplazo_id INTEGER REFERENCES trabajadores(id) ON DELETE SET NULL,
            fecha_cambio DATE NOT NULL,
            motivo TEXT NOT NULL,
            tipo_cambio VARCHAR(20) NOT NULL,
            estado VARCHAR(20) DEFAULT 'pendiente',
            aprobado_por INTEGER,
            fecha_aprobacion TIMESTAMP,
            observaciones TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    echo "<p>✅ Tabla 'cambios_turno' creada</p>";
    
    // Tabla: dias_especiales
    $conn->exec("
        CREATE TABLE IF NOT EXISTS dias_especiales (
            id SERIAL PRIMARY KEY,
            trabajador_id INTEGER NOT NULL REFERENCES trabajadores(id) ON DELETE CASCADE,
            tipo VARCHAR(4) NOT NULL,
            fecha_inicio DATE NOT NULL,
            fecha_fin DATE,
            horas_inicio TIME,
            horas_fin TIME,
            descripcion TEXT,
            estado VARCHAR(20) DEFAULT 'programado',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    echo "<p>✅ Tabla 'dias_especiales' creada</p>";
    
    // Tabla: historial_turnos
    $conn->exec("
        CREATE TABLE IF NOT EXISTS historial_turnos (
            id SERIAL PRIMARY KEY,
            turno_asignado_id INTEGER,
            trabajador_id INTEGER NOT NULL,
            puesto_trabajo_id INTEGER NOT NULL,
            turno_id INTEGER NOT NULL,
            fecha DATE NOT NULL,
            accion VARCHAR(20) NOT NULL,
            usuario_id INTEGER,
            detalles TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    echo "<p>✅ Tabla 'historial_turnos' creada</p>";
    
    // Tabla: horarios_especiales_puesto
    $conn->exec("
        CREATE TABLE IF NOT EXISTS horarios_especiales_puesto (
            id SERIAL PRIMARY KEY,
            puesto_trabajo_id INTEGER NOT NULL REFERENCES puestos_trabajo(id),
            numero_turno INTEGER NOT NULL,
            nombre_turno VARCHAR(50) NOT NULL,
            hora_inicio TIME NOT NULL,
            hora_fin TIME NOT NULL,
            es_nocturno BOOLEAN DEFAULT false,
            activo BOOLEAN DEFAULT true,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(puesto_trabajo_id, numero_turno)
        )
    ");
    echo "<p>✅ Tabla 'horarios_especiales_puesto' creada</p>";
    
    // Tabla: incapacidades
    $conn->exec("
        CREATE TABLE IF NOT EXISTS incapacidades (
            id SERIAL PRIMARY KEY,
            trabajador_id INTEGER NOT NULL REFERENCES trabajadores(id) ON DELETE CASCADE,
            tipo VARCHAR(10) DEFAULT 'EG',
            fecha_inicio DATE NOT NULL,
            fecha_fin DATE NOT NULL,
            dias_incapacidad INTEGER NOT NULL,
            descripcion TEXT,
            genera_restriccion BOOLEAN DEFAULT false,
            tipo_restriccion_generada VARCHAR(50),
            restriccion_permanente BOOLEAN DEFAULT false,
            fecha_fin_restriccion DATE,
            documento_soporte VARCHAR(255),
            eps VARCHAR(100),
            estado VARCHAR(20) DEFAULT 'activa',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    echo "<p>✅ Tabla 'incapacidades' creada</p>";
    
    // Tabla: novedades_dia
    $conn->exec("
        CREATE TABLE IF NOT EXISTS novedades_dia (
            id SERIAL PRIMARY KEY,
            fecha DATE NOT NULL UNIQUE,
            contenido TEXT NOT NULL,
            usuario_id INTEGER DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    echo "<p>✅ Tabla 'novedades_dia' creada</p>";
    
    // Tabla: restricciones_trabajador
    $conn->exec("
        CREATE TABLE IF NOT EXISTS restricciones_trabajador (
            id SERIAL PRIMARY KEY,
            trabajador_id INTEGER NOT NULL REFERENCES trabajadores(id) ON DELETE CASCADE,
            tipo_restriccion VARCHAR(30) NOT NULL,
            descripcion TEXT,
            fecha_inicio DATE NOT NULL,
            fecha_fin DATE,
            documento_soporte VARCHAR(255),
            activa BOOLEAN DEFAULT true,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    echo "<p>✅ Tabla 'restricciones_trabajador' creada</p>";
    
    // Tabla: supervisores_turno
    $conn->exec("
        CREATE TABLE IF NOT EXISTS supervisores_turno (
            id SERIAL PRIMARY KEY,
            trabajador_id INTEGER NOT NULL REFERENCES trabajadores(id),
            fecha DATE NOT NULL,
            hora_inicio TIME NOT NULL,
            hora_fin TIME NOT NULL,
            usuario_id INTEGER DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    echo "<p>✅ Tabla 'supervisores_turno' creada</p>";
    
    // Crear índices
    echo "<h3>Creando índices...</h3>";
    
    $conn->exec("CREATE INDEX IF NOT EXISTS idx_turnos_asignados_fecha ON turnos_asignados(fecha)");
    $conn->exec("CREATE INDEX IF NOT EXISTS idx_turnos_asignados_estado ON turnos_asignados(estado)");
    $conn->exec("CREATE INDEX IF NOT EXISTS idx_trabajadores_cedula ON trabajadores(cedula)");
    $conn->exec("CREATE INDEX IF NOT EXISTS idx_trabajadores_activo ON trabajadores(activo)");
    $conn->exec("CREATE INDEX IF NOT EXISTS idx_puestos_trabajo_area ON puestos_trabajo(area)");
    
    echo "<p>✅ Índices creados</p>";
    
    // Mostrar resumen
    $stmt = $conn->query("SELECT COUNT(*) as total FROM pg_tables WHERE schemaname = 'public'");
    $result = $stmt->fetch();
    
    echo "<hr>";
    echo "<h2 style='color:green'>🎉 INSTALACIÓN COMPLETADA!</h2>";
    echo "<p>Total de tablas creadas: <strong>" . $result['total'] . "</strong></p>";
    echo "<p>Ahora puedes usar tu aplicación normalmente.</p>";
    echo "<p><strong>⚠️ IMPORTANTE: Elimina este archivo por seguridad.</strong></p>";
    
} catch (PDOException $e) {
    echo "<p style='color:red'>❌ ERROR: " . $e->getMessage() . "</p>";
}
?>