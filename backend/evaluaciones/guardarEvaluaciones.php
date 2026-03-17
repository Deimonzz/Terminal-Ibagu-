<?php
require __DIR__ . '/../../config/db.php';
require __DIR__ . '/../../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ✅ Función para enviar correo
function enviarCorreo($destino, $asunto, $mensaje) {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'system@terminalibague.com';
        $mail->Password   = 'vebl mzoq dtrj evwu'; // Clave de aplicación
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom('system@terminalibague.com', 'Sistema de Cumplimientos');
        $mail->addAddress($destino);

        $mail->isHTML(true);
        $mail->Subject = $asunto;
        $mail->Body    = $mensaje;

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("❌ Error al enviar correo a $destino: {$mail->ErrorInfo}");
        return false;
    }
}

$visita_id = $_POST['visita_id'] ?? null;
$aspectos  = json_decode($_POST['aspectos'] ?? '[]', true);

if (!$visita_id) {
    echo json_encode(['success' => false, 'error' => 'Falta visita_id']);
    exit;
}

try {
    $pdo->beginTransaction();

    foreach ($aspectos as $idx => $a) {
        $nombreArchivo = null;

        // Subir evidencia si viene
        if (!empty($a['evidencia']) && isset($_FILES[$a['evidencia']])) {
            $file = $_FILES[$a['evidencia']];
            if ($file['error'] === UPLOAD_ERR_OK) {
                $uploadDir = __DIR__ . "/../../uploads/hallazgos/";
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

                $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                $nombreArchivo = uniqid("evid_") . "." . $ext;
                move_uploaded_file($file['tmp_name'], $uploadDir . $nombreArchivo);
            }
        }

        // Insertar evaluación
        $sql = "INSERT INTO evaluaciones 
                (visita_id, aspecto_id, observacion, estado, recurrente, plazo, actividad, responsable, evidencia) 
                VALUES (:visita_id, :aspecto_id, :observacion, :estado, :recurrente, :plazo, :actividad, :responsable, :evidencia)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':visita_id'   => $visita_id,
            ':aspecto_id'  => $a['aspecto_id'],
            ':observacion' => $a['observacion'],
            ':estado'      => $a['estado'],
            ':recurrente'  => $a['recurrente'],
            ':plazo'       => $a['plazo'],
            ':actividad'   => $a['actividad'],
            ':responsable' => $a['responsable'],
            ':evidencia'   => $nombreArchivo
        ]);

        // ✅ Buscar correo del responsable
        if (!empty($a['responsable'])) {
            $stmtResp = $pdo->prepare("SELECT correo, nombre FROM responsables WHERE id = ?");
            $stmtResp->execute([$a['responsable']]);
            $responsable = $stmtResp->fetch(PDO::FETCH_ASSOC);

            if ($responsable && !empty($responsable['correo'])) {
                $correo = $responsable['correo'];
                $nombre = $responsable['nombre'];
                $asunto = "Nueva asignacion de cumplimiento";
                $mensaje = "
                    <h3>Hola, {$nombre} 👋</h3>
                    <p>Se te ha asignado una nueva actividad de cumplimiento.</p>
                    <p><strong>Actividad:</strong> {$a['actividad']}</p>
                    <p><strong>Plazo:</strong> {$a['plazo']}</p>
                    <p><strong>Observación:</strong> {$a['observacion']}</p>
                    <p>Por favor, revisa el sistema para más detalles.</p>
                ";

                enviarCorreo($correo, $asunto, $mensaje);
            }
        }
    }

    $pdo->commit();
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    $pdo->rollBack();
    error_log("❌ Error al guardar evaluaciones: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
