//Variables Globales

const API_BASE = 'backend/api/';
let mesActual = new Date().getMonth();
let añoActual = new Date().getFullYear();
let puestosData = [];
let turnosData = [];

//Inicia al cargar la pagina
document.addEventListener('DOMContentLoaded', function() {
    inicializarApp();
    configurarEventos();
    cargarDatosIniciales();
});

function inicializarApp() {
  const fechaActual = new Date().toLocaleDateString('es-CO', {
    weekday: 'long',
    year: 'numeric',
    month: 'long',
    day: 'numeric'
  });
  document.getElementById('fecha-actual').textContent = fechaActual;

  //Establecer una fecha minima en el formulario

  const hoy = new Date().toISOString().split('T')[0];
  document.getElementById('fecha-turn').value = hoy;
}

function configurarEventos() {
  document.querrySelectorAll('.menu-item').forEach(item => {
    item.addEventListener('click', function() {
      const section = this.dataset.section;
      cambiarSeccion(section);
    });
  });

  //Formulario de asignacion

  document.getElementById('form-asignar-turno').addEventListener('submit', asignarTurno);
  document.getElementById('area-select').addEventListener('change', cargarPuestoPorArea);
  document.getElementById('puesto-select').addEventListener('change',cargarTrabajadoresDisponibles);
  document.getElementById('turno-select').addEventListener('change', cargarTrabajadoresDisponibles);
  document.getElementById('fecha-turn').addEventListener('change', cargarTrabajadoresDisponibles);
  document.getElementById('trabajador-select').addEventListener('change', mostrarInforTrabajador);
}

function cambiarSeccion(seccion) {
  document.querySelectorAll('.section-content').forEach(s => {
    s.classList.remove('active');
  });

  document.getElementById(seccion).classList.add('active');

  document.querySelectorAll('.menu-item').forEach(item => {
    item.classList.remove('active');
  });
  document.querySelector(`[data-section="${seccion}"]`).classList.add('active');

  switch(seccion) {
    case 'dashboard':
      cargarEstadisticasDashboard();
      break;
    case 'calendario':
      cargarCalendario();
      break;
    case 'trabajadores':
      cargarTablaTrabajadores();
      break;
    case 'incapacidades':
      cargarTablaIncapacidades();
      break;
    case 'cambios':
      cargarTablaCambios();
      break;
    case 'dias-especiales':
      cargarTablaDiasEspeciales();
      break;
  }
}

async function cargarDatosIniciales() {
  try {
    const responseTurnos = await fetch(`${API_BASE}turnos.php?action=configuracion`);
    if (!responseTurnos.ok) {
      turnosData = [
        { id: 1, numero_turno: 1, nombre: 'Turno 1 - Mañana', hora_inicio: '06:00', hora_fin: '14:00'},
        { id: 2, numero_turno: 2, nombre: 'Turno 2 - Tarde', hora_inicio: '14:00', hora_fin: '22:00'},
        { id: 3, numero_turno: 3, nombre: 'Turno 3 - Noche', hora_inicio: '22:00', hora_fin: '06:00'}
      ];
    } else {
      const dataTurnos = await responseTurnos.json();
      turnosData = dataTurnos.data || [];
    }

    const turnoSelect = document.getElementById('turno-select');
    turnoSelect.innerHTML = '<option value="">Seleccione...</option>';

    const response = await fetch(`${API_BASE}trabajadores.php`);
    const data = await response.json();

    if (turnosData.length === 0) {
      turnosData = [
        {id: 1, numero_turno: 1, nombre: 'Turno 1 - Mañana', hora_inicio: '06:00', hora_fin: '14:00'},
        {id: 2, numero_turno: 2, nombre: 'Turno 2 - Tarde', hora_inicio: '14:00', hora_fin: '22:00'},
        {id: 3, numero_turno: 2, nombre: 'Turno 3 - Noche', hora_inicio: '22:00', hora_fin: '06:00'}
      ];
    }

    turnosData.forEach(turno => {
      const option = document.createElement('option');
      option.value = turno.id;
      option.textContent = `${turno.nombre} (${turno.hora_inicio} - ${turno.hora_fin})`;
      turnoSelect.appendChild(option);
    });

    cargarEstadisticasDashboard();

  } catch (error) {
    console.error('Error cargando datos iniciales:', error);
    mostrarAlerta('Error al cargar datos iniciales', 'danger');
  }
}

