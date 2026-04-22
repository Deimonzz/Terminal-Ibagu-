<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require __DIR__ . '/../config/db.php';  // tu conexión PDO

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/../vendor/autoload.php'; // Ajusta la ruta si tu vendor está en otro lado

// ✅ Función para enviar correo
function enviarCorreo($destino, $asunto, $mensaje) {
    $mail = new PHPMailer(true);

    try {
        // Configuración SMTP (ejemplo con Gmail)
        $mail->isSMTP();
        $mail->Host       = 'smtp.terminalibague.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'system@terminalibague.com';   // 👉 cambia aquí
        $mail->Password   = 'vebl mzoq dtrj evwu';     // 👉 clave de aplicación, no la personal
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        // Remitente
        $mail->setFrom('system@terminalibague.com', 'Sistema de Cumplimientos');

        // Destinatario
        $mail->addAddress($destino);

        // Contenido
        $mail->isHTML(true);
        $mail->Subject = $asunto;
        $mail->Body    = $mensaje;

        $mail->send();
        echo "📧 Correo enviado a $destino<br>";
    } catch (Exception $e) {
        echo "❌ Error al enviar correo: {$mail->ErrorInfo}<br>";
    }
}

// ✅ Función: buscar evaluaciones próximas a vencerse (30 o 15 días)
function notificarVencimientos($pdo) {
    $sql = "SELECT e.id, e.plazo, e.estado, r.nombre, r.correo,
                   " . Database::dateDiff('e.plazo', Database::currentDate()) . " AS dias_restantes
            FROM evaluaciones e
            JOIN responsables r ON e.responsable = r.id
            WHERE e.estado != 'CUMPLE' AND e.plazo IS NOT NULL";
    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        if ($row['dias_restantes'] == 30 || $row['dias_restantes'] == 15) {
            $mensaje = "
                <p>Hola <b>{$row['nombre']}</b>,</p>
                <p>Te recordamos que tienes <b>{$row['dias_restantes']} días</b> para cumplir con el aspecto asignado
                (Evaluación #{$row['id']}).</p>
                <p>Plazo límite: {$row['plazo']}</p>
                <p><i>Este es un recordatorio automático del sistema.</i></p>
            ";
            enviarCorreo($row['correo'], "Aviso de cumplimiento pendiente", $mensaje);
        } else {
            echo "⏳ Evaluación {$row['id']} no cumple la condición (faltan {$row['dias_restantes']} días)<br>";
        }
    }

    echo "✅ Revisión de notificaciones finalizada<br>";
}


notificarVencimientos($pdo);
