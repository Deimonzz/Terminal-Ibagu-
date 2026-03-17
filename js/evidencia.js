async function cargarEvaluaciones() {
  const visita = document.getElementById("filtroVisita").value;
  const estado = document.getElementById("filtroEstado").value;

  const res = await fetch("backend/evaluaciones/listarEvaluaciones.php?visita=" + visita + "&estado=" + estado);
  const data = await res.json();

  const tbody = document.getElementById("tablaEvaluaciones");
  tbody.innerHTML = "";
  

data
  .filter(ev => ev.estado === "NO CUMPLE") // <- filtrabgmbo
  .forEach(ev => {
    const tr = document.createElement("tr");
    tr.innerHTML = `
      <td>${ev.aspecto}</td>
      <td>${ev.observacion || ""}</td>
      <td>${ev.estado}</td>
      <td>
        <button class="btn btn-sm btn-success" onclick="abrirSubida(${ev.id})">
          Subir Evidencia
        </button>
      </td>
    `;
    tbody.appendChild(tr);
  });

}

function abrirSubida(evalId) {
  // Guardamos el ID en el input oculto
  document.getElementById("evaluacion_id").value = evalId;

  // Abrimos el modal
  const modal = new bootstrap.Modal(document.getElementById("modalSubirEvidencia"));
  modal.show();
}


async function cargarVisitas() {
  try {
    const res = await fetch("backend/visitas/listarVisitas.php");
    const visitas = await res.json();

    const select = document.getElementById("filtroVisita");
    select.innerHTML = `<option value="">-- Todas --</option>`;

    visitas.forEach(v => {
      const opt = document.createElement("option");
      opt.value = v.id || v.visita_id; // 👈 asegurar que usamos la clave correcta
      opt.textContent = `${v.nombre_visita} (${v.fecha_inicio})`;
      select.appendChild(opt);
    });
  } catch (err) {
    console.error("Error cargando visitas:", err);
  }
}

document.addEventListener("DOMContentLoaded", () => {
  // --- FORMULARIO SUBIDA ---
  const form = document.getElementById("formEvidencia");
  if (form && !form.dataset.listener) {  // 👈 Evita duplicado
    form.dataset.listener = "true";      // Marcamos que ya tiene listener

    form.addEventListener("submit", async (e) => {
      e.preventDefault();
      const formData = new FormData(form);

      try {
        const res = await fetch("backend/evidencias/uploadEvidencia.php", {
          method: "POST",
          body: formData,
        });
        const data = await res.json();

        if (data.success) {
          alert("✅ " + data.message);
          bootstrap.Modal.getInstance(document.getElementById("modalSubirEvidencia")).hide();
          cargarEvaluaciones(); // Recarga tabla
        } else {
          alert("❌ Error: " + data.error);
        }
      } catch (err) {
        console.error(err);
        alert("Error en la conexión con el servidor");
      }
    });
  }

  // --- MODAL ---
  const modal = document.getElementById("modalEvidencia");
  if (modal) {
    modal.addEventListener("shown.bs.modal", async () => {
      await cargarVisitas();
      await cargarEvaluaciones();
    });
  }

  // --- FILTROS ---
  const filtroVisita = document.getElementById("filtroVisita");
  if (filtroVisita) filtroVisita.addEventListener("change", cargarEvaluaciones);

  const filtroEstado = document.getElementById("filtroEstado");
  if (filtroEstado) filtroEstado.addEventListener("change", cargarEvaluaciones);
});
