// js/visitas.js
async function cargarVisitas() {
  const res = await fetch("backend/visitas/listarVisitas.php");
  const visitas = await res.json();

  const contPendientes = document.getElementById("historial");
  const contCumplidas = document.getElementById("historialCumplidas");

  contPendientes.innerHTML = "";
  contCumplidas.innerHTML = "";

  visitas.forEach(v => {
    // Header (clicable) - lo mismo para ambas tablas
    const headerHtml = `
      <div class="visita-header">
        <div style="display:flex;align-items:center;gap:12px;width:100%;">
          <strong style="flex:1;">${v.nombre_visita}</strong>
          <small style="color:#666;">(${v.fecha_inicio} - ${v.fecha_fin || ''})</small>
        </div>
      </div>
    `;

    // Plantilla tablas (cabecera)
    const tablaHead = `
      <table class="table">
        <thead>
          <tr>
            <th>Aspecto</th>
            <th>Descripción</th>
            <th>Observación</th>
            <th>Estado</th>
            <th>Plazo</th>
            <th>Recurrente</th>
            <th>Actividad</th>
            <th>Responsable</th>
            <th>Evidencia hallazgo</th>
            <th>Evidencias cumplimiento</th>
          </tr>
        </thead>
        <tbody>
    `;

    // Filas separadas
    let filasPendientes = "";
    let filasCumplidas = "";

    if (!v.aspectos || v.aspectos.length === 0) {
      filasPendientes += `<tr><td colspan="10">Sin aspectos registrados</td></tr>`;
    } else {
      v.aspectos.forEach(a => {
        const estadoNormalizado = (a.estado ?? "").toString().trim().toUpperCase();
        const evalId = a.id || a.evaluacion_id || '';
        
        // Determinar clase del círculo según el estado
        const circleClass = estadoNormalizado === "CUMPLE" ? "circle-cumple" : "circle-nocumple";
        
        let responsable = a.responsable_nombre || "No asignado";
        if (a.responsable_correo) {
          responsable += `<br><small style="color:gray;">${a.responsable_correo}</small>`;
        }

        let evidenciasCumplimiento = "Sin evidencia";
        if (a.cumplimientos && a.cumplimientos.length > 0) {
          evidenciasCumplimiento = a.cumplimientos
            .map(ec => `<a href="/visitas/uploads/cumplimientos/${ec.archivo}" target="_blank">Ver</a>`)
            .join(", ");
        }

        const evidenciaHallazgo = a.evidencia_hallazgo ? 
          `<a href="/visitas/uploads/hallazgos/${a.evidencia_hallazgo}" target="_blank">Ver</a>` : 
          "Sin evidencia";

        const fila = `
          <tr>
            <td>${a.aspecto_nombre || "—"}</td>
            <td>${a.aspecto_desc || "—"}</td>
            <td>${a.observacion || "—"}</td>
            <td class="td-estado">
              <span class="circle ${circleClass}" 
                    data-id="${evalId}" 
                    data-estado="${estadoNormalizado}"
                    title="${estadoNormalizado}"></span>
            </td>
            <td>${a.plazo || "—"}</td>
            <td>${a.recurrente == 1 ? "Sí" : "No"}</td>
            <td>${a.actividad || "—"}</td>
            <td>${responsable}</td>
            <td>${evidenciaHallazgo}</td>
            <td>${evidenciasCumplimiento}</td>
          </tr>
        `;

        // Clasifica por fila (no por visita)
        if (estadoNormalizado === "CUMPLE") {
          filasCumplidas += fila;
        } else {
          // PARCIAL y NO CUMPLE y cualquier otro => pendientes
          filasPendientes += fila;
        }
      });
    }

    // Cerrar tbody/table
    const tablaPendientesHtml = tablaHead + filasPendientes + `</tbody></table>`;
    const tablaCumplidasHtml = tablaHead + filasCumplidas + `</tbody></table>`;

    // Crear nodo DOM para la visita PENDIENTE (si tiene filas pendientes)
    if (filasPendientes.trim() !== "") {
      const grupoPend = document.createElement("div");
      grupoPend.classList.add("visita-group");
      grupoPend.innerHTML = headerHtml + `<div class="visita-content">${tablaPendientesHtml}</div>`;
      // toggle
      const header = grupoPend.querySelector(".visita-header");
      const contenido = grupoPend.querySelector(".visita-content");
      header.addEventListener("click", () => {
        contenido.classList.toggle("visible");
      });
      contPendientes.appendChild(grupoPend);
    }

    // Crear nodo DOM para la visita CUMPLIDAS (si tiene filas cumplidas)
    if (filasCumplidas.trim() !== "") {
      const grupoCum = document.createElement("div");
      grupoCum.classList.add("visita-group");
      grupoCum.innerHTML = headerHtml + `<div class="visita-content">${tablaCumplidasHtml}</div>`;
      const header = grupoCum.querySelector(".visita-header");
      const contenido = grupoCum.querySelector(".visita-content");
      header.addEventListener("click", () => {
        contenido.classList.toggle("visible");
      });
      contCumplidas.appendChild(grupoCum);
    }
  });

  // Configurar los listeners para los círculos después de cargar todo
  setupEstadoToggleListeners();
}