async function cargarEstadisticasDashboard() {
  try {
    //Trabajadores hoy
    const resTrabajadores = await fetch(`${API_BASE}trabajadores.php`);
    const dataTrabajadores = await resTrabajadores.json();
    document.getElementByID('total-trabajadores').textContent = dataTrabajadores.data?.length || 0;

    //Turnos hoy
    const hoy = new Date().toISOString().split('T')[0];
    const resTurnos = await fetch(`${API_BASE}turnos.php?fecha=${hoy}`);
    const dataTurnos = await resTurnos.json();
    document.getElementById('turnos-hoy').textContent = dataTurnos.data?.length || 0;

    //Incapacidades activas
    const resIncapacidades = await fetch(`${API_BASE}incapacidades.php?activas=1`);
    const dataIncapacidades = await resIncapacidades.json();
    document.getElementById('incapacidades-activas').textContent = dataIncapacidades.data?.length || 0;

    //Cambios pendientes
    const resCambios = await fetch(`${API_BASE}cambios_turno.php?estado=pendiente`);
    const dataCambios = await resCambios.json();
    document.getElementById('cambios-pendientes').textContent = dataCambios.data?.length || 0;

  } catch (error) {
    console.error('Error cargando estadisticas:', error);
  }
}

async function cargarPuestosPorArea() {
  const area = document.getElementById('area-select').value;
  const puestoSelect = document.getElementById('puesto-select');

  if (!area) {
    puestoSelect.disabled = true;
    puestoSelect.innerHTML = '<option value"">Seleccione área primero...</option>';
    return;
  }

  try {
    const puestos = {
      'DELTA': [
        {id: 1, codigo: 'D1', nombre: 'Delta 1'},
        {id: 2, codigo: 'D2', nombre: 'Delta 2'},
        {id: 3, codigo: 'D3', nombre: 'Delta 3'},
        {id: 4, codigo: 'D4', nombre: 'Delta 4'}
      ],
      'FOX': [
        {id: 5, codigo: 'F4', nombre: 'Fox 4'},
        {id: 6, codigo: 'F5', nombre: 'Fox 5'},
        {id: 7, codigo: 'F6', nombre: 'Fox 6'},
        {id: 8, codigo: 'F11', nombre: 'Fox 11'},
        {id: 9, codigo: 'F14', nombre: 'Fox 14'},
        {id: 10, codigo: 'F15', nombre: 'Fox 15'}
      ],
      'VIGIA': [
        {id: 11, codigo: 'V1', nombre: 'Vigia 1'},
        {id: 12, codigo: 'V2', nombre: 'Vigia 2'}
      ],
      'TASA DE USO': [
        {id: 13, codigo: 'C', nombre: 'Tasa de uso'},
        {id: 14, codigo: '2C', nombre: 'Tasa de uso 2'}
      ],
      'EQUIPAJES': [
        {id: 15, codigo: 'G', nombre: 'Equipajes'},
        {id: 16, codigo: '2G', nombre: 'Equipajes 2'}
      ]
    };

    puestosData = puestos[area] || [];

    puestosSelect.innetHTML = '<option value="">Seleccione...<option>';
    puestosData.forEach(puesto => {
      const option = document.createElement('option');
      option.value = puesto.id;
      option.textContent = `${puesto.codigo} - ${puesto.nombre}`;
      puestoSelect.appendChild(option);
    });

    puestoSelect.disabled = false;
  } catch (error) {
    console.error('Error cargando puestos:', error);
    mostarAlerta('Error al cargar puestos', 'danger');
    }
}

async function cargarTrabajadoresDisponibles() {
  const puesto = document.getElementById('puesto-select').value;
  const turno = document.getElementById('turno-select').value;
  const fecha = document.getElementById('fecha-turno').value;
  const trabajadorSelect = document.getElementByID('trabajador-select');

  if (!puesto || !turno || !fecha) {
    trabajadorSelect.disabled = true;
    trabajadorSelect.innerHTML = '<option value="">Complete los campos anteriores</option>';
    return;
  }
  try {
    const response = await fetch(`${API_BASE}trabajadores.php?disponibles=1&puesto_id=${puesto}&turno_id=${turno}&fecha=${fecha}`);
    const data = await response.json();

    if (data.success) {
      trabajadorSelect.innerHTML = '<option value="">Seleccione...</option>';

      if (data.data.length === 0) {
        trabajadorSelect.innerHTML = '<option value="">No hay trabajadores disponibles</option>';
        trabajadoresSelect.disabled = true;
        return;
      }

      data.data.forEach(trabajador => {
        const option = document.createElement('option');
        option.value = trabajador.id;
        option.textContent = `${trabajador.nombre} - ${trabajadores.cedula}`;
        if (trabajador.restriccicones) {
          option.textContent += `(${trabajador.restricciones})`;
        }
        trabajadorSelect.appendChild(option);
      });
      trabajadorSelect.disabled = false;
    }
  } catch (error){
    console.error('Error al cargando trabajadores:', error);
    mostrarAlerta('Error al cargar trabajadores disponibles', 'danger');
  }
}

