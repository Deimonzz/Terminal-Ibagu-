let catalogo = [];
let visitas = [];
let aspectosTemp = [];

// Cargar catálogo de aspectos
async function loadCatalogo(){
  try {
    const res = await fetch('http:///visitas/backend/aspectos/listarcatalogo.php');
    catalogo = await res.json();
    const sel = document.getElementById('sel_aspecto');
    sel.innerHTML = '<option value="">-- Seleccionar aspecto --</option>';
    
    catalogo.forEach(c => {
      let o = document.createElement('option'); 
      o.value = c.id; 
      o.text = c.nombre; 
      sel.appendChild(o);
    });
  } catch (error) {
    console.error('Error cargando catálogo:', error);
  }
}

// Cargar lista de visitas existentes
async function loadVisitas(){
  try {
    // Cambia esta URL por tu endpoint real para listar visitas
    const res = await fetch('http://localhost/visitas/backend/visitas/listarvisitas.php');
    visitas = await res.json();
    const sel = document.getElementById('sel_visita');
    sel.innerHTML = '<option value="">-- Seleccionar visita --</option>';
    
    visitas.forEach(v => {
      let o = document.createElement('option'); 
      o.value = v.id; 
      o.text = v.nombre_visita; 
      sel.appendChild(o);
    });
  } catch (error) {
    console.error('Error cargando visitas:', error);
  }
}

// Cuando se selecciona una visita existente, cargar sus datos
document.getElementById('sel_visita').addEventListener('change', function(){
  if(this.value){
    const visitaSeleccionada = visitas.find(v => v.id == this.value);
    if(visitaSeleccionada){
      document.getElementById('nombre_visita').value = '';
      document.getElementById('fecha_inicio').value = visitaSeleccionada.fecha_inicio;
      document.getElementById('fecha_fin').value = visitaSeleccionada.fecha_fin;
      document.getElementById('obs_adicionales').value = visitaSeleccionada.obs_adicionales || '';
    }
  }
});

// Función para crear nuevo aspecto desde el modal
async function crearNuevoAspecto() {
  const nombre = document.getElementById('nuevo_aspecto_nombre').value.trim();
  const descripcion = document.getElementById('nuevo_aspecto_desc').value.trim();

  if (!nombre) {
    alert('Por favor escribe el nombre del aspecto.');
    return;
  }

  try {
    const res = await fetch('backend/aspectos/guardarAspecto.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ nombre, descripcion, peso: 1 })
    });
    const j = await res.json();

    if (!j.success) {
      alert('Error creando aspecto: ' + (j.msg || ''));
      return;
    }

    const newId = j.id;

    // actualizar catálogo local (para que renderTablaTemp y búsquedas lo reconozcan)
    catalogo.push({ id: newId, nombre });

    // agregar al <select> y seleccionarlo
    const sel = document.getElementById('sel_aspecto');
    const opt = document.createElement('option');
    opt.value = newId;
    opt.text = nombre;
    sel.appendChild(opt);
    sel.value = newId;

    // Cerrar modal bootstrap
    const modalEl = document.getElementById('modalNuevoAspecto');
    const modalInstance = bootstrap.Modal.getInstance(modalEl);
    if (modalInstance) modalInstance.hide();

    // limpiar inputs del modal
    document.getElementById('nuevo_aspecto_nombre').value = '';
    document.getElementById('nuevo_aspecto_desc').value = '';
  } catch (err) {
    console.error(err);
    alert('Error conectando al servidor al crear aspecto');
  }
}


