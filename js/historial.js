// historial.js - versión final con agrupamiento por observación+actividad
// Incluye: pestañas, agrupamiento, botones de upload/reemplazo y lógica para propagar evidencia

document.addEventListener("DOMContentLoaded", () => {
  setupTabControls(); // configurar pestañas antes de cargar
  loadHistorial();
});

const ESTADOS = {
  CUMPLE: 'CUMPLE',
  NO_CUMPLE: 'NO CUMPLE',
  PARCIAL: 'PARCIAL',
  NO_EXISTE: 'NOEXISTE'
};

const TIPOS_EVIDENCIA = {
  HALLAZGO: 'hallazgo',
  CUMPLIMIENTO: 'cumplimiento'
};

const RUTAS = {
  HALLAZGO: '/visitas/uploads/hallazgos/',
  CUMPLIMIENTO: '/visitas/uploads/cumplimientos/'
};

const ENDPOINTS = {
  HALLAZGO: 'backend/evidencias/uploadHallazgo.php',
  CUMPLIMIENTO: 'backend/evidencias/uploadEvidencia.php',
  // endpoint recomendado para propagar evidencia ya subida a múltiples evaluaciones (debes implementarlo en backend)
  PROPAGAR: 'backend/evidencias/propagarEvidencia.php'
};



/* ------------------ helpers ------------------ */

// helper: devolver YYYY-MM-DD (si viene con rango 'inicio - fin' devuelve inicio si es fecha sola)
function formatDateRaw(dateStr) {
  if (!dateStr) return "";

  const d = new Date(dateStr);
  if (isNaN(d)) return dateStr; // fallback seguro

  const yyyy = d.getFullYear();
  const mm = String(d.getMonth() + 1).padStart(2, "0");
  const dd = String(d.getDate()).padStart(2, "0");

  return `${yyyy}-${mm}-${dd}`;
}


function applyTextCollapse(element, maxChars = 160) {
  if (!element) return;

  // tomar texto "limpio" (no HTML) para decidir truncado
  const fullText = element.innerText.trim();
  if (fullText.length <= maxChars) return;

  const shortText = fullText.slice(0, maxChars).trim() + "...";

  // montar HTML seguro (escapeHtml para evitar XSS)
  element.innerHTML = `
    <div class="collapse-wrap">
      <div class="short">${escapeHtml(shortText)}</div>
      <div class="full" style="display:none">${escapeHtml(fullText)}</div>
      <button class="btn-ver-mas">Ver más</button>
    </div>
  `;

  const btn = element.querySelector(".btn-ver-mas");
  const shortEl = element.querySelector(".short");
  const fullEl = element.querySelector(".full");

  btn.addEventListener("click", () => {
    const expanded = fullEl.style.display === "block";
    fullEl.style.display = expanded ? "none" : "block";
    shortEl.style.display = expanded ? "block" : "none";
    btn.textContent = expanded ? "Ver más" : "Ver menos";
  });
}


// ===================== NORMALIZAR TEXTO =====================
function normalizeTextForMatch(str) {
  if (!str) return "";
  return str
    .toString()
    .trim()
    .toLowerCase()
    // quitar tildes
    .normalize("NFD").replace(/[\u0300-\u036f]/g, "")
    // sustituir caracteres no alfanuméricos por espacio (preserva letras y números)
    .replace(/[^a-z0-9\s]/g, " ")
    // colapsar múltiples espacios
    .replace(/\s+/g, " ")
    // quitar espacios extremos otra vez
    .trim();
}

// ===================== LEVENSHTEIN (distancia) =====================
function levenshtein(a, b) {
  if (a === b) return 0;
  const al = a.length, bl = b.length;
  if (al === 0) return bl;
  if (bl === 0) return al;

  const v0 = new Array(bl + 1);
  const v1 = new Array(bl + 1);

  for (let i = 0; i <= bl; i++) v0[i] = i;

  for (let i = 0; i < al; i++) {
    v1[0] = i + 1;
    for (let j = 0; j < bl; j++) {
      const cost = a[i] === b[j] ? 0 : 1;
      v1[j + 1] = Math.min(
        v1[j] + 1,          // inserción
        v0[j + 1] + 1,      // eliminación
        v0[j] + cost        // sustitución
      );
    }
    for (let k = 0; k <= bl; k++) v0[k] = v1[k];
  }
  return v1[bl];
}

// ===================== SIMILITUD (0..1) =====================
function similarityScore(a, b) {
  if (!a && !b) return 1;
  if (!a || !b) return 0;
  const la = a.length, lb = b.length;
  const max = Math.max(la, lb);
  if (max === 0) return 1;
  const dist = levenshtein(a, b);
  return 1 - (dist / max); // 1 = idéntico, 0 = totalmente distinto
}

function getEvalId(ev) {
  return ev.id ?? ev.eval_id ?? ev.evalId ?? ev.EVAL_ID ?? null;
}

function normalizeEstado(raw) {
  return (raw ?? "").toString().trim().toUpperCase();
}