function mostrarInforTrabajador() {
  const trabajadorId = document.getElementById('trabajador-select').value;
  const infoDiv = document.getElementById('trabajador-info');

  if (!trabajadorId) {
    infoDiv.style.display = 'none';
    return;
  }

  const trabajadorOption = document.getelementById('trabajador-select').selectedOptions[0];
  const texto = trabajadorOption.textContent;

  if (texto.includes('(') && texto.includes(')')) {
    const restricciones = texto.match(/\((.*?)\)/)[1];
    infoDiv.innerHTML = `<strong>⚠️Restricciones</strong> ${restricciones}`;
    infoDiv.style.display = 'block';
    infoDiv.className = 'info-box';
  } else {
    infoDiv.style.display = 'none';
  }
}

async function validarAsignacion() {
  const trabajador = document.getElementById('trabajador-select').value;
  const puesto = document.getElementById('puesto-select').value;
  const turno = document.getElementById('turno-select').value;
  const fecha = document.getElementById('fecha-turno').value;

  if (!trabajador || !puesto || !turno || !fecha) {
    mostrarAlerta('Complete todos los campos requeridos', 'warning');
    return;
  }

  try {
    const response = await fetch(`${API_BASE}turnos.php?action=validar`, {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({
        trabajador_id: trabajador,
        puesto_trabajo_id: puesto,
        turno_id: turno,
        fecha: fecha
      })
    });

    const data = await response.json();
    const mensajeDiv = document.getElementById('validacion-mensaje');

    if (data.success && data.data.valida) {
      mensajeDiv.className = 'alert alert-success';
      mensajeDiv.innerHTML = 'La asignacion es valida. Se puede proceder a guardar'
    } else {
      mensajeDiv.className = 'alert alert-danger';
      mensajeDiv.innerHTML = '<strong>No se puede asignar:</strong><ul>' + data.data.errores.map(e => `<li>${e}`).join('') + '</ul>';
    }

    mensajeDiv.style.display = 'block';
  } catch (error) {
    console.error('Error validando:', error);
    mostrarAlerta('Error al validad la asignacion', 'danger');
  }
}

async function asignarTurno(e) {
  e.preventDefault();

  const datos = {
    trabajador_id: document.getElementById('trabajador-select').value,
    puesto_trabajo_id: document.getElementById('puesto-select').value,
    turno_id: document.getElementById('turno-select').value,
    fecha: document.getElementById('fecha-turno'.value),
    observaciones: document.getElementById('observaciones-turno').value
  };

  try {
    const response = await fetch(`${API_BASE}turnos.php`, {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify(datos)
    });

    const data = await response.json();

    if(data.seccess) {
      mostrarAlerta('Turno asignado exitosamente', 'success');
      limpiarFormulario();
      cargarEstadisticasDashboard();
    } else {
      mostrarAlerta(data.message || 'Error al asignar turno', 'danger');
    }
  } catch (error) {
    console.error('Error asignando turno:', error);
    mostrarAlerta('Error al asignar turno', 'danger');
  }
}

function limpiarFormulario() {
  document.getElementById('form-asignar-turno').reset();
  document.getElementById('puesto-select').disabled = true;
  document.getElementById('trabajador-select').disabled = true;
  document.getElementById('validacion-mensaje').style.display = 'none';
  document.getElementById('trabajador-info').style.display = 'none';
}

function mostrarAlerta (mensaje, tipo) {
  const alertDiv = document.createElement('div');
  alertDiv.className = `alert alert-${tipo}`;
  alertDiv.textContent = mensaje;
  alertDiv.style.position = fixed;
  alertDiv.style.top = '20px';
  alertDiv.style.right = '20px';
  alertDiv.style.zIndex = '9999';
  alertDiv.style.minWidth = '300px';

  document.body.appendchild(alertDiv);

  setTimeout(() => {
    alertDiv.remove();
  }, 5000);
}