function agregarAspecto(){
  const sel = document.getElementById('sel_aspecto');
  const selVal = sel.value;
  if(!selVal){
    alert('Por favor selecciona o crea un aspecto');
    return;
  }

  // determinar nombre del aspecto: buscar en catalogo; si falla, tomar texto del option
  let nombreAspecto = (catalogo.find(c => String(c.id) === String(selVal)) || {}).nombre;
  if(!nombreAspecto){
    const option = sel.options[sel.selectedIndex];
    nombreAspecto = option ? option.text : '—';
  }

  const observacion = document.getElementById('observacion').value.trim();
  const estado = "NO CUMPLE";
  const recurrente = document.getElementById('recurrente').checked ? 1 : 0;
  const plazo = document.getElementById('plazo').value || null;
  const actividad = document.getElementById('actividad').value.trim();
  const responsable = document.getElementById('responsable').value; // id del responsable
  const evidenciaFile = document.getElementById('evidencia').files[0] || null;

  const record = {
    aspecto_id: selVal,           // <-- ID real
    aspecto_nombre: nombreAspecto,
    observacion,
    estado,
    recurrente,
    plazo,
    actividad,
    responsable,
    evidenciaFile
  };

  aspectosTemp.push(record);
  renderTablaTemp();

  // limpiar campos
  sel.value = '';
  document.getElementById('observacion').value = '';
  document.getElementById('recurrente').checked = false;
  document.getElementById('plazo').value = '';
  document.getElementById('actividad').value = '';
  document.getElementById('responsable').value = '';
  document.getElementById('evidencia').value = '';
}

function renderTablaTemp(){
  const tbody = document.querySelector('#tablaAspectos tbody');
  tbody.innerHTML = '';
  aspectosTemp.forEach((a,i) => {
    const nombre = a.aspecto_nombre || (catalogo.find(c=>String(c.id)===String(a.aspecto_id)) || {}).nombre || '—';
    const evidenciaName = a.evidenciaFile ? a.evidenciaFile.name : '';
    const tr = document.createElement('tr');
    tr.innerHTML = `<td>${nombre}</td>
                    <td>${a.estado}</td>
                    <td class="small">${(a.observacion||'')}</td>
                    <td>${a.responsable||''}</td>
                    <td>${evidenciaName}</td>
                    <td><button class="btn btn-danger btn-sm" onclick="eliminarTemp(${i})">Eliminar</button></td>`;
    tbody.appendChild(tr);
  });
}

function eliminarTemp(i){ aspectosTemp.splice(i,1); renderTablaTemp(); }