function escapeHtml(text) {
  if (text == null) return "";
  return text.toString().replace(/[&<>"'`=\/]/g, function(s) {
    return ( { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;', '/':'&#x2F;', '`':'&#96;','=':'&#61;' } )[s];
  });
}

function createThumbnailElement(src, title = "") {
  const a = document.createElement("a");
  a.href = src;
  a.target = "_blank";
  a.title = title;
  const img = document.createElement("img");
  img.src = src;
  img.alt = title || "Evidencia";
  img.style.cssText = "width:56px;height:56px;object-fit:cover;border-radius:6px;margin-right:6px";
  a.appendChild(img);
  return a;
}

function createThumbnailHTML(src, title = "") {
  return `<img src="${src}" alt="${title}" title="${title}" style="width:56px;height:56px;object-fit:cover;border-radius:6px;margin-right:6px">`;
}

function getEstadoClass(estado, prefix = 'circle') {
  const normalized = normalizeEstado(estado);
  switch (normalized) {
    case ESTADOS.CUMPLE: return `${prefix}-cumple`;
    case ESTADOS.NO_CUMPLE: return `${prefix}-nocumple`;
    case ESTADOS.PARCIAL: return `${prefix}-parcial`;
    default: return `${prefix}-noexiste`;
  }
}

function createUploadButton(evalId, tipo, targetCell, text = "📎", title = "Subir evidencia") {
  const uploadBtn = document.createElement("button");
  uploadBtn.textContent = text;
  uploadBtn.title = title;
  uploadBtn.className = `btn-upload-${tipo}`;
  uploadBtn.addEventListener("click", () => triggerUpload(evalId, tipo, targetCell));
  return uploadBtn;
}

function updateHallazgoCell(evalId, targetCell, fileName) {
  const src = `${RUTAS.HALLAZGO}${fileName}?t=${Date.now()}`;
  targetCell.innerHTML = "";
  targetCell.appendChild(createThumbnailElement(src, "Evidencia hallazgo"));
  targetCell.appendChild(createUploadButton(evalId, TIPOS_EVIDENCIA.HALLAZGO, targetCell, "🔁", "Reemplazar evidencia"));
}

function updateCumplimientoCell(evalId, targetCell, fileName) {
  const src = `${RUTAS.CUMPLIMIENTO}${fileName}?t=${Date.now()}`;
  const thumb = createThumbnailElement(src, "Evidencia cumplimiento");
  const existingUploadBtn = targetCell.querySelector(`.btn-upload-${TIPOS_EVIDENCIA.CUMPLIMIENTO}`);
  existingUploadBtn ? targetCell.insertBefore(thumb, existingUploadBtn) : targetCell.appendChild(thumb);
}

/* ------------------ pestañas (Pendientes / Cumplidas) ------------------ */
function setupTabControls() {
  // Asegúrate de que existan los elementos en tu HTML: btnPendientes, btnCumplidas, historial, historialCumplidas
  const btnP = document.getElementById("btnPendientes");
  const btnC = document.getElementById("btnCumplidas");
  const hist = document.getElementById("historial");
  const histC = document.getElementById("historialCumplidas");

  // Si no existen, no fallar: crear controles mínimos (útil para testing)
  if (!btnP || !btnC || !hist || !histC) {
    // no hacer nada si no está el HTML preparado; evitar errores
    return;
  }

  btnP.addEventListener("click", () => {
    btnP.classList.add("active");
    btnC.classList.remove("active");
    hist.style.display = "block";
    histC.style.display = "none";
  });

  btnC.addEventListener("click", () => {
    btnC.classList.add("active");
    btnP.classList.remove("active");
    histC.style.display = "block";
    hist.style.display = "none";
  });
}

/* ------------------ loadHistorial ------------------ */
async function loadHistorial() {
  try {
    const res = await fetch("backend/aspectos/listarHistorialGrouped.php");
    if (!res.ok) throw new Error("Error al pedir historial: " + res.status);
    const data = await res.json() || [];

    // guardamos copia para uso posterior en propagación
    LAST_HISTORIAL_DATA = data;

function poblarFiltros(data) {
  const visitasSet = new Set();
  const responsablesSet = new Set();

  data.forEach(a => {
    (a.evaluaciones || []).forEach(ev => {
      if (ev.nombre_visita) visitasSet.add(ev.nombre_visita);
      if (ev.responsable) responsablesSet.add(ev.responsable);
    });
  });

  const selVisita = document.getElementById("filtroVisita");
  const selResp = document.getElementById("filtroResponsable");

  visitasSet.forEach(v => {
    selVisita.appendChild(new Option(v, v));
  });

  responsablesSet.forEach(r => {
    selResp.appendChild(new Option(r, r));
  });
}

    // Renderizar secciones
    renderHistorialPendientes(data);
    renderHistorialCumplidas(data);
    poblarFiltros(data);
    // Activar toggles de estado
    setupEstadoToggleListeners();

  } catch (err) {
    console.error("loadHistorial error:", err);
    const container = document.getElementById("historial");
    if (container)
      container.innerHTML = `<p style="color:red">Error cargando historial: ${escapeHtml(err.message)}</p>`;
  }
}

/* ------------------ tabla / fila de evaluación (compatibilidad) ------------------ */
function createTableTemplate() {
  const table = document.createElement("table");
  table.classList.add("table");
  table.innerHTML = `<thead><tr><th>Fechas</th><th>Observación</th><th>Estado</th><th>Plazo</th><th>Recurrente</th><th>Actividad</th><th>Responsable</th><th>Evidencia Hallazgo</th><th>Evidencias Cumplimiento</th><th>Acciones</th></thead><tbody></tbody>`;
  return table;
}

function createEvaluationRow(ev) {
  const evalId = getEvalId(ev);
  const estado = normalizeEstado(ev.estado);
  const circleClass = getEstadoClass(estado, 'circle');
  const evidenciaHallazgoFile = ev.evidencia_hallazgo ?? ev.evidencia ?? ev.hallazgo ?? null;
  const cumplimientos = Array.isArray(ev.cumplimientos) ? ev.cumplimientos : (ev.cumplimientos || []);

  const row = document.createElement("tr");
  row.dataset.evalId = evalId ?? "";
  row.innerHTML = `<td>${ev.fecha_inicio || ""}${ev.fecha_fin ? " - " + ev.fecha_fin : ""}</td><td>${ev.nombre_visita || ""}</td><td>${escapeHtml(ev.observacion) || ""}</td><td class="td-estado"><span class="circle ${circleClass}" data-id="${evalId ?? ''}" data-estado="${estado}" title="${estado}"></span></td><td>${ev.plazo || ""}</td><td>${ev.recurrente == 1 ? "Sí" : "No"}</td><td>${escapeHtml(ev.actividad) || ""}</td><td>${escapeHtml(ev.responsable) || ""}</td><td class="td-evidencia-hallazgo" id="hallazgo-${evalId}"></td><td class="td-evidencias-cumplimiento" id="cumplimientos-${evalId}"></td><td class="td-acciones" id="acciones-${evalId}"></td>`;

  const hallazgoCell = row.querySelector(`#hallazgo-${evalId}`);
  const cumplCell = row.querySelector(`#cumplimientos-${evalId}`);
  const accionesCell = row.querySelector(`#acciones-${evalId}`);

  hallazgoCell.innerHTML = "";
  if (evidenciaHallazgoFile) {
    const src = `${RUTAS.HALLAZGO}${evidenciaHallazgoFile}`;
    hallazgoCell.appendChild(createThumbnailElement(src, "Evidencia hallazgo"));
    hallazgoCell.appendChild(createUploadButton(evalId, TIPOS_EVIDENCIA.HALLAZGO, hallazgoCell, "🔁", "Reemplazar evidencia"));
  } else {
    hallazgoCell.appendChild(createUploadButton(evalId, TIPOS_EVIDENCIA.HALLAZGO, hallazgoCell));
  }

  cumplCell.innerHTML = "";
  cumplimientos.forEach(c => {
    const archivo = c.archivo ?? c.filename ?? c.file ?? null;
    if (archivo) {
      const src = `${RUTAS.CUMPLIMIENTO}${archivo}`;
      cumplCell.appendChild(createThumbnailElement(src, c.uploaded_at ?? "Evidencia cumplimiento"));
    }
  });
  cumplCell.appendChild(createUploadButton(evalId, TIPOS_EVIDENCIA.CUMPLIMIENTO, cumplCell));

  accionesCell.innerHTML = `<button class="btn-ver" data-id="${evalId}">Ver</button>`;
  return row;
}

/* ------------------ AGRUPAR: observación + actividad (manteniendo solo 1 evidencia por tipo) ------------------ */
function groupEvaluacionesPorObservacionActividad(evaluaciones) {
  const THRESHOLD_SIMILARITY = 0.85; // ajústalo: 0.9 más estricto, 0.75 más flexible

  // 1) mapa preliminar por key normalizada exacta
  const map = {};

  (evaluaciones || []).forEach(ev => {
    const obsRaw = ev.observacion ?? "";
    const actRaw = ev.actividad ?? "";
    const obsNorm = normalizeTextForMatch(obsRaw);
    const actNorm = actRaw; // ya NO se usa para agrupar
    const key = obsNorm;

    if (!map[key]) {
      map[key] = {
        key,
        obsNorm,
        actNorm,
        observacionOriginals: new Set(), // guardar originales para referencia
        actividadOriginals: new Set(),
        visitas: new Set(),
        fechas: new Set(),
        plazos: [],
        estados: [],
        responsables: new Set(),
        evidencia_hallazgo: null,
        evidencia_cumplimiento: null,
        evaluaciones: []
      };
    }

    const g = map[key];

    const visita = (ev.nombre_visita ?? ev.visita_nombre ?? "").toString().trim();
    if (visita) g.visitas.add(visita);

    if (ev.fecha_inicio || ev.fecha_fin) {
      const f = (ev.fecha_inicio ?? "") + (ev.fecha_fin ? " - " + ev.fecha_fin : "");
      g.fechas.add(f);
    }

    if (ev.plazo) g.plazos.push(ev.plazo);
    if (ev.estado) g.estados.push(normalizeEstado(ev.estado));
    if (ev.responsable) g.responsables.add(ev.responsable);

    if (ev.evidencia_hallazgo) g.evidencia_hallazgo = ev.evidencia_hallazgo;

    if (Array.isArray(ev.cumplimientos)) {
      ev.cumplimientos.forEach(c => {
        const file = c.archivo ?? c.filename ?? c.file ?? null;
        if (file) g.evidencia_cumplimiento = file;
      });
    }

    // Guardar originales para mostrar
    if (ev.observacion) g.observacionOriginals.add(ev.observacion);
    if (ev.actividad) g.actividadOriginals.add(ev.actividad);

    g.evaluaciones.push(ev);
  });

  // 2) convertir a array de grupos
  let groups = Object.values(map);

  // 3) fusionar grupos similares (comparar obsNorm + actNorm concatenadas)
  // algoritmo simple O(n^2) pero OK para listas moderadas
  let merged = [];
  const used = new Array(groups.length).fill(false);

  for (let i = 0; i < groups.length; i++) {
    if (used[i]) continue;
    let base = {...groups[i]}; // shallow copy
    used[i] = true;

    for (let j = i + 1; j < groups.length; j++) {
      if (used[j]) continue;

const sim = similarityScore(base.obsNorm, groups[j].obsNorm);


      if (sim >= THRESHOLD_SIMILARITY) {
        // fusionar groups[j] en base
        used[j] = true;

        // combinar sets/lists
        groups[j].visitas.forEach(v => base.visitas.add(v));
        groups[j].fechas.forEach(f => base.fechas.add(f));
        base.plazos = base.plazos.concat(groups[j].plazos || []);
        base.estados = base.estados.concat(groups[j].estados || []);
        groups[j].responsables.forEach(r => base.responsables.add(r));
        groups[j].observacionOriginals.forEach(o => base.observacionOriginals.add(o));
        groups[j].actividadOriginals.forEach(a => base.actividadOriginals.add(a));
        if (groups[j].evidencia_hallazgo) base.evidencia_hallazgo = groups[j].evidencia_hallazgo;
        if (groups[j].evidencia_cumplimiento) base.evidencia_cumplimiento = groups[j].evidencia_cumplimiento;
        base.evaluaciones = base.evaluaciones.concat(groups[j].evaluaciones || []);
        // actualizar norm strings: mantener la más "larga" o la de base (no crítico)
        // base.obsNorm = base.obsNorm.length >= groups[j].obsNorm.length ? base.obsNorm : groups[j].obsNorm;
        // base.actNorm = base.actNorm.length >= groups[j].actNorm.length ? base.actNorm : groups[j].actNorm;
      }
    }

    merged.push(base);
  }

  // 4) transformar merged en la estructura que usaba antes (escoger observación/actividad a mostrar)
  const hoy = new Date();
  return merged.map(g => {
    // escoger una observación original (la más frecuente o la primera)
    const observacion = Array.from(g.observacionOriginals)[0] ?? g.obsNorm;
    const actividad = Array.from(g.actividadOriginals)[0] ?? g.actNorm;

    // plazo más cercano
    let plazoMasCercano = "";
    if (g.plazos.length > 0) {
      plazoMasCercano = g.plazos.reduce((best, cur) => {
        if (!best) return cur;
        try {
          return (Math.abs(new Date(cur) - hoy) < Math.abs(new Date(best) - hoy)) ? cur : best;
        } catch {
          return best;
        }
      }, null) || "";
    }

    const order = { "NO CUMPLE": 3, "PARCIAL": 2, "CUMPLE": 1, "NOEXISTE": 0, "": 0 };
    let peor = g.estados.length ? g.estados.reduce((a, b) => (order[b] > order[a] ? b : a), g.estados[0]) : "NO CUMPLE";

    return {
      observacion: observacion,
      actividad: actividad,
      visitas: Array.from(g.visitas),
      fechas: Array.from(g.fechas),
      plazo: plazoMasCercano,
      estado: peor,
      responsables: Array.from(g.responsables),
      evidencia_hallazgo: g.evidencia_hallazgo,
      evidencia_cumplimiento: g.evidencia_cumplimiento,
      evaluaciones: g.evaluaciones
    };
  });
}



function createGroupedRowAccordion(group) {
  const firstEvalId = getEvalId(group.evaluaciones?.[0]);
  const row = document.createElement("tr");
  row.classList.add("grouped-row");

  const circleClass = getEstadoClass(group.estado, "circle");
  const respHTML = (group.responsables || []).map(r => escapeHtml(r)).join(", ");

  // --- calcular última fecha (usar formatDateRaw)
  const ultimaFecha = (() => {
    try {
      const fechasOrdenadas = group.evaluaciones
        .map(ev => ev.fecha_fin || ev.fecha_inicio)
        .filter(f => f)
        .map(f => formatDateRaw(f))
        .sort((a,b) => new Date(b) - new Date(a));
      return fechasOrdenadas[0] || "";
    } catch {
      return "";
    }
  })();

  // badges
  const totalVisitas = (group.visitas || group.evaluaciones || []).length;
  const badgesHTML = `
    <div class="badges-resumen">
      <span class="badge badge-info">${totalVisitas} visitas</span>
      <span class="badge badge-light">Últ: ${escapeHtml(ultimaFecha || "—")}</span>
    </div>
  `;

  // evidencia hallazgo
  let hallazgoHTML = "";
  if (group.evidencia_hallazgo) {
    const src = `${RUTAS.HALLAZGO}${group.evidencia_hallazgo}`;
    hallazgoHTML = `<a href="${src}" target="_blank">${createThumbnailHTML(src)}</a><button class="btn-upload-hallazgo" data-id="${firstEvalId}">🔁</button>`;
  } else {
    hallazgoHTML = `<button class="btn-upload-hallazgo" data-id="${firstEvalId}">📎</button>`;
  }

  // evidencia cumplimiento
  let cumplimientoHTML = "";
  if (group.evidencia_cumplimiento) {
    const src = `${RUTAS.CUMPLIMIENTO}${group.evidencia_cumplimiento}`;
    cumplimientoHTML = `<a href="${src}" target="_blank">${createThumbnailHTML(src)}</a><button class="btn-upload-cumplimiento" data-id="${firstEvalId}">🔁</button>`;
  } else {
    cumplimientoHTML = `<button class="btn-upload-cumplimiento" data-id="${firstEvalId}">📎</button>`;
  }

  // fila principal compacta (sin columna 'visita' ni listado largo)
  row.innerHTML = `
    <td>${escapeHtml(ultimaFecha)}</td>
   <td class="col-observacion">
  <div class="obs-text">${escapeHtml(group.observacion)}</div>
  ${badgesHTML}
</td>
    <td><span class="circle ${circleClass}" data-id="${firstEvalId}" title="${escapeHtml(group.estado)}"></span></td>
    <td>${escapeHtml(group.plazo)}</td>
    <td>No</td>
    <td class="col-actividad">${escapeHtml(group.actividad)}</td>
    <td>${respHTML}</td>
    <td>${hallazgoHTML}</td>
    <td>${cumplimientoHTML}</td>
    <td><button class="btn-details-toggle" data-id="${firstEvalId}">▼</button></td>
  `;

  // aplicar "ver más" con tu helper (mejorado) - se ejecuta inmediatamente
 const obsTd = row.querySelector(".col-observacion .obs-text");
  const actTd = row.querySelector(".col-actividad");
  if (obsTd) applyTextCollapse(obsTd, 140);
  if (actTd) applyTextCollapse(actTd, 140);

  // PANEL DETALLADO (segunda fila, compacto)
  const detailsRow = document.createElement("tr");
  detailsRow.classList.add("details-panel-row");
  const td = document.createElement("td");
  td.colSpan = 11;
  td.appendChild(createDetailsPanel(group));
  detailsRow.appendChild(td);
  detailsRow.style.display = "none";

  // toggle
  row.querySelector(".btn-details-toggle")
    .addEventListener("click", () => {
      const visible = detailsRow.style.display === "table-row";
      detailsRow.style.display = visible ? "none" : "table-row";
    });

  return { row, detailsRow };
}


function createDetailsPanel(group) {
  const container = document.createElement("div");
  container.classList.add("details-panel");

  // Mostrar solo resumen sencillo + botón "ver todas" que abre modal (si quieres)
  const itemsHTML = group.evaluaciones.map(ev => {
    const fecha = `${formatDateRaw(ev.fecha_inicio)}${ev.fecha_fin ? ' - ' + formatDateRaw(ev.fecha_fin) : ''}`;
    return `
      <div class="details-item-row">
        <div class="details-item-left"><b>Visita:</b> ${escapeHtml(ev.nombre_visita || '—')}</div>
        <div class="details-item-right"><b>Fecha:</b> ${escapeHtml(fecha)}</div>
      </div>
    `;
  }).join("");

  container.innerHTML = `
    <div class="details-title">Visitas relacionadas (${group.evaluaciones.length})</div>
    <div class="details-list">
      ${itemsHTML}
    </div>
  `;

  return container;
}


/* ------------------ RENDER Pendientes ------------------ */
function renderHistorialPendientes(data) {
  const container = document.getElementById("historial");
  if (!container) return;
  container.innerHTML = "";

  data.forEach(group => {
    let evaluaciones = (group.evaluaciones || []).filter(ev => normalizeEstado(ev.estado) !== ESTADOS.CUMPLE);
    if (evaluaciones.length === 0) return;

    const estadoGeneral = calcularEstadoGeneral(evaluaciones);
    const estadoClass = getEstadoClass(estadoGeneral, 'badge');

    const aspectDiv = document.createElement("div");
    aspectDiv.classList.add("aspect-group");

    const header = document.createElement("h3");
    header.classList.add("aspect-header");
    header.innerHTML = `${escapeHtml(group.nombre)}`;

    const content = document.createElement("div");
    content.classList.add("aspect-content");

    const table = createTableTemplate();
    const tbody = table.querySelector("tbody");

    const grupos = groupEvaluacionesPorObservacionActividad(evaluaciones);
grupos.forEach(g => {
  const { row, detailsRow } = createGroupedRowAccordion(g);
  tbody.appendChild(row);
  tbody.appendChild(detailsRow);
});


    content.appendChild(table);
    header.addEventListener("click", () => content.classList.toggle("visible"));

    aspectDiv.appendChild(header);
    aspectDiv.appendChild(content);
    container.appendChild(aspectDiv);
  });

  setupEstadoToggleListeners();
}

/* ------------------ RENDER Cumplidas ------------------ */
function renderHistorialCumplidas(data) {
  const container = document.getElementById("historialCumplidas");
  if (!container) return;
  container.innerHTML = "";

  data.forEach(group => {
    let evaluaciones = (group.evaluaciones || []).filter(ev => normalizeEstado(ev.estado) === ESTADOS.CUMPLE);
    if (evaluaciones.length === 0) return;

    const aspectDiv = document.createElement("div");
    aspectDiv.classList.add("aspect-group");

    const header = document.createElement("h3");
    header.classList.add("aspect-header");
    header.innerHTML = `${escapeHtml(group.nombre)}`;

    const content = document.createElement("div");
    content.classList.add("aspect-content");

    const table = createTableTemplate();
    const tbody = table.querySelector("tbody");

    const grupos = groupEvaluacionesPorObservacionActividad(evaluaciones);
grupos.forEach(g => {
  const { row, detailsRow } = createGroupedRowAccordion(g);
  tbody.appendChild(row);
  tbody.appendChild(detailsRow);
});


    content.appendChild(table);
    header.addEventListener("click", () => content.classList.toggle("visible"));

    aspectDiv.appendChild(header);
    aspectDiv.appendChild(content);
    container.appendChild(aspectDiv);
  });

  setupEstadoToggleListeners();
}

/* ------------------ calcular estado general ------------------ */
function calcularEstadoGeneral(evaluaciones) {
  const estados = evaluaciones.map(ev => normalizeEstado(ev.estado));
  if (estados.every(est => est === ESTADOS.CUMPLE)) return ESTADOS.CUMPLE;
  if (estados.some(est => est === ESTADOS.PARCIAL)) return ESTADOS.PARCIAL;
  if (estados.every(est => est === ESTADOS.NO_EXISTE)) return ESTADOS.NO_EXISTE;
  return ESTADOS.NO_CUMPLE;
}

/* ------------------ Estado toggle ------------------ */
function setupEstadoToggleListeners() {
  document.querySelectorAll(".circle").forEach(circle => {
    try {
      circle.removeEventListener("click", handleEstadoToggle);
    } catch(e) {}
    circle.addEventListener("click", handleEstadoToggle);
  });
}

async function handleEstadoToggle(e) {
  const el = e.currentTarget;
  const evalId = el.dataset.id;
  const estadoActual = normalizeEstado(el.dataset.estado);
  const nuevoEstado = (estadoActual === ESTADOS.NO_CUMPLE) ? ESTADOS.CUMPLE : ESTADOS.NO_CUMPLE;

  updateEstadoUI(el, nuevoEstado);

  try {
    const resp = await fetch("backend/evidencias/updateEstado.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ id: evalId, estado: nuevoEstado })
    });
    const json = await resp.json();

    if (!json.success) {
      updateEstadoUI(el, estadoActual);
      alert("Error guardando estado en BD: " + (json.error ?? "desconocido"));
      return;
    }

    if (nuevoEstado === ESTADOS.CUMPLE) {
      loadHistorial();
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
  element.classList.add(getEstadoClass(estado, 'circle'));
}

/* ------------------ triggerUpload (modificado para propagar) ------------------ */
async function triggerUpload(evalId, tipo, targetCell) {
  try {
    const input = document.createElement("input");
    input.type = "file";
    input.accept = "image/*";
    input.click();

    input.addEventListener("change", async () => {
      const file = input.files[0];
      if (!file) return;

      // previsualización temporal
      const previewUrl = URL.createObjectURL(file);
      const tempThumb = createThumbnailElement(previewUrl, "Previsualización");

      if (tipo === TIPOS_EVIDENCIA.HALLAZGO) {
        targetCell.innerHTML = "";
        targetCell.appendChild(tempThumb);
      } else {
        const existingUploadBtn = targetCell.querySelector(`.btn-upload-${TIPOS_EVIDENCIA.CUMPLIMIENTO}`);
        existingUploadBtn ? targetCell.insertBefore(tempThumb, existingUploadBtn) : targetCell.appendChild(tempThumb);
      }

      const loadingText = document.createElement("span");
      loadingText.textContent = "Subiendo...";
      loadingText.style.marginLeft = "5px";
      targetCell.appendChild(loadingText);

      const fd = new FormData();
      fd.append("evaluacion_id", evalId);
      fd.append("evidencia", file);

      const endpoint = tipo === TIPOS_EVIDENCIA.HALLAZGO ? ENDPOINTS.HALLAZGO : ENDPOINTS.CUMPLIMIENTO;

      try {
        const resp = await fetch(endpoint, { method: "POST", body: fd });
        const json = await resp.json();
        loadingText.remove();

        if (!json.success) {
          alert("Error al subir evidencia: " + (json.error ?? "desconocido"));
          tempThumb.remove();
          if (tipo === TIPOS_EVIDENCIA.HALLAZGO) {
            targetCell.appendChild(createUploadButton(evalId, TIPOS_EVIDENCIA.HALLAZGO, targetCell));
          }
          return;
        }

        const fileName = (json.file ?? "").split('/').pop();

        // actualizar UI localmente para el grupo donde pertenece evalId
        updateGroupAfterUpload(evalId, tipo, fileName, targetCell);

        // liberar preview
        URL.revokeObjectURL(previewUrl);

      } catch (err) {
        loadingText.remove();
        tempThumb.remove();
        console.error("Error al subir evidencia:", err);
        alert("Error al subir archivo (ver consola).");
        targetCell.appendChild(createUploadButton(evalId, tipo, targetCell));
      }
    });
  } catch (err) {
    console.error("triggerUpload error:", err);
    alert("Error iniciando subida (ver consola).");
  }
}

/* ------------------ actualización / propagación tras subir ------------------ */
async function updateGroupAfterUpload(evalId, tipo, fileName, targetCell) {

  if (tipo !== TIPOS_EVIDENCIA.CUMPLIMIENTO) {
    // Si es hallazgo, NO se propaga a todas
    updateHallazgoCell(evalId, targetCell, fileName);
    return;
  }

  // 1. Encontrar a qué aspecto pertenece
  const aspecto = findGroupContainingEval(evalId);
  if (!aspecto) {
    alert("No se pudo encontrar el grupo para propagar.");
    return;
  }

  // 2. Agrupar evaluaciones del aspecto
  const grupos = groupEvaluacionesPorObservacionActividad(aspecto.evaluaciones);

  // 3. Buscar el grupo específico (observación + actividad)
  let grupoActual = null;
  for (const g of grupos) {
    if (g.evaluaciones.some(ev => getEvalId(ev) == evalId)) {
      grupoActual = g;
      break;
    }
  }

  if (!grupoActual) {
    alert("No se encontró el subgrupo para propagar evidencia.");
    return;
  }

  // 4. Obtener todos los eval_id del grupo
  const evalIds = grupoActual.evaluaciones.map(ev => getEvalId(ev));

  // 5. Llamar al backend para propagar evidencia
  try {
    const resp = await fetch(ENDPOINTS.PROPAGAR, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        filename: fileName,
        eval_ids: evalIds
      })
    });

    const json = await resp.json();

    if (!json.success) {
      console.error(json);
      alert("Error al propagar evidencia");
      return;
    }

    // 6. Recargar historial luego de propagar
    await loadHistorial();

  } catch (err) {
    console.error("Error propagando evidencia:", err);
    alert("Falló la propagación en backend");
  }
}