// ===== FUNCIONALIDAD DE CAMBIO DE ESTADO =====

function setupEstadoToggleListeners() {
  document.querySelectorAll(".circle").forEach(circle => {
    circle.removeEventListener("click", handleEstadoToggle); // Limpiar listeners previos
    circle.addEventListener("click", handleEstadoToggle);
  });
}

async function handleEstadoToggle(e) {
  const el = e.currentTarget;
  const evalId = el.dataset.id;
  const estadoActual = el.dataset.estado;
  
  if (!evalId) {
    console.error("No hay ID de evaluación para actualizar");
    return;
  }
  
  // Solo permitir cambiar entre NO CUMPLE y CUMPLE
  const nuevoEstado = (estadoActual === "NO CUMPLE") ? "CUMPLE" : "NO CUMPLE";

  // Actualizar UI inmediatamente
  updateEstadoUI(el, nuevoEstado);

  try {
    const resp = await fetch("backend/evidencias/updateEstado.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ id: evalId, estado: nuevoEstado })
    });
    
    const json = await resp.json();

    if (!json.success) {
      // Revertir cambios si falla
      updateEstadoUI(el, estadoActual);
      alert("Error guardando estado en BD: " + (json.error ?? "desconocido"));
      return;
    }

    // Si pasa a CUMPLE, recargar la página para actualizar las pestañas
    if (nuevoEstado === "CUMPLE") {
      setTimeout(() => {
        cargarVisitas(); // Recargar para actualizar la vista
      }, 500);
    }

  } catch (err) {
    console.error("Error en fetch updateEstado:", err);
    updateEstadoUI(el, estadoActual);
    alert("Error al actualizar estado (ver consola).");
  }
}

function updateEstadoUI(element, estado) {
  element.dataset.estado = estado;
  element.title = estado;
  element.classList.remove("circle-cumple", "circle-nocumple", "circle-parcial", "circle-noexiste");
  
  // Solo manejamos dos estados: CUMPLE (verde) y NO CUMPLE (rojo)
  if (estado === "CUMPLE") {
    element.classList.add("circle-cumple");
  } else {
    element.classList.add("circle-nocumple");
  }
}

/* 🔹 Botones de pestañas */
document.getElementById("btnPendientes").addEventListener("click", () => {
  document.getElementById("historial").style.display = "block";
  document.getElementById("historialCumplidas").style.display = "none";
  document.getElementById("btnPendientes").classList.add("active");
  document.getElementById("btnCumplidas").classList.remove("active");
});

document.getElementById("btnCumplidas").addEventListener("click", () => {
  document.getElementById("historial").style.display = "none";
  document.getElementById("historialCumplidas").style.display = "block";
  document.getElementById("btnCumplidas").classList.add("active");
  document.getElementById("btnPendientes").classList.remove("active");
});

// Inicializar
document.addEventListener("DOMContentLoaded", function() {
  cargarVisitas();
});