async function guardarTodo() {
  const btn = document.querySelector('.btn-save-visita');
  btn.disabled = true;

  // Mostrar modal con barra de progreso
  Swal.fire({
    title: 'Procesando...',
    html: `
      <p>Se están guardando las evaluaciones y enviando las notificaciones.</p>
      <div id="progressContainer" style="width: 100%; background: #eee; border-radius: 8px; height: 12px; margin-top: 10px;">
        <div id="progressBar" style="width: 0%; height: 100%; background: linear-gradient(90deg,#28a745,#4cd964); border-radius: 8px; transition: width 0.3s;"></div>
      </div>
      <p id="progressText" style="font-size: 13px; margin-top: 5px; color:#666;">Iniciando...</p>
    `,
    allowOutsideClick: false,
    showConfirmButton: false,
    didOpen: () => {
      Swal.showLoading();
      simulateProgress();
    }
  });

  function simulateProgress() {
    let progress = 0;
    const bar = document.getElementById("progressBar");
    const text = document.getElementById("progressText");
    const interval = setInterval(() => {
      progress += Math.random() * 10; // avanza de forma irregular
      if (progress >= 100) progress = 95; // se queda cerca del final hasta terminar
      bar.style.width = progress + "%";

      if (progress < 30) text.textContent = "Subiendo archivos...";
      else if (progress < 60) text.textContent = "Guardando en base de datos...";
      else if (progress < 90) text.textContent = "Enviando notificaciones...";
      else text.textContent = "Finalizando...";
    }, 400);

    // detenemos el intervalo al cerrar SweetAlert
    Swal.stopProgress = () => clearInterval(interval);
  }

  try {
    let visitaSeleccionada = document.getElementById('sel_visita').value;
    let visita_id = visitaSeleccionada || null;

    if (!visita_id) {
      const nombre_visita = document.getElementById('nombre_visita').value.trim();
      const fecha_inicio = document.getElementById('fecha_inicio').value;
      const fecha_fin = document.getElementById('fecha_fin').value;
      const obs_adicionales = document.getElementById('obs_adicionales') ? document.getElementById('obs_adicionales').value : '';

      if (!nombre_visita || !fecha_inicio || !fecha_fin) {
        Swal.stopProgress?.();
        Swal.fire({
          icon: 'warning',
          title: 'Campos incompletos',
          text: 'Completa el nombre de la visita y las fechas de inicio y fin.',
        });
        btn.disabled = false;
        return;
      }

      const rVis = await fetch('backend/visitas/guardarVisita.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ nombre_visita, fecha_inicio, fecha_fin, obs_adicionales })
      });
      const jVis = await rVis.json();

      if (!jVis.success) {
        Swal.stopProgress?.();
        Swal.fire({
          icon: 'error',
          title: 'Error al crear visita',
          text: jVis.msg || jVis.error || 'No se pudo guardar la visita.'
        });
        btn.disabled = false;
        return;
      }

      visita_id = jVis.id;
    }

    if (!aspectosTemp || aspectosTemp.length === 0) {
      Swal.stopProgress?.();
      const confirmar = await Swal.fire({
        icon: 'question',
        title: 'Sin evaluaciones',
        text: '¿Deseas guardar la visita sin evaluaciones?',
        showCancelButton: true,
        confirmButtonText: 'Sí, guardar',
        cancelButtonText: 'Cancelar'
      });
      if (!confirmar.isConfirmed) {
        btn.disabled = false;
        return;
      }
      Swal.showLoading();
      simulateProgress();
    }

    const formData = new FormData();
    formData.append('visita_id', visita_id);

    const serial = aspectosTemp.map((a, idx) => {
      if (a.evidenciaFile) formData.append('evidencia_' + idx, a.evidenciaFile);
      return {
        aspecto_id: a.aspecto_id,
        observacion: a.observacion || null,
        estado: a.estado || 'NO CUMPLE',
        recurrente: a.recurrente ? 1 : 0,
        plazo: a.plazo || null,
        actividad: a.actividad || null,
        responsable: a.responsable || null,
        evidencia: a.evidenciaFile ? ('evidencia_' + idx) : null
      };
    });

    formData.append('aspectos', JSON.stringify(serial));

    const rEval = await fetch('backend/evaluaciones/guardarEvaluaciones.php', {
      method: 'POST',
      body: formData
    });
    const jEval = await rEval.json();

    Swal.stopProgress?.();

    if (!jEval.success) {
      Swal.fire({
        icon: 'error',
        title: 'Error al guardar evaluaciones',
        text: jEval.error || jEval.msg || 'Ocurrió un error al procesar las evaluaciones.'
      });
      btn.disabled = false;
      return;
    }

    // Finalizar progreso visual
  Swal.fire({
  icon: 'success',
  title: 'Guardado correctamente',
  text: 'Las evaluaciones fueron guardadas y los correos enviados con éxito.',
  showConfirmButton: true,
  confirmButtonText: 'Ir al dashboard',
  allowOutsideClick: false
}).then(() => {
  window.location.href = 'index.php'; // 🔹 cambia a la ruta de tu panel principal
});


    aspectosTemp = [];
    renderTablaTemp();
    await loadVisitas();

  } catch (err) {
    Swal.stopProgress?.();
    Swal.fire({
      icon: 'error',
      title: 'Error inesperado',
      text: 'Ocurrió un problema: ' + err.message
    });
  } finally {
    btn.disabled = false;
  }
}


// Cargar responsables
async function loadResponsables(){
  try {
    const res = await fetch('backend/responsables/listarResponsables.php');
    const responsables = await res.json();
    const sel = document.getElementById('responsable');
    sel.innerHTML = '<option value="">-- Seleccionar responsable --</option>';
    responsables.forEach(r => {
      let o = document.createElement('option');
      o.value = r.id;
      o.text = r.nombre + (r.correo ? ` (${r.correo})` : '');
      sel.appendChild(o);
    });
  } catch (error) {
    console.error('Error cargando responsables:', error);
  }
}

// Inicializar la página
document.addEventListener('DOMContentLoaded', function() {
  loadCatalogo();
  loadVisitas();
  loadResponsables();
});