function findGroupContainingEval(evalId) {
  for (const aspecto of (LAST_HISTORIAL_DATA || [])) {
    if (!Array.isArray(aspecto.evaluaciones)) continue;
    if (aspecto.evaluaciones.some(ev => String(getEvalId(ev)) === String(evalId))) {
      return aspecto;
    }
  }
  return null;
}
document.addEventListener("click", function (e) {

  // ---------- HALLAZGO ----------
  if (e.target.classList.contains("btn-upload-hallazgo")) {
    const evalId = e.target.dataset.id;
    const td = e.target.closest("td");
    if (!evalId || !td) return;
    triggerUpload(evalId, TIPOS_EVIDENCIA.HALLAZGO, td);
  }

  // ---------- CUMPLIMIENTO ----------
  if (e.target.classList.contains("btn-upload-cumplimiento")) {
    const evalId = e.target.dataset.id;
    const td = e.target.closest("td");
    if (!evalId || !td) return;
    triggerUpload(evalId, TIPOS_EVIDENCIA.CUMPLIMIENTO, td);
  }

});
//exportar

document
  .getElementById("btnExportExcel")
  ?.addEventListener("click", exportarExcelProfesional);

function exportarExcelProfesional() {
  if (!Array.isArray(LAST_HISTORIAL_DATA) || !LAST_HISTORIAL_DATA.length) {
    alert("No hay datos para exportar");
    return;
  }

  const tipo = document.getElementById("exportTipo")?.value || "TODOS";

  const resumen = [];
  const detalle = [];

  /* ================= RECORRIDO PRINCIPAL ================= */
  LAST_HISTORIAL_DATA.forEach(aspecto => {
    let evaluaciones = aspecto.evaluaciones || [];

    // 🔹 Filtro por estado
    if (tipo === "PENDIENTES") {
      evaluaciones = evaluaciones.filter(
        e => normalizeEstado(e.estado) !== ESTADOS.CUMPLE
      );
    } else if (tipo === "CUMPLIDAS") {
      evaluaciones = evaluaciones.filter(
        e => normalizeEstado(e.estado) === ESTADOS.CUMPLE
      );
    }

    if (!evaluaciones.length) return;

    const grupos = groupEvaluacionesPorObservacionActividad(evaluaciones);

    grupos.forEach(g => {
      const fechas = g.evaluaciones
        .map(ev => ev.fecha_fin || ev.fecha_inicio)
        .filter(Boolean)
        .sort((a, b) => new Date(b) - new Date(a));

      /* ========= HOJA 1: RESUMEN ========= */
      resumen.push({
        "Aspecto": aspecto.nombre,
        "Observación": g.observacion,
        "Actividad a realizar": g.actividad,
        "Estado": g.estado,
        "Total visitas": g.evaluaciones.length,
        "Última visita": fechas[0] || "",
        "Plazo": g.plazo || "",
        "Responsable(s)": g.responsables.join(", ")
      });

      /* ========= HOJA 2: DETALLE ========= */
      g.evaluaciones.forEach(ev => {
        detalle.push({
          "Aspecto": aspecto.nombre,
          "Observación": g.observacion,
          "Actividad": g.actividad,
          "Visita": ev.nombre_visita || "",
          "Fecha inicio": ev.fecha_inicio || "",
          "Fecha fin": ev.fecha_fin || "",
          "Estado": ev.estado,
          "Responsable": ev.responsable || ""
        });
      });
    });
  });

  if (!resumen.length) {
    alert("No hay registros con el filtro seleccionado");
    return;
  }

  /* ================= CREAR EXCEL ================= */
  const wb = XLSX.utils.book_new();

  crearHojaResumen(wb, resumen);
  crearHojaDetalle(wb, detalle);

  const fecha = new Date().toISOString().slice(0, 10);
  XLSX.writeFile(wb, `Historial_${tipo}_${fecha}.xlsx`);
}
function crearHojaResumen(wb, data) {
  const ws = XLSX.utils.json_to_sheet(data);

  ws["!cols"] = [
    { wch: 28 },
    { wch: 55 },
    { wch: 55 },
    { wch: 16 },
    { wch: 15 },
    { wch: 18 },
    { wch: 16 },
    { wch: 30 }
  ];

  ws["!freeze"] = { ySplit: 1 };
  ws["!autofilter"] = { ref: ws["!ref"] };

  estilizarEncabezado(ws);

  XLSX.utils.book_append_sheet(wb, ws, "Resumen Hallazgos");
}
function crearHojaDetalle(wb, data) {
  // ⚠️ SheetJS NO crea hojas vacías
  if (!data.length) {
    data.push({
      "Aspecto": "",
      "Observación": "Sin registros de detalle",
      "Actividad": "",
      "Visita": "",
      "Fecha inicio": "",
      "Fecha fin": "",
      "Estado": "",
      "Responsable": ""
    });
  }

  const ws = XLSX.utils.json_to_sheet(data);

  ws["!cols"] = [
    { wch: 28 },
    { wch: 55 },
    { wch: 55 },
    { wch: 22 },
    { wch: 16 },
    { wch: 16 },
    { wch: 16 },
    { wch: 30 }
  ];

  ws["!freeze"] = { ySplit: 1 };
  ws["!autofilter"] = { ref: ws["!ref"] };

  estilizarEncabezado(ws);

  XLSX.utils.book_append_sheet(wb, ws, "Detalle Visitas");
}
function estilizarEncabezado(ws) {
  const range = XLSX.utils.decode_range(ws["!ref"]);

  for (let C = range.s.c; C <= range.e.c; C++) {
    const cell = ws[XLSX.utils.encode_cell({ r: 0, c: C })];
    if (!cell) continue;

    cell.s = {
      font: { bold: true },
      alignment: { horizontal: "center", vertical: "center" }
    };
  }
}


