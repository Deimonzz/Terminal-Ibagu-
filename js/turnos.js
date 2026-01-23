const datos = new FormData();
datos.append("empleado_id", 3);
datos.append("fecha", "2026-02-01");
datos.append("hora_inicio", "08:00");
datos.append("hora_fin", "16:00");
datos.append("tipo_turno", "Diurno");
datos.append("observacion", "Turno regular");

fetch("backend/turnos/guardarTurno.php", {
  method: "POST",
  body: datos
})
.then(res => res.json())
.then(data => {
  if (data.ok) {
    alert(data.mensaje);
  } else {
    alert("Error: " + data.mensaje);
  }
});