//filtros

document.getElementById("btnAplicarFiltros")
  ?.addEventListener("click", aplicarFiltros);

document.getElementById("btnLimpiarFiltros")
  ?.addEventListener("click", () => {
    document.getElementById("filtroFechaDesde").value = "";
    document.getElementById("filtroFechaHasta").value = "";
    document.getElementById("filtroVisita").value = "";
    document.getElementById("filtroResponsable").value = "";
    renderHistorialPendientes(LAST_HISTORIAL_DATA);
    renderHistorialCumplidas(LAST_HISTORIAL_DATA);
  });

function aplicarFiltros() {
  const desde = document.getElementById("filtroFechaDesde").value;
  const hasta = document.getElementById("filtroFechaHasta").value;
  const visita = document.getElementById("filtroVisita").value;
  const responsable = document.getElementById("filtroResponsable").value;

  const filtrado = LAST_HISTORIAL_DATA.map(aspecto => {
    const evals = (aspecto.evaluaciones || []).filter(ev => {

      // 📅 fechas
      const fechaEv = ev.fecha_fin || ev.fecha_inicio || "";
      if (desde && fechaEv < desde) return false;
      if (hasta && fechaEv > hasta) return false;

      // 👁️ visita
      if (visita && ev.nombre_visita !== visita) return false;

      // 👤 responsable
      if (responsable && ev.responsable !== responsable) return false;

      return true;
    });

    return { ...aspecto, evaluaciones: evals };
  });

  renderHistorialPendientes(filtrado);
  renderHistorialCumplidas(filtrado);
}
document.getElementById("navToggle")?.addEventListener("click", () => {
  document.getElementById("navLinks")?.classList.toggle("show");
});
