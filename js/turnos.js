//Variables Globales

const API_BASE = 'backend/api/';
let mesActual = new Date().getMonth();
let anioActual = new Date().getFullYear();
let puestosData = [];
let turnosData = [];
let trabajadoresData = [];

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
  const fechaElement = document.getElementById('fecha-actual');
  if (fechaElement) {
    fechaElement.textContent = fechaActual;
  }

  //Establecer una fecha minima en el formulario

  const hoy = new Date().toISOString().split('T')[0];
  const fechaTurno = document.getElementById('fecha-turno');
  if (fechaTurno) {
    fechaTurno.value = hoy;
    fechaTurno.min = hoy;
  }
}

function configurarEventos() {
  document.querySelectorAll('.menu-item').forEach(item => {
    item.addEventListener('click', function() {
      const section = this.dataset.section;
      cambiarSeccion(section);
    });
  });

  //Formulario de asignacion

  const formAsignar = document.getElementById('form-asignar-turno');
    if (formAsignar) {
        formAsignar.addEventListener('submit', asignarTurno);
    }
    
    const areaSelect = document.getElementById('area-select');
    if (areaSelect) {
        areaSelect.addEventListener('change', cargarPuestosPorArea);
    }
    
    const puestoSelect = document.getElementById('puesto-select');
    if (puestoSelect) {
        puestoSelect.addEventListener('change', function() {
            actualizarTurnosDisponibles();
            cargarTrabajadoresDisponibles();
        });
    }
    
    const turnoSelect = document.getElementById('turno-select');
    if (turnoSelect) {
        turnoSelect.addEventListener('change', function() {
            filtrarPuestosPorTurnoNoche();
            cargarTrabajadoresDisponibles();
        });
    }
    
    const fechaTurnoInput = document.getElementById('fecha-turno');
    if (fechaTurnoInput) {
        fechaTurnoInput.addEventListener('change', cargarTrabajadoresDisponibles);
    }
    
    const trabajadorSelect = document.getElementById('trabajador-select');
    if (trabajadorSelect) {
        trabajadorSelect.addEventListener('change', mostrarInfoTrabajador);
    }
}

function cambiarSeccion(seccion) {
  document.querySelectorAll('.section-content').forEach(s => {
    s.classList.remove('active');
  });

  const seccionElement = document.getElementById(seccion);
    if (seccionElement) {
        seccionElement.classList.add('active');
    }

  document.querySelectorAll('.menu-item').forEach(item => {
    item.classList.remove('active');
  });
  const menuItem = document.querySelector(`[data-section="${seccion}"]`);
    if (menuItem) {
        menuItem.classList.add('active');
    }

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
    case 'restricciones':
      cargarTablaRestricciones();
      break;
    case 'incapacidades':
      cargarTablaIncapacidades();
      break;
    case 'dias-especiales':
      cargarTablaDiasEspeciales();
      break;
  }
}

async function cargarDatosIniciales() {
  try {
    await cargarConfiguracionTurnos();
    await cargarPuestosTrabajo();
    await cargarEstadisticasDashboard();

  } catch (error) {
    console.error('Error cargando datos iniciales:', error);
    mostrarAlerta('Error al cargar datos iniciales', 'danger');
  }
}
async function cargarConfiguracionTurnos() {
  turnosData = [
    {id: 1, numero_turno: 1, nombre: 'Turno 1 - Mañana', hora_inicio: '06:00:00', hora_fin: '14:00:00', es_nocturno: false},
    {id: 2, numero_turno: 2, nombre: 'Turno 2 - Tarde', hora_inicio: '14:00:00', hora_fin: '22:00:00', es_nocturno: false},
    {id: 3, numero_turno: 3, nombre: 'Turno 3 - Noche', hora_inicio: '22:00:00', hora_fin: '06:00:00', es_nocturno: true}
  ];
  const turnoSelect = document.getElementById('turno-select');
  if (turnoSelect) {
    turnoSelect.innerHTML = '<option value="">Seleccione...</option>';
    turnosData.forEach(turno => {
      const option = document.createElement('option');
      option.value = turno.id;
      option.textContent = `${turno.nombre} (${turno.hora_inicio.substring(0,5)} - ${turno.hora_fin.substring(0,5)})`;
      turnoSelect.appendChild(option);
    });
  }
}
const horariosEspeciales = {
    16: [ // G - Equipajes (solo 2 turnos)
        {numero: 1, nombre: 'Turno Mañana', inicio: '06:00', fin: '14:00'},
        {numero: 2, nombre: 'Turno Tarde', inicio: '14:00', fin: '22:00'}
    ],
    9: [ // F14 - Fox 14 (horarios especiales)
        {numero: 1, nombre: 'Turno Mañana', inicio: '04:00', fin: '12:00'},
        {numero: 2, nombre: 'Turno Tarde', inicio: '12:00', fin: '20:00'},
        {numero: 3, nombre: 'Turno Noche', inicio: '20:00', fin: '04:00'}
    ]
};

// Turnos L4 disponibles para cualquier puesto
const turnosL4 = [
    {numero: 4, nombre: 'L4 - Mañana (4h)', inicio: '06:00', fin: '10:00'},
    {numero: 5, nombre: 'L4 - Tarde (4h)', inicio: '14:00', fin: '18:00'}
];

function obtenerHorariosPuesto(puestoId) {
    return horariosEspeciales[puestoId] || null;
}
async function cargarPuestosTrabajo() {
    puestosData = {
        'DELTA': [
            {id: 1, codigo: 'D1', nombre: 'Delta 1', area: 'DELTA'},
            {id: 2, codigo: 'D2', nombre: 'Delta 2', area: 'DELTA'},
            {id: 3, codigo: 'D3', nombre: 'Delta 3', area: 'DELTA'},
            {id: 4, codigo: 'D4', nombre: 'Delta 4', area: 'DELTA'}
        ],
        'FOX': [
            {id: 5, codigo: 'F4', nombre: 'Fox 4', area: 'FOX'},
            {id: 6, codigo: 'F5', nombre: 'Fox 5', area: 'FOX'},
            {id: 7, codigo: 'F6', nombre: 'Fox 6', area: 'FOX'},
            {id: 8, codigo: 'F11', nombre: 'Fox 11', area: 'FOX'},
            {id: 9, codigo: 'F14', nombre: 'Fox 14', area: 'FOX'},
            {id: 10, codigo: 'F15', nombre: 'Fox 15', area: 'FOX'}
        ],
        'VIGIA': [
            {id: 11, codigo: 'V1', nombre: 'Vigía 1', area: 'VIGIA'},
            {id: 12, codigo: 'V2', nombre: 'Vigía 2', area: 'VIGIA'}
        ],
        'TASA DE USO': [
            {id: 14, codigo: 'C', nombre: 'Tasa de Uso', area: 'TASA DE USO'},
        ],
        'EQUIPAJES': [
            {id: 15, codigo: 'G', nombre: 'Equipajes', area: 'EQUIPAJES'},
        ]
    };
}

// IDs de puestos activos en turno nocturno (Turno 3)
const PUESTOS_NOCTURNOS = new Set([3, 7, 8, 11, 12, 14]); // D3, F6, F11, V1, V2, Tasa de Uso

function esTurnoNoche() {
    const turnoSelect = document.getElementById('turno-select');
    if (!turnoSelect) return false;
    // turno_id 3 = Turno 3 Noche. También puede venir como número_turno 3.
    const val = turnoSelect.value;
    const selectedOption = turnoSelect.options[turnoSelect.selectedIndex];
    // turnosData tiene los ids reales; el turno nocturno tiene numero_turno === 3
    const turno = turnosData.find(t => String(t.id) === String(val));
    return turno ? turno.numero_turno === 3 : false;
}

function filtrarPuestosPorTurnoNoche() {
    const areaSelect = document.getElementById('area-select');
    const puestoSelect = document.getElementById('puesto-select');
    if (!areaSelect || !puestoSelect) return;

    const esNoche = esTurnoNoche();

    if (!esNoche) {
        // Restaurar todas las áreas y puestos normalmente
        // Refrescar el select de área sin restricciones
        const areaActual = areaSelect.value;
        Array.from(areaSelect.options).forEach(opt => { opt.disabled = false; opt.title = ''; });
        // Re-renderizar puestos del área actual sin filtro
        const area = areaSelect.value;
        if (area) {
            const puestos = puestosData[area] || [];
            puestoSelect.innerHTML = '<option value="">Seleccione...</option>';
            puestos.forEach(puesto => {
                const option = document.createElement('option');
                option.value = puesto.id;
                option.textContent = puesto.codigo + ' - ' + puesto.nombre;
                puestoSelect.appendChild(option);
            });
            puestoSelect.disabled = false;
        }
        return;
    }

    // Es turno noche: filtrar áreas y puestos
    // Áreas que tienen al menos un puesto nocturno
    const areasNocturnas = new Set();
    Object.entries(puestosData).forEach(([area, puestos]) => {
        if (puestos.some(p => PUESTOS_NOCTURNOS.has(p.id))) areasNocturnas.add(area);
    });

    // Deshabilitar áreas sin puestos nocturnos
    Array.from(areaSelect.options).forEach(opt => {
        if (!opt.value) return;
        if (!areasNocturnas.has(opt.value)) {
            opt.disabled = true;
            opt.title = 'No disponible en turno nocturno';
        } else {
            opt.disabled = false;
            opt.title = '';
        }
    });

    // Si el área actual no está en las nocturnas, resetear
    if (areaSelect.value && !areasNocturnas.has(areaSelect.value)) {
        areaSelect.value = '';
        puestoSelect.innerHTML = '<option value="">Seleccione área primero...</option>';
        puestoSelect.disabled = true;
        mostrarAlerta('El área seleccionada no opera en turno nocturno', 'warning');
        return;
    }

    // Filtrar puestos del área actual a solo los nocturnos
    const area = areaSelect.value;
    if (area) {
        const puestos = (puestosData[area] || []).filter(p => PUESTOS_NOCTURNOS.has(p.id));
        const puestoActual = puestoSelect.value;
        puestoSelect.innerHTML = '<option value="">Seleccione...</option>';
        puestos.forEach(puesto => {
            const option = document.createElement('option');
            option.value = puesto.id;
            option.textContent = puesto.codigo + ' - ' + puesto.nombre;
            puestoSelect.appendChild(option);
        });
        puestoSelect.disabled = false;
        // Si el puesto actual ya no es nocturno, limpiarlo
        if (puestoActual && !PUESTOS_NOCTURNOS.has(parseInt(puestoActual))) {
            puestoSelect.value = '';
            mostrarAlerta('El puesto seleccionado no opera en turno nocturno', 'warning');
        } else {
            puestoSelect.value = puestoActual;
        }
    }
}
async function cargarEstadisticasDashboard() {
    try {
        // Total trabajadores
        const resTrabajadores = await fetch(API_BASE + 'trabajadores.php');
        const dataTrabajadores = await resTrabajadores.json();
        const totalTrabajadores = document.getElementById('total-trabajadores');
        if (totalTrabajadores) {
            totalTrabajadores.textContent = dataTrabajadores.data ? dataTrabajadores.data.length : 0;
        }
        
        // Turnos hoy
        const hoy = new Date().toISOString().split('T')[0];
        const resTurnos = await fetch(API_BASE + 'turnos.php?fecha=' + hoy);
        const dataTurnos = await resTurnos.json();
        const turnosHoy = document.getElementById('turnos-hoy');
        if (turnosHoy) {
            turnosHoy.textContent = dataTurnos.data ? dataTurnos.data.length : 0;
        }
        
        // Incapacidades activas
        const resIncapacidades = await fetch(API_BASE + 'incapacidades.php?activas=1');
        const dataIncapacidades = await resIncapacidades.json();
        const incapacidadesActivas = document.getElementById('incapacidades-activas');
        if (incapacidadesActivas) {
            incapacidadesActivas.textContent = dataIncapacidades.data ? dataIncapacidades.data.length : 0;
        }
        
        // Cargar alertas
        await cargarAlertas();
        
    } catch (error) {
        console.error('Error cargando estadísticas:', error);
        mostrarAlerta('Error cargando las estadisticas', 'danger')
    }
}

// ALERTAS Y AVISOS IMPORTANTES
async function cargarAlertas() {
    try {
        const contenedor = document.getElementById('contenedor-alertas');
        if (!contenedor) return;

        const alertas = [];

        // Cargar incapacidades próximas a vencer (próximos 7 días)
        const incapacidadesProximas = await obtenerIncapacidadesProximasAVencer();
        if (incapacidadesProximas && incapacidadesProximas.length > 0) {
            incapacidadesProximas.forEach(incapacidad => {
                alertas.push({
                    tipo: 'warning',
                    icono: 'fa-heartbeat',
                    titulo: `Incapacidad por vencer - ${incapacidad.trabajador}`,
                    descripcion: `Vence el ${incapacidad.fecha_fin}`,
                    badge: `${incapacidad.dias_restantes}d`,
                    badgeClass: 'warning'
                });
            });
        }


        if (alertas.length === 0) {
            contenedor.innerHTML = '<div class="alert-placeholder"><i class="fas fa-check-circle"></i> No hay alertas activas</div>';
        } else {
            renderizarAlertas(alertas);
        }

    } catch (error) {
        console.error('Error cargando alertas:', error);
        const contenedor = document.getElementById('contenedor-alertas');
        if (contenedor) {
            contenedor.innerHTML = '<div class="alert-placeholder"><i class="fas fa-exclamation-circle"></i> Error cargando alertas</div>';
        }
    }
}

async function obtenerIncapacidadesProximasAVencer() {
    try {
        const hoy = new Date();
        const hace7dias = new Date(hoy.getTime() - 7 * 24 * 60 * 60 * 1000);
        const en7dias = new Date(hoy.getTime() + 7 * 24 * 60 * 60 * 1000);

        const response = await fetch(API_BASE + 'incapacidades.php?activas=1');
        const data = await response.json();

        if (!data.success || !data.data) return [];

        return data.data
            .filter(inc => {
                const fechaFin = new Date(inc.fecha_fin);
                return fechaFin > hoy && fechaFin <= en7dias;
            })
            .map(inc => {
                const diasRestantes = Math.ceil((new Date(inc.fecha_fin) - hoy) / (1000 * 60 * 60 * 24));
                return {
                    id: inc.id,
                    trabajador: inc.trabajador || 'Desconocido',
                    fecha_fin: new Date(inc.fecha_fin).toLocaleDateString('es-CO'),
                    dias_restantes: diasRestantes
                };
            })
            .sort((a, b) => a.dias_restantes - b.dias_restantes);
    } catch (error) {
        console.error('Error cargando incapacidades próximas:', error);
        return [];
    }
}

function renderizarAlertas(alertas) {
    const contenedor = document.getElementById('contenedor-alertas');
    if (!contenedor) return;

    contenedor.innerHTML = '';

    alertas.forEach(alerta => {
        const alertaElement = document.createElement('div');
        alertaElement.className = `alert-item alert-${alerta.tipo}`;
        
        let badge = '';
        if (alerta.badge) {
            badge = `<span class="alert-item-badge ${alerta.badgeClass}">${alerta.badge}</span>`;
        }

        alertaElement.innerHTML = `
            <div class="alert-item-icon"><i class="fas ${alerta.icono}"></i></div>
            <div class="alert-item-content">
                <div class="alert-item-title">${alerta.titulo}</div>
                <div class="alert-item-description">${alerta.descripcion}</div>
            </div>
            ${badge}
        `;

        contenedor.appendChild(alertaElement);
    });
}

async function cargarFormularioAsignacion() {
  const validacionMensaje = document.getElementById('validacion-mensaje');
  if (validacionMensaje) {
    validacionMensaje.style.display = 'none';
  }
}

async function cargarPuestosPorArea() {
    const areaSelect = document.getElementById('area-select');
    const puestoSelect = document.getElementById('puesto-select');
    
    if (!areaSelect || !puestoSelect) return;
    
    const area = areaSelect.value;
    
    if (!area) {
        puestoSelect.disabled = true;
        puestoSelect.innerHTML = '<option value="">Seleccione área primero...</option>';
        resetearTrabajadores();
        return;
    }
    
    let puestos = puestosData[area] || [];

    // Si es turno noche, filtrar solo puestos nocturnos
    if (esTurnoNoche()) {
        puestos = puestos.filter(p => PUESTOS_NOCTURNOS.has(p.id));
        if (puestos.length === 0) {
            puestoSelect.disabled = true;
            puestoSelect.innerHTML = '<option value="">Sin puestos nocturnos en esta área</option>';
            resetearTrabajadores();
            return;
        }
    }
    
    puestoSelect.innerHTML = '<option value="">Seleccione...</option>';
    puestos.forEach(puesto => {
        const option = document.createElement('option');
        option.value = puesto.id;
        option.textContent = puesto.codigo + ' - ' + puesto.nombre;
        puestoSelect.appendChild(option);
    });
    
    puestoSelect.disabled = false;
    resetearTrabajadores();
    
    // Resetear select de turnos al cambiar área
    actualizarTurnosDisponibles();
}

function actualizarTurnosDisponibles() {
  const puestoSelect = document.getElementById('puesto-select');
  const turnoSelect = document.getElementById('turno-select');

  if (!puestoSelect || !turnoSelect) return;

  const puestoId = parseInt(puestoSelect.value);
  const turnoActual = turnoSelect.value; // preservar selección actual

  if (!puestoId) {
    turnoSelect.innerHTML = '<option value="">Seleccione...</option>';
    turnosData.forEach(turno => {
      const option = document.createElement('option');
      option.value = turno.id;
      option.textContent = turno.nombre + ' (' + turno.hora_inicio.substring(0, 5) + ' - ' + turno.hora_fin.substring(0, 5) + ')';
      turnoSelect.appendChild(option);
    });
    // Restaurar selección si sigue siendo válida
    if (turnoActual) turnoSelect.value = turnoActual;
    return;
  }

  const horariosEsp = obtenerHorariosPuesto(puestoId);

  turnoSelect.innerHTML = '<option value="">Seleccione...</option>';

  if (horariosEsp) {
    horariosEsp.forEach(horario => {
      const option = document.createElement('option');
      option.value = horario.numero;
      option.textContent = horario.nombre + ' (' + horario.inicio + ' - ' + horario.fin + ')';
      option.dataset.especial = 'true';
      turnoSelect.appendChild(option);
    });
  } else {
    turnosData.forEach(turno => {
      const option = document.createElement('option');
      option.value = turno.id;
      option.textContent = turno.nombre + ' (' + turno.hora_inicio.substring(0, 5) + ' - ' + turno.hora_fin.substring(0, 5) + ')';
      turnoSelect.appendChild(option);
    });

    const separador = document.createElement('option');
    separador.disabled = true;
    separador.textContent = '--- Turnos L4 (4 Horas) ---';
    turnoSelect.appendChild(separador);

    turnosL4.forEach(turno => {
      const option = document.createElement('option');
      option.value = turno.numero;
      option.textContent = turno.nombre + ' (' + turno.inicio + ' - ' + turno.fin + ')';
      option.dataset.l4 = 'true';
      turnoSelect.appendChild(option);
    });
  }

  // Restaurar el turno que estaba seleccionado si sigue estando disponible
  if (turnoActual) {
    turnoSelect.value = turnoActual;
    // Si no se pudo restaurar (opción ya no existe) dejar en blanco
    if (turnoSelect.value !== turnoActual) {
      turnoSelect.value = '';
    }
  }
}

async function cargarTrabajadoresDisponibles() {
    const puestoSelect = document.getElementById('puesto-select');
    const turnoSelect = document.getElementById('turno-select');
    const fechaTurno = document.getElementById('fecha-turno');
    const trabajadorSelect = document.getElementById('trabajador-select');
    
    if (!puestoSelect || !turnoSelect || !fechaTurno || !trabajadorSelect) {
        console.log('Falta algún elemento del formulario');
        return;
    }
    
    const puesto = puestoSelect.value;
    const turno = turnoSelect.value;
    const fecha = fechaTurno.value;
    
    
    if (!puesto || !turno || !fecha) {
        resetearTrabajadores();
        return;
    }
    
    // Mostrar mensaje de carga
    trabajadorSelect.innerHTML = '<option value="">Cargando trabajadores...</option>';
    trabajadorSelect.disabled = true;
    
    try {
        const url = `${API_BASE}trabajadores.php?disponibles=1&puesto_id=${puesto}&turno_id=${turno}&fecha=${fecha}`;
        
        const response = await fetch(url);
        const data = await response.json();
        
        
        trabajadorSelect.innerHTML = '<option value="">Seleccione...</option>';
        
        if (data.success && data.data && data.data.length > 0) {
            data.data.forEach(trabajador => {
                const option = document.createElement('option');
                option.value = trabajador.id;
                option.textContent = `${trabajador.nombre} - ${trabajador.cedula}`;
                if (trabajador.restricciones) {
                    option.dataset.restricciones = trabajador.restricciones;
                }
                trabajadorSelect.appendChild(option);
            });
            trabajadorSelect.disabled = false;
            console.log('Trabajadores cargados:', data.data.length);
        } else {
            trabajadorSelect.innerHTML = '<option value="">No hay trabajadores disponibles</option>';
            trabajadorSelect.disabled = true;
            console.log('No hay trabajadores disponibles');
        }
        
    } catch (error) {
        console.error('Error cargando trabajadores:', error);
        trabajadorSelect.innerHTML = '<option value="">Error al cargar trabajadores</option>';
        trabajadorSelect.disabled = true;
        mostrarAlerta('Error al cargar trabajadores disponibles', 'danger');
    }
}

function resetearTrabajadores() {
  const trabajadorSelect = document.getElementById('trabajador-select');
  const trabajadorInfo = document.getElementById('trabajador-info');

  if (trabajadorSelect) {
    trabajadorSelect.disabled = true;
    trabajadorSelect.innerHTML = '<option value="">Complete los campos anteriores...</option>';
  }
  if (trabajadorInfo) {
    trabajadorInfo.style.display = 'none';
  }
}

function mostrarInfoTrabajador() {
  const trabajadorSelect = document.getElementById('trabajador-select');
    const trabajadorInfo = document.getElementById('trabajador-info');
    
    if (!trabajadorSelect || !trabajadorInfo) return;
    
    const selectedOption = trabajadorSelect.selectedOptions[0];
    
    if (selectedOption && selectedOption.dataset.restricciones) {
        trabajadorInfo.innerHTML = `<strong>⚠️ Restricciones:</strong> ${selectedOption.dataset.restricciones}`;
        trabajadorInfo.style.display = 'block';
    } else {
      trabajadorInfo.style.display = 'none';
  }      
}

async function validarAsignacion() {
  const trabajador = document.getElementById('trabajador-select')?.value;
  const puesto = document.getElementById('puesto-select')?.value;
  const turno = document.getElementById('turno-select')?.value;
  const fecha = document.getElementById('fecha-turno')?.value;

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
        fecha: fecha,
        estado: 'programado',
        created_by: 1
      })
    });

    const data = await response.json();
    const mensajeDiv = document.getElementById('validacion-mensaje');

    if (data.success && data.data.valido) {
      mensajeDiv.className = 'alert alert-success';
      mensajeDiv.innerHTML = 'La asignacion es valida. Se puede proceder a guardar'
    } else {
      mensajeDiv.className = 'alert alert-danger';
      mensajeDiv.innerHTML = '<strong>No se puede asignar:</strong><ul>' + (data.data.errores || []).map(e => `<li>${e}</li>`).join('') + '</ul>';
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
    trabajador_id: document.getElementById('trabajador-select')?.value,
    puesto_trabajo_id: document.getElementById('puesto-select')?.value,
    turno_id: document.getElementById('turno-select')?.value,
    fecha: document.getElementById('fecha-turno')?.value,
    estado: 'programado',
    created_by: 1
  };

  if (!datos.trabajador_id || ! datos.puesto_trabajo_id || !datos.turno_id || !datos.fecha) {
    mostrarAlerta('Complete todos los campos requeridos', 'warning');
    return;
  }

  try {
    const response = await fetch(`${API_BASE}turnos.php`, {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify(datos)
    });

    const data = await response.json();

    if(data.success) {
      mostrarAlerta('Turno asignado exitosamente', 'success');
      limpiarFormulario();
      cargarEstadisticasDashboard();
    } else {
      const mensaje = data.message || 'Error al asignar turno';
      const errores = data.errores ? '<ul>' + data.errores.map(e => `<li>${e}</li>`).join('') + '</ul>' : '';
      mostrarAlerta(mensaje + errores, 'danger');
    }
  } catch (error) {
    console.error('Error asignando turno:', error);
    mostrarAlerta('Error al asignar turno', 'danger');
  }
}

async function asignacionAutomaticaMes() {
    const modalOverlay = document.getElementById('modal-overlay');
    const modalTitulo = document.getElementById('modal-titulo');
    const modalBody = document.getElementById('modal-body');
    
    modalTitulo.textContent = '🎲 Asignación Automática Mensual';
    
    const hoy = new Date();
    const mesActualNum = hoy.getMonth() + 1;
    const anioActualNum = hoy.getFullYear();
    
    let htmlContent = '<form id="form-asignacion-automatica">';
    htmlContent += '<div class="info-box" style="margin-bottom: 1.5rem;">';
    htmlContent += '<p><strong>ℹ️ Información:</strong></p>';
    htmlContent += '<p>Esta función asignará automáticamente trabajadores a todos los turnos del mes seleccionado.</p>';
    htmlContent += '<ul style="margin: 0.5rem 0; padding-left: 1.5rem;">';
    htmlContent += '<li>Se respetarán restricciones, incapacidades y días especiales</li>';
    htmlContent += '<li>La asignación es aleatoria pero inteligente</li>';
    htmlContent += '<li>Puedes editar las asignaciones después si es necesario</li>';
    htmlContent += '</ul>';
    htmlContent += '</div>';
    
    htmlContent += '<div class="form-grid">';
    htmlContent += '<div class="form-group">';
    htmlContent += '<label for="mes-asignacion">Mes <span class="required">*</span></label>';
    htmlContent += '<select id="mes-asignacion" required>';
    htmlContent += '<option value="1">Enero</option>';
    htmlContent += '<option value="2">Febrero</option>';
    htmlContent += '<option value="3">Marzo</option>';
    htmlContent += '<option value="4">Abril</option>';
    htmlContent += '<option value="5">Mayo</option>';
    htmlContent += '<option value="6">Junio</option>';
    htmlContent += '<option value="7">Julio</option>';
    htmlContent += '<option value="8">Agosto</option>';
    htmlContent += '<option value="9">Septiembre</option>';
    htmlContent += '<option value="10">Octubre</option>';
    htmlContent += '<option value="11">Noviembre</option>';
    htmlContent += '<option value="12">Diciembre</option>';
    htmlContent += '</select>';
    htmlContent += '</div>';
    
    htmlContent += '<div class="form-group">';
    htmlContent += '<label for="anio-asignacion">Año <span class="required">*</span></label>';
    htmlContent += '<input type="number" id="anio-asignacion" min="2024" max="2030" value="' + anioActualNum + '" required>';
    htmlContent += '</div>';
    htmlContent += '</div>';
    
    htmlContent += '<div class="form-group">';
    htmlContent += '<label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">';
    htmlContent += '<input type="checkbox" id="saltar-domingos" checked>';
    htmlContent += '<span>No asignar turnos los domingos</span>';
    htmlContent += '</label>';
    htmlContent += '</div>';
    
    htmlContent += '<div class="alert alert-warning" style="margin-top: 1rem;">';
    htmlContent += '<strong>⚠️ Advertencia:</strong> Si ya existen turnos asignados para este mes, NO se sobrescribirán. Solo se llenarán los espacios vacíos.';
    htmlContent += '</div>';
    
    htmlContent += '<div class="form-actions">';
    htmlContent += '<button type="submit" class="btn btn-primary"><i class="fas fa-magic"></i> Generar Asignaciones</button>';
    htmlContent += '<button type="button" class="btn btn-outline" onclick="cerrarModal()"><i class="fas fa-times"></i> Cancelar</button>';
    htmlContent += '</div>';
    htmlContent += '</form>';
    
    modalBody.innerHTML = htmlContent;
    
    document.getElementById('mes-asignacion').value = mesActualNum;
    modalOverlay.classList.add('active');
    
    document.getElementById('form-asignacion-automatica').addEventListener('submit', ejecutarAsignacionAutomatica);
}

async function ejecutarAsignacionAutomatica(e) {
    e.preventDefault();
    
    const mesSelect = document.getElementById('mes-asignacion');
    const anioInput = document.getElementById('anio-asignacion');
    const saltarDomingosCheck = document.getElementById('saltar-domingos');
    
    if (!mesSelect || !anioInput || !saltarDomingosCheck) {
        mostrarAlerta('Error: elementos del formulario no encontrados', 'danger');
        return;
    }
    
    const mes = parseInt(mesSelect.value);
    const anio = parseInt(anioInput.value);
    const saltarDomingos = saltarDomingosCheck.checked;
    
    const nombresMeses = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 
                          'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
    
    const mesNombre = nombresMeses[mes] || 'Desconocido';
    
    if (!confirm('Generar asignaciones automaticas para ' + mesNombre + ' ' + anio + '?')) {
        return;
    }
    
    mostrarAlerta('Generando asignaciones... Esto puede tardar unos segundos', 'info');
    cerrarModal();
    
    try {
        const url = API_BASE + 'asignacion_automatica.php';
        const datos = {
            mes: mes,
            anio: anio,
            opciones: {
                saltar_domingos: saltarDomingos
            }
        };
        
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(datos)
        });
        
        if (!response.ok) {
            throw new Error('Error HTTP: ' + response.status);
        }
        
        const result = await response.json();
        
        if (result.success) {
            let mensaje = 'Asignacion completada: ' + result.asignaciones + ' turnos asignados';
            if (result.errores > 0) {
                mensaje += ', ' + result.errores + ' no pudieron asignarse';
                console.log('Detalles de errores:', result.detalle_errores);
            }
            mostrarAlerta(mensaje, 'success');
            cargarEstadisticasDashboard();
        } else {
            mostrarAlerta('Error: ' + result.message, 'danger');
        }
        
    } catch (error) {
        console.error('Error completo:', error);
        mostrarAlerta('Error en asignacion automatica: ' + error.message, 'danger');
    }
}

function limpiarFormulario() {
  const form = document.getElementById('form-asignar-turno');
  if (form) {
    form.reset();
  }

  const hoy = new Date().toISOString().split('T')[0];
  const fechaTurno = document.getElementById('fecha-turno');
  if (fechaTurno) {
    fechaTurno.value = hoy;
  }

  resetearTrabajadores();

  const puestoSelect = document.getElementById('puesto-select');
  if (puestoSelect) {
    puestoSelect.disabled = true;
    puestoSelect.innerHTML = '<option value="">Seleccione areá primero...</option>';
  }

  const validacionMensaje = document.getElementById('validacion-mensaje');
  if (validacionMensaje) {
    validacionMensaje.style.display = 'none';
  }
}
//  CALENDARIO
let fechaCalendarioActual = new Date();

function cargarCalendario() {
    actualizarDisplayFecha();
    cargarVistaDiaria();
}

function actualizarDisplayFecha() {
    const display = document.getElementById('fecha-calendario-display');
    if (!display) return;

    const opciones = {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric'
    };
    display.textContent = fechaCalendarioActual.toLocaleString('es-CO', opciones);
}

function cambiarDia(dias) {
    fechaCalendarioActual.setDate(fechaCalendarioActual.getDate() + dias);
    cargarCalendario();
}

function irAHoy() {
    fechaCalendarioActual = new Date();
    cargarCalendario();
}

async function cargarVistaDiaria() {
    const container = document.getElementById('calendario-vista-diaria');
    if (!container) return;

    const fecha = fechaCalendarioActual.toISOString().split('T')[0];
    const areaFiltro = document.getElementById('filtro-area-calendario');
    const turnoFiltro = document.getElementById('filtro-turno-calendario');
    const area = areaFiltro ? areaFiltro.value : '';
    const turno = turnoFiltro ? turnoFiltro.value : '';

    try {
        let url = API_BASE + 'turnos.php?fecha=' + fecha;
        if (area) url += '&area=' + area;

        const response = await fetch(url);
        const data = await response.json();

        console.log('Turnos del dia:', data);

        if (data.success) {
            renderizarVistaDiaria(data.data || [], fecha, turno);
        } else {
            container.innerHTML = '<div class="no-turnos-message"><i class="fas fa-calendar-times"></i><p>Error al cargar turnos</p></div>';
        }
    } catch (error) {
        console.error('Error cargando vista diaria:', error);
        container.innerHTML = '<div class="no-turnos-message"><i class="fas fa-exclamation-triangle"></i><p>Error de conexión</p></div>';
    }
}

function renderizarVistaDiaria(turnos, fecha, filtroTurno) {
    const container = document.getElementById('calendario-vista-diaria');
    if (!container) return;

    // Excluir cancelados
    turnos = turnos.filter(t => t.estado !== 'cancelado');

    // Aplicar filtro de turno si está seleccionado
    if (filtroTurno) {
        turnos = turnos.filter(t => String(t.numero_turno) === String(filtroTurno));
    }

    // Agrupar turnos por su número base: mapear L4/L5 a turno 1/2 para mostrarse junto
    const turnosPorNumero = {};
    turnos.forEach(function(turno) {
      let num = turno.numero_turno;
      // Mapear L4 (4) -> 1 (mañana), L5 (5) -> 2 (tarde). Otros Lx se dejan como están.
      if (num >= 4) {
        if (num === 4) num = 1;
        else if (num === 5) num = 2;
      }
      if (!turnosPorNumero[num]) turnosPorNumero[num] = [];
      turnosPorNumero[num].push(turno);
    });

    const totalAsignados = turnos.length;
    const puestosDefinidos = 17;
    const turnosEsperados = puestosDefinidos * 3;

    const fechaObj = new Date(fecha + 'T00:00:00');
    const fechaFormateada = fechaObj.toLocaleDateString('es-CO', {
      weekday: 'long',
      day: 'numeric',
      month: 'long',
      year: 'numeric'
    });

    let html = '<div class="day-header">';
    html += '<div class="day-info">';
    html += '<h3>' + fechaFormateada.charAt(0).toUpperCase() + fechaFormateada.slice(1) + '</h3>';
    html += '<p>Asignaciones del dia</p>';
    html += '</div>'
    html += '<div class="day-stats">';
    html += '<span class="stat-badge">' + totalAsignados + '/' + turnosEsperados + ' turnos asignados</span>';
    html += '</div>';
    html += '</div>';

    if (totalAsignados === 0) {
      html += '<div class="no-turnos-message">';
      html += '<i class="fas fa-calendar-times"></i>';
      html += '<p>No hay turnos asignados para este dia</p>';
      html += '</div>';
      container.innerHTML = html;
      return;
    }

    const turnosUnicos = {};
    turnos.forEach(function(turno) {
        if (!turnosUnicos[turno.numero_turno]) {
            turnosUnicos[turno.numero_turno] = {
                numero: turno.numero_turno,
                nombre: turno.turno_nombre,
                horario: turno.hora_inicio.substring(0,5) + ' - ' + turno.hora_fin.substring(0,5)
            };
        }
    });

    // Iterar por los números de turno existentes en orden
    const numeros = Object.keys(turnosPorNumero).map(n => parseInt(n,10)).sort((a,b)=>a-b);
    numeros.forEach(function(num) {
      const turnosDelTurno = turnosPorNumero[num] || [];
      const meta = turnosUnicos[num] || { numero: num, nombre: 'TURNO ' + num, horario: '' };

      html += '<div class="turno-section turno-' + num +'">';
      html += '<div class="turno-header">';
      html += '<span class="turno-icon">' + (num === 1 ? '' : num === 2 ? '' : num === 3 ? '' : '') + '</span>';
      html += '<div class="turno-tittle">';
      html += '<h4>' + (meta.nombre || ('TURNO ' + num)) + '</h4>';
      html += '<p>' + (meta.horario || '') + '</p>';
      html += '</div>';
      html += '<span class="turno-count">' + turnosDelTurno.length + ' asignados</span>';
      html += '</div>';

      if (turnosDelTurno.length > 0) {
        html += renderizarPuestosPorArea(turnosDelTurno);
      } else {
        html += '<p style="text-align: center; color: #7f8c8d; padding: 1rem;">Sin asignaciones en este turno</p>';
      }

      html += '</div>';
    });

    container.innerHTML = html;
    
}

function renderizarPuestosPorArea(turnos) {
    const  areas = {
      'DELTA': [],
      'FOX': [],
      'VIGIA': [],
      'TASA DE USO': [],
      'EQUIPAJES': []
    };

    turnos.forEach(function(turno) {
      if (areas[turno.area]) {
          areas[turno.area].push(turno);
      }
    });

    let html = '<div class="area-grid">';

    Object.keys(areas).forEach(function(area) {
      const turnosArea = areas[area];

      if (turnosArea.length > 0) {
        html += '<div class="area-group">';
        html += '<h5><i class="fas fa-map-marker-alt"></i> ' + area + '</h5>';
        html += '<div class="puestos-list">';

        turnosArea.forEach(function(turno) {
          html += '<div class="puesto-item ocupado">';
                const origNum = Number(turno.numero_turno) || 0;
            let codigoCorto = '';
            if (origNum >= 4) {
              // Mapear L4->base1, L5->base2 (como en el agrupamiento)
              const base = (origNum === 4) ? 1 : (origNum === 5) ? 2 : origNum;
              codigoCorto = base + 'L' + origNum + (turno.puesto_codigo || '');
            } else {
              codigoCorto = String(origNum) + (turno.puesto_codigo || '');
            }
              html += '<span class="puesto-code">' + codigoCorto + '</span>';
              html += '<span class="trabajador-name">' + turno.trabajador + '</span>';
              html += '<span class="puesto-actions">';
              const fechaEsc = (turno.fecha || '').replace(/'/g, "\\'");
              html += "<button class=\"btn btn-sm btn-outline\" title=\"Editar asignación\" onclick=\"editarAsignacion(" + (turno.id || 0) + ", " + (turno.puesto_id || 'null') + ", '" + fechaEsc + "', " + origNum + ", " + (turno.trabajador_id || 'null') + ")\"><i class=\"fas fa-edit\"></i></button>";
              html += '</span>';
          html += '<span class="puesto-status status-ok">✓</span>';
          html += '</div>';
        });
        html += '</div></div>'; 
      }
    });

    html += '</div>';
    return html;
}

function cambiarMes(direccion) {
  mesActual += direccion;
  if (mesActual > 11) {
    mesActual = 0;
    anioActual++;
  } else if (mesActual < 0) {
    mesActual = 11;
    anioActual--;
  }
}
async function exportarDiaExcel() {
  const fecha = fechaCalendarioActual.toISOString().split('T')[0];
  const areaFiltro = document.getElementById('filtro-area-calendario');
  const area = areaFiltro ? areaFiltro.value : '';

  try {
    let url = API_BASE + 'turnos.php?fecha=' + fecha;
    if (area) {
      url += '&area=' + area;
    }

    const response = await fetch(url);
    const data = await response.json();

    if (!data.success || !data.data || data.data.length === 0) {
      mostrarAlerta('No hay turnos para exportar este dia', 'warning');
      return;
    }

    const turnos = data.data;

    //crear CSV

    let csv = '\uFEFF';
    csv += 'Turno,Horario,Trabajador,Cedula,Puesto,Area\n';

    turnos.forEach(function(turno) {
      csv += '"' + turno.turno_nombre + '",';
      csv += '"' + turno.hora_inicio.substring(0,5) + ' - ' +turno.hora_fin.substring(0,5) + '",';
      csv += '"' + turno.trabajador + '",';
      csv += turno.cedula + ',';
      csv += '"' + turno.puesto_codigo + ' - ' + turno.puesto_nombre + '",';
      csv += '"' + turno.area + '"\n';
    });

    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;'});
    const link = document.createElement('a');
    const urlBlob = URL.createObjectURL(blob);

    const fechaFormateada = fechaCalendarioActual.toLocaleDateString('es-CO').replace(/\//g, '-');
    link.setAttribute('href', urlBlob);
    link.setAttribute('download', 'Turnos_' + fechaFormateada + ' .csv');
    link.style.visibility = 'hidden';

    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);

    mostrarAlerta('Turnos exportados a Excel exitosamente', 'success');
  } catch (error) {
    console.error('Error exportando:', error);
    mostrarAlerta('Error al exportar a Excel', 'danger');
  }
}

async function exportarDiaPDF() {
    const fecha = fechaCalendarioActual.toISOString().split('T')[0];
    const areaFiltro = document.getElementById('filtro-area-calendario');
    const area = areaFiltro ? areaFiltro.value : '';
    
    try {
        let url = API_BASE + 'turnos.php?fecha=' + fecha;
        if (area) {
            url += '&area=' + area;
        }
        
        const response = await fetch(url);
        const data = await response.json();
        
        if (!data.success || !data.data || data.data.length === 0) {
            mostrarAlerta('No hay turnos para exportar este día', 'warning');
            return;
        }
        
        const turnos = data.data;
        const fechaObj = new Date(fecha + 'T00:00:00');
        const fechaFormateada = fechaObj.toLocaleDateString('es-CO', { 
            weekday: 'long', 
            day: 'numeric', 
            month: 'long',
            year: 'numeric'
        });
        
        // Agrupar por turno
        const turnosPorNumero = { 1: [], 2: [], 3: [] };
        turnos.forEach(function(turno) {
            if (turnosPorNumero[turno.numero_turno]) {
                turnosPorNumero[turno.numero_turno].push(turno);
            }
        });
        
        mostrarAlerta('⏳ Generando PDF...', 'info');
        
        // Crear contenedor temporal para el PDF
        const container = document.createElement('div');
        container.style.position = 'absolute';
        container.style.left = '-9999px';
        container.style.width = '210mm'; // Ancho A4
        container.style.padding = '20px';
        container.style.background = 'white';
        container.style.fontFamily = 'Arial, sans-serif';
        
        let html = '';
        html += '<div style="text-align: center; margin-bottom: 30px;">';
        html += '<h1 style="color: #1e3c72; margin: 0; font-size: 22px;">Terminal de Transportes de Ibagué</h1>';
        html += '<h2 style="color: #6c757d; margin: 10px 0 0 0; font-size: 16px; font-weight: normal;">';
        html += fechaFormateada.charAt(0).toUpperCase() + fechaFormateada.slice(1) + '</h2>';
        html += '</div>';
        
        const turnosConfig = [
            { numero: 1, nombre: 'TURNO 1 - MAÑANA', horario: '06:00 - 14:00', icono: '', color: '#0056b3' },
            { numero: 2, nombre: 'TURNO 2 - TARDE', horario: '14:00 - 22:00', icono: '', color: '#e65100' },
            { numero: 3, nombre: 'TURNO 3 - NOCHE', horario: '22:00 - 06:00', icono: '', color: '#6a1b9a' }
        ];
        
        turnosConfig.forEach(function(config) {
            const turnosDelTurno = turnosPorNumero[config.numero];
            
            html += '<div style="margin-bottom: 25px; border: 1px solid #e9ecef; border-radius: 6px; overflow: hidden;">';
            html += '<div style="background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); padding: 12px 18px; border-bottom: 3px solid ' + config.color + ';">';
            html += '<h3 style="margin: 0; color: #212529; font-size: 14px;">' + config.icono + ' ' + config.nombre + '</h3>';
            html += '<p style="margin: 5px 0 0 0; color: #6c757d; font-size: 11px;">' + config.horario + ' - ' + turnosDelTurno.length + ' trabajadores</p>';
            html += '</div>';
            
            if (turnosDelTurno.length > 0) {
                html += '<table style="width: 100%; border-collapse: collapse;">';
                html += '<thead>';
                html += '<tr style="background: #f8f9fa;">';
                html += '<th style="padding: 8px; text-align: left; font-size: 10px; color: #495057; border-bottom: 2px solid #dee2e6;">Puesto</th>';
                html += '<th style="padding: 8px; text-align: left; font-size: 10px; color: #495057; border-bottom: 2px solid #dee2e6;">Trabajador</th>';
                html += '<th style="padding: 8px; text-align: left; font-size: 10px; color: #495057; border-bottom: 2px solid #dee2e6;">Cédula</th>';
                html += '<th style="padding: 8px; text-align: left; font-size: 10px; color: #495057; border-bottom: 2px solid #dee2e6;">Área</th>';
                html += '</tr>';
                html += '</thead><tbody>';
                
                turnosDelTurno.forEach(function(turno) {
                    html += '<tr>';
                    html += '<td style="padding: 8px; font-size: 10px; border-bottom: 1px solid #f1f3f5;"><strong>' + turno.puesto_codigo + '</strong> - ' + turno.puesto_nombre + '</td>';
                    html += '<td style="padding: 8px; font-size: 10px; border-bottom: 1px solid #f1f3f5;">' + turno.trabajador + '</td>';
                    html += '<td style="padding: 8px; font-size: 10px; border-bottom: 1px solid #f1f3f5;">' + turno.cedula + '</td>';
                    html += '<td style="padding: 8px; font-size: 10px; border-bottom: 1px solid #f1f3f5; font-weight: 600;">' + turno.area + '</td>';
                    html += '</tr>';
                });
                
                html += '</tbody></table>';
            } else {
                html += '<p style="text-align: center; padding: 20px; color: #6c757d; font-size: 11px;">Sin asignaciones</p>';
            }
            
            html += '</div>';
        });
        
        html += '<div style="margin-top: 30px; text-align: center; color: #adb5bd; font-size: 9px; border-top: 1px solid #e9ecef; padding-top: 15px;">';
        html += 'Generado: ' + new Date().toLocaleDateString('es-CO') + ' - ' + new Date().toLocaleTimeString('es-CO');
        html += '<br>Sistema de Gestión de Turnos';
        html += '</div>';
        
        container.innerHTML = html;
        document.body.appendChild(container);
        
        // Generar PDF
        const canvas = await html2canvas(container, {
            scale: 2,
            useCORS: true,
            logging: false
        });
        
        const imgData = canvas.toDataURL('image/png');
        const { jsPDF } = window.jspdf;
        const pdf = new jsPDF('p', 'mm', 'a4');
        
        const imgWidth = 210; // A4 width in mm
        const imgHeight = (canvas.height * imgWidth) / canvas.width;
        
        pdf.addImage(imgData, 'PNG', 0, 0, imgWidth, imgHeight);
        
        const nombreArchivo = 'Turnos_' + fechaObj.toLocaleDateString('es-CO').replace(/\//g, '-') + '.pdf';
        pdf.save(nombreArchivo);
        
        document.body.removeChild(container);
        
        mostrarAlerta('PDF descargado exitosamente', 'success');
        
    } catch (error) {
        console.error('Error exportando PDF:', error);
        mostrarAlerta('Error al exportar a PDF', 'danger');
    }
}
//Exportacion mensual
async function exportarMesExcel() {
  const mes = fechaCalendarioActual.getMonth() + 1;
  const anio = fechaCalendarioActual.getFullYear();

  try {
    const response = await fetch(API_BASE + 'turnos.php?action=calendario&mes=' + mes + '&anio=' + anio);
    const data = await response.json();

    if (!data.success || !data.data || data.data.length === 0) {
      mostrarAlerta('No hay turnos para exportar este mes', 'warning');
      return;
    }

    const turnos = data.data;

    let csv = '\uFEFF';
    csv += 'Fecha,Día,Turno,Horario,Trabajador,Cédula,Puesto,Área,Estado\n';

    turnos.sort(function(a, b) {
      if (a.fecha !== b.fecha) {
        return a.fecha.localeCompare(b.fecha);
      }
      return a.numero_turno - b.numero_turno;
    });

    turnos.forEach(function(turno) {
      const fechaObj = new Date(turno.fecha + 'T00:00:00');
      const diaSemana = fechaObj.toLocaleDateString('es-CO', { weekday: 'long'});

      csv += turno.fecha + ',';
      csv += '"' + diaSemana.charAt(0).toUpperCase() + diaSemana.slice(1) + '",';
      csv += '"' + turno.turno_nombre + '",';
      csv += '"' + turno.hora_inicio.substring(0,5) + ' - ' + turno.hora_fin.substring(0,5) + '",';
      csv += '"' + turno.trabajador + '",';
      csv += turno.cedula + ',';
      csv += '"' + turno.puesto_codigo + ' - ' + turno.puesto_nombre + '",';
      csv += '"' + turno.area + '",';
      csv += turno.estado + '\n';
    });

    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;'});
    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);

    const mesNombre = fechaCalendarioActual.toLocaleDateString('es-CO', { month: 'long'});
    link.setAttribute('href', url);
    link.setAttribute('download', 'Turnos_' + mesNombre.charAt(0).toUpperCase() + mesNombre.slice(1) + mesNombre.slice(1) + '_' + anio + '.csv');
    link.style.visibility = 'hidden';

    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);

    mostrarAlerta('Mes completo exportado a Excel (' + turnos.length + ' turnos)', 'success');

  } catch (error) {
    console.error('Error exportando mes:', error)
    mostrarAlerta('Error al exportar mes a Excel', 'danger');
  }
}
async function exportarMesPDF() {
    const mes = fechaCalendarioActual.getMonth() + 1;
    const anio = fechaCalendarioActual.getFullYear();
    
    try {
        const response = await fetch(API_BASE + 'turnos.php?action=calendario&mes=' + mes + '&anio=' + anio);
        const data = await response.json();
        
        if (!data.success || !data.data || data.data.length === 0) {
            mostrarAlerta('No hay turnos para exportar este mes', 'warning');
            return;
        }
        
        const turnos = data.data.filter(t => t.estado !== 'cancelado');
        const mesNombre = fechaCalendarioActual.toLocaleDateString('es-CO', { month: 'long', year: 'numeric' });
        
        // Agrupar por fecha
        const turnosPorFecha = {};
        turnos.forEach(function(turno) {
            if (!turnosPorFecha[turno.fecha]) turnosPorFecha[turno.fecha] = [];
            turnosPorFecha[turno.fecha].push(turno);
        });
        
        const fechasOrdenadas = Object.keys(turnosPorFecha).sort();
        mostrarAlerta('⏳ Generando PDF del mes...', 'info');

        const { jsPDF } = window.jspdf;
        const pdf = new jsPDF('p', 'mm', 'a4');
        const pageW = 210;
        const pageH = 297;
        const margin = 12;
        const contentW = pageW - margin * 2;

        const borderColors = { 1: '#0056b3', 2: '#e65100', 3: '#6a1b9a' };
        const turnosNombres = { 1: 'Turno 1 - Mañana  06:00-14:00', 2: 'Turno 2 - Tarde  14:00-22:00', 3: 'Turno 3 - Noche  22:00-06:00' };

        let isFirstPage = true;

        for (const fecha of fechasOrdenadas) {
            const turnosDelDia = turnosPorFecha[fecha];
            const fechaObj = new Date(fecha + 'T00:00:00');
            const fechaFormateada = fechaObj.toLocaleDateString('es-CO', {
                weekday: 'long', day: 'numeric', month: 'long', year: 'numeric'
            });

            const porTurno = { 1: [], 2: [], 3: [] };
            turnosDelDia.forEach(t => { if (porTurno[t.numero_turno]) porTurno[t.numero_turno].push(t); });

            const container = document.createElement('div');
            container.style.cssText = 'position:absolute;left:-9999px;width:186mm;padding:0;background:white;font-family:Arial,sans-serif;';

            let html = `<div style="text-align:center;margin-bottom:14px;padding-bottom:10px;border-bottom:2px solid #1e3c72;">
                <div style="font-size:13px;font-weight:bold;color:#1e3c72;">Terminal de Transportes de Ibagué</div>
                <div style="font-size:10px;color:#6c757d;margin-top:3px;">Gestión de Turnos</div>
                <div style="font-size:11px;font-weight:600;color:#212529;margin-top:5px;">${fechaFormateada.charAt(0).toUpperCase() + fechaFormateada.slice(1)}</div>
                <div style="font-size:9px;color:#6c757d;">${turnosDelDia.length} turnos asignados</div>
            </div>`;

            [1, 2, 3].forEach(numTurno => {
                const lista = porTurno[numTurno];
                const color = borderColors[numTurno];
                html += `<div style="margin-bottom:14px;border:1px solid #e9ecef;border-radius:5px;overflow:hidden;">
                    <div style="background:#f8f9fa;padding:7px 10px;border-left:4px solid ${color};border-bottom:1px solid #e9ecef;">
                        <span style="font-size:10px;font-weight:700;color:#212529;">${turnosNombres[numTurno]}</span>
                        <span style="font-size:9px;color:#6c757d;margin-left:8px;">${lista.length} trabajadores</span>
                    </div>`;

                if (lista.length > 0) {
                    html += `<table style="width:100%;border-collapse:collapse;">
                        <thead><tr style="background:#f1f3f5;">
                            <th style="padding:5px 7px;font-size:8px;text-align:left;color:#495057;border-bottom:1px solid #dee2e6;">Trabajador</th>
                            <th style="padding:5px 7px;font-size:8px;text-align:left;color:#495057;border-bottom:1px solid #dee2e6;">Cédula</th>
                            <th style="padding:5px 7px;font-size:8px;text-align:left;color:#495057;border-bottom:1px solid #dee2e6;">Puesto</th>
                            <th style="padding:5px 7px;font-size:8px;text-align:left;color:#495057;border-bottom:1px solid #dee2e6;">Área</th>
                        </tr></thead><tbody>`;
                    lista.forEach((t, i) => {
                        const bg = i % 2 === 0 ? 'white' : '#f8f9fa';
                        html += `<tr style="background:${bg};">
                            <td style="padding:5px 7px;font-size:9px;border-bottom:1px solid #f1f3f5;">${t.trabajador}</td>
                            <td style="padding:5px 7px;font-size:9px;border-bottom:1px solid #f1f3f5;">${t.cedula}</td>
                            <td style="padding:5px 7px;font-size:9px;border-bottom:1px solid #f1f3f5;">${t.puesto_codigo} - ${t.puesto_nombre}</td>
                            <td style="padding:5px 7px;font-size:9px;font-weight:600;border-bottom:1px solid #f1f3f5;">${t.area}</td>
                        </tr>`;
                    });
                    html += `</tbody></table>`;
                } else {
                    html += `<p style="text-align:center;padding:10px;color:#adb5bd;font-size:9px;margin:0;">Sin asignaciones</p>`;
                }
                html += `</div>`;
            });

            html += `<div style="text-align:right;font-size:7px;color:#adb5bd;margin-top:6px;">
                Generado: ${new Date().toLocaleDateString('es-CO')} ${new Date().toLocaleTimeString('es-CO')} · Sistema de Gestión de Turnos
            </div>`;

            container.innerHTML = html;
            document.body.appendChild(container);

            const canvas = await html2canvas(container, { scale: 2, useCORS: true, logging: false });
            document.body.removeChild(container);

            const imgData = canvas.toDataURL('image/png');
            const imgH = (canvas.height * contentW) / canvas.width;

            if (!isFirstPage) pdf.addPage();
            isFirstPage = false;

            if (imgH <= pageH - margin * 2) {
                pdf.addImage(imgData, 'PNG', margin, margin, contentW, imgH);
            } else {
                const scaledH = pageH - margin * 2;
                const scaledW = (canvas.width * scaledH) / canvas.height;
                const offsetX = margin + (contentW - scaledW) / 2;
                pdf.addImage(imgData, 'PNG', offsetX, margin, scaledW, scaledH);
            }
        }

        const nombreArchivo = 'Turnos_' + mesNombre.replace(/ /g, '_') + '.pdf';
        pdf.save(nombreArchivo);
        mostrarAlerta('✅ PDF del mes descargado (' + turnos.length + ' turnos, ' + fechasOrdenadas.length + ' días)', 'success');

    } catch (error) {
        console.error('Error exportando PDF:', error);
        mostrarAlerta('Error al exportar a PDF', 'danger');
    }
}
async function cargarTablaRestricciones() {
    const tabla = document.getElementById('tabla-restricciones');
    if (!tabla) return;

    try {
        const response = await fetch(API_BASE + 'trabajadores.php');
        const data = await response.json();

        if (data.success && Array.isArray(data.data)) {
            const conRestricciones = data.data.filter(t => t.restricciones && t.restricciones.trim() !== '');

            if (conRestricciones.length === 0) {
                tabla.innerHTML = `<div style="text-align:center; padding:3rem 1rem; color:#666;">
                    <i class="fas fa-shield-alt" style="font-size:4rem; color:#ddd; display:block; margin-bottom:1rem;"></i>
                    <p style="font-size:1.1rem; margin-bottom:1.5rem;">No hay restricciones médicas o laborales registradas</p>
                    <button class="btn btn-primary" onclick="agregarRestriccionDesdeSeccion()"><i class="fas fa-plus"></i> Agregar Primera Restricción</button>
                </div>`;
                return;
            }

            let html = '<table><thead><tr style="background: linear-gradient(135deg, var(--terminal) 0%, #027433 100%); color: white;">';
            html += '<th>Trabajador</th><th>Cédula</th><th>Restricciones Activas</th><th>Acciones</th></tr></thead><tbody>';

            conRestricciones.forEach(t => {
                const badgesHTML = t.restricciones.split(',').map(r => {
                    r = r.trim();
                    let icon = 'fa-shield-alt';
                    if (r.includes('noche')) icon = 'fa-moon';
                    else if (r.includes('fuerza')) icon = 'fa-dumbbell';
                    else if (r.includes('movilidad')) icon = 'fa-wheelchair';
                    else if (r.includes('visual')) icon = 'fa-eye-slash';
                    return `<span style="background:#e74c3c20;color:#e74c3c;padding:0.3rem 0.6rem;border-radius:4px;font-size:0.85rem;display:inline-flex;align-items:center;gap:0.3rem;margin:0.2rem;"><i class="fas ${icon}"></i> ${r}</span>`;
                }).join('');
                html += `<tr>
                    <td><strong>${t.nombre}</strong></td>
                    <td>${t.cedula}</td>
                    <td>${badgesHTML}</td>
                    <td>
                        <button class="btn-icon" onclick="verDetallesRestriccionesTrabajador(${t.id})" title="Ver detalles"><i class="fas fa-eye"></i></button>
                        <button class="btn-icon" onclick="agregarRestriccionATrabajador(${t.id})" title="Agregar restricción"><i class="fas fa-plus"></i></button>
                    </td>
                </tr>`;
            });
            html += '</tbody></table>';
            tabla.innerHTML = html;
        } else {
            tabla.innerHTML = '<div class="alert alert-danger">Error al cargar restricciones</div>';
        }
    } catch (error) {
        console.error('Error cargando restricciones:', error);
        tabla.innerHTML = '<div class="alert alert-danger">Error al cargar restricciones</div>';
    }
}

async function agregarRestriccionDesdeSeccion() {
    try {
        const response = await fetch(API_BASE + 'trabajadores.php');
        const data = await response.json();
        if (!data.success) { mostrarAlerta('Error al cargar trabajadores', 'danger'); return; }

        const hoy = new Date().toISOString().split('T')[0];
        const modalOverlay = document.getElementById('modal-overlay');
        const modalTitulo = document.getElementById('modal-titulo');
        const modalBody = document.getElementById('modal-body');

        modalTitulo.textContent = 'Agregar Restricción Médica/Laboral';
        modalBody.innerHTML = `
            <div class="form-group">
                <label>Trabajador <span class="required">*</span></label>
                <select id="trabajador-restriccion" required>
                    <option value="">Seleccione...</option>
                    ${data.data.filter(t => t.activo).map(t => `<option value="${t.id}">${t.nombre} - ${t.cedula}</option>`).join('')}
                </select>
            </div>
            <div class="form-group">
                <label>Tipo de Restricción <span class="required">*</span></label>
                <select id="tipo-restriccion" required>
                    <option value="">Seleccione...</option>
                    <option value="no_fuerza_fisica">No Fuerza Física</option>
                    <option value="no_turno_noche">No Turno Noche</option>
                    <option value="movilidad_limitada">Movilidad Limitada</option>
                    <option value="problema_visual">Problema Visual</option>
                </select>
            </div>
            <div class="form-group">
                <label>Descripción</label>
                <textarea id="descripcion-restriccion" rows="3" placeholder="Detalles adicionales sobre la restricción"></textarea>
            </div>
            <div class="form-grid">
                <div class="form-group">
                    <label>Fecha Inicio <span class="required">*</span></label>
                    <input type="date" id="fecha-inicio-restriccion" value="${hoy}" required>
                </div>
                <div class="form-group">
                    <label>Fecha Fin</label>
                    <input type="date" id="fecha-fin-restriccion">
                    <small style="color:#666;">Dejar vacío si es permanente</small>
                </div>
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-primary" onclick="guardarNuevaRestriccion()"><i class="fas fa-save"></i> Guardar</button>
                <button type="button" class="btn btn-outline" onclick="cerrarModal()"><i class="fas fa-times"></i> Cancelar</button>
            </div>`;
        modalOverlay.classList.add('active');
    } catch (error) {
        console.error('Error:', error);
        mostrarAlerta('Error al abrir formulario', 'danger');
    }
}

async function guardarNuevaRestriccion() {
    const trabajadorId = document.getElementById('trabajador-restriccion').value;
    const tipoRestriccion = document.getElementById('tipo-restriccion').value;
    const descripcion = document.getElementById('descripcion-restriccion').value;
    const fechaInicio = document.getElementById('fecha-inicio-restriccion').value;
    const fechaFin = document.getElementById('fecha-fin-restriccion').value;

    if (!trabajadorId || !tipoRestriccion || !fechaInicio) {
        mostrarAlerta('Por favor completa los campos obligatorios', 'warning');
        return;
    }
    try {
        const response = await fetch(API_BASE + 'trabajadores.php?path=restriccion', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ trabajador_id: parseInt(trabajadorId), tipo_restriccion: tipoRestriccion, descripcion: descripcion || null, fecha_inicio: fechaInicio, fecha_fin: fechaFin || null })
        });
        const result = await response.json();
        if (result.success) {
            mostrarAlerta('Restricción agregada exitosamente', 'success');
            cerrarModal();
            cargarTablaRestricciones();
        } else {
            mostrarAlerta(result.message || 'Error al agregar restricción', 'danger');
        }
    } catch (error) {
        mostrarAlerta('Error al guardar restricción', 'danger');
    }
}

async function agregarRestriccionATrabajador(trabajadorId) {
    try {
        const response = await fetch(API_BASE + 'trabajadores.php?id=' + trabajadorId);
        const data = await response.json();
        if (!data.success || !data.data) { mostrarAlerta('Error al cargar datos del trabajador', 'danger'); return; }

        const trabajador = data.data;
        const hoy = new Date().toISOString().split('T')[0];
        const modalOverlay = document.getElementById('modal-overlay');
        const modalTitulo = document.getElementById('modal-titulo');
        const modalBody = document.getElementById('modal-body');

        modalTitulo.textContent = `Agregar Restricción - ${trabajador.nombre}`;
        modalBody.innerHTML = `
            <div class="info-box" style="margin-bottom:1rem;">
                <p><strong>Trabajador:</strong> ${trabajador.nombre}</p>
                <p><strong>Cédula:</strong> ${trabajador.cedula}</p>
            </div>
            <div class="form-group">
                <label>Tipo de Restricción <span class="required">*</span></label>
                <select id="tipo-restriccion-t" required>
                    <option value="">Seleccione...</option>
                    <option value="no_fuerza_fisica">No Fuerza Física</option>
                    <option value="no_turno_noche">No Turno Noche</option>
                    <option value="movilidad_limitada">Movilidad Limitada</option>
                    <option value="problema_visual">Problema Visual</option>
                </select>
            </div>
            <div class="form-group">
                <label>Descripción</label>
                <textarea id="descripcion-restriccion-t" rows="3" placeholder="Detalles adicionales"></textarea>
            </div>
            <div class="form-grid">
                <div class="form-group">
                    <label>Fecha Inicio <span class="required">*</span></label>
                    <input type="date" id="fecha-inicio-restriccion-t" value="${hoy}" required>
                </div>
                <div class="form-group">
                    <label>Fecha Fin</label>
                    <input type="date" id="fecha-fin-restriccion-t">
                    <small style="color:#666;">Dejar vacío si es permanente</small>
                </div>
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-primary" onclick="guardarRestriccionTrabajador(${trabajadorId})"><i class="fas fa-save"></i> Guardar</button>
                <button type="button" class="btn btn-outline" onclick="cerrarModal()"><i class="fas fa-times"></i> Cancelar</button>
            </div>`;
        modalOverlay.classList.add('active');
    } catch (error) {
        mostrarAlerta('Error al abrir formulario', 'danger');
    }
}

async function guardarRestriccionTrabajador(trabajadorId) {
    const tipoRestriccion = document.getElementById('tipo-restriccion-t').value;
    const descripcion = document.getElementById('descripcion-restriccion-t').value;
    const fechaInicio = document.getElementById('fecha-inicio-restriccion-t').value;
    const fechaFin = document.getElementById('fecha-fin-restriccion-t').value;

    if (!tipoRestriccion || !fechaInicio) {
        mostrarAlerta('Por favor completa los campos obligatorios', 'warning');
        return;
    }
    try {
        const response = await fetch(API_BASE + 'trabajadores.php?path=restriccion', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ trabajador_id: trabajadorId, tipo_restriccion: tipoRestriccion, descripcion: descripcion || null, fecha_inicio: fechaInicio, fecha_fin: fechaFin || null })
        });
        const result = await response.json();
        if (result.success) {
            mostrarAlerta('Restricción agregada exitosamente', 'success');
            cerrarModal();
            cargarTablaRestricciones();
        } else {
            mostrarAlerta(result.message || 'Error al agregar restricción', 'danger');
        }
    } catch (error) {
        mostrarAlerta('Error al guardar restricción', 'danger');
    }
}

async function verDetallesRestriccionesTrabajador(trabajadorId) {
    try {
        const response = await fetch(API_BASE + 'trabajadores.php?id=' + trabajadorId);
        const data = await response.json();
        if (!data.success || !data.data) { mostrarAlerta('Error al cargar datos', 'danger'); return; }

        const trabajador = data.data;
        let restriccionesHTML = '';

        if (trabajador.restricciones && Array.isArray(trabajador.restricciones) && trabajador.restricciones.length > 0) {
            restriccionesHTML = trabajador.restricciones.map(rest => {
                const fechaFinTexto = (!rest.fecha_fin) ? 'Permanente' : new Date(rest.fecha_fin + 'T00:00:00').toLocaleDateString('es-CO');
                return `<div style="border:1px solid #ddd;padding:1rem;border-radius:8px;margin-bottom:1rem;">
                    <div style="display:flex;justify-content:space-between;align-items:start;margin-bottom:0.5rem;">
                        <h4 style="margin:0;color:#e74c3c;"><i class="fas fa-shield-alt"></i> ${rest.tipo_restriccion.replace(/_/g, ' ').toUpperCase()}</h4>
                        <button class="btn-icon" onclick="eliminarRestriccion(${rest.id})" title="Eliminar"><i class="fas fa-trash"></i></button>
                    </div>
                    <p><strong>Desde:</strong> ${new Date(rest.fecha_inicio + 'T00:00:00').toLocaleDateString('es-CO')}</p>
                    <p><strong>Hasta:</strong> ${fechaFinTexto}</p>
                    ${rest.descripcion ? `<p><strong>Descripción:</strong> ${rest.descripcion}</p>` : ''}
                    <span style="background:${rest.activa ? '#28a745' : '#6c757d'};color:white;padding:0.3rem 0.6rem;border-radius:4px;font-size:0.85rem;">${rest.activa ? 'Activa' : 'Inactiva'}</span>
                </div>`;
            }).join('');
        } else {
            restriccionesHTML = '<p class="info-box">Este trabajador no tiene restricciones registradas.</p>';
        }

        const modalOverlay = document.getElementById('modal-overlay');
        const modalTitulo = document.getElementById('modal-titulo');
        const modalBody = document.getElementById('modal-body');

        modalTitulo.textContent = `Restricciones - ${trabajador.nombre}`;
        modalBody.innerHTML = `
            <div class="info-box" style="margin-bottom:1rem;">
                <p><strong>Trabajador:</strong> ${trabajador.nombre}</p>
                <p><strong>Cédula:</strong> ${trabajador.cedula}</p>
                <p><strong>Cargo:</strong> ${trabajador.cargo || 'No especificado'}</p>
            </div>
            <h4 style="margin-bottom:1rem;">Restricciones Registradas:</h4>
            <div style="max-height:350px;overflow-y:auto;">${restriccionesHTML}</div>
            <div class="form-actions" style="margin-top:1.5rem;">
                <button class="btn btn-primary" onclick="cerrarModal(); setTimeout(() => agregarRestriccionATrabajador(${trabajadorId}), 300)">
                    <i class="fas fa-plus"></i> Agregar Nueva Restricción
                </button>
                <button class="btn btn-outline" onclick="cerrarModal()"><i class="fas fa-times"></i> Cerrar</button>
            </div>`;
        modalOverlay.classList.add('active');
    } catch (error) {
        mostrarAlerta('Error al cargar detalles', 'danger');
    }
}

async function eliminarRestriccion(restriccionId) {
    if (!confirm('¿Está seguro de eliminar esta restricción?')) return;
    try {
        const response = await fetch(API_BASE + 'trabajadores.php?path=restriccion&id=' + restriccionId, { method: 'DELETE' });
        const result = await response.json();
        if (result.success) {
            mostrarAlerta('Restricción eliminada exitosamente', 'success');
            cerrarModal();
            cargarTablaRestricciones();
        } else {
            mostrarAlerta(result.message || 'Error al eliminar restricción', 'danger');
        }
    } catch (error) {
        mostrarAlerta('Error al eliminar restricción', 'danger');
    }
}
async function cargarTablaIncapacidades() {
    const tabla = document.getElementById('tabla-incapacidades');
    if (!tabla) return;
    
    try {
        const response = await fetch(API_BASE + 'incapacidades.php');
        const data = await response.json();
        
        if (data.success && data.data.length > 0) {
            let html = `
                <div class="tabla-responsiva">
                    <table class="tabla-incapacidades">
                        <thead>
                            <tr>
                                <th>Cédula</th>
                                <th>Trabajador</th>
                                <th>Tipo</th>
                                <th>Fecha Inicio</th>
                                <th>Fecha Fin</th>
                                <th>Días</th>
                                <th>EPS</th>
                                <th>Descripción</th>
                                <th>Estado</th>
                                <th>Documento</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
            `;
            
            data.data.forEach(function(inc) {
                const estadoClass = inc.estado === 'activa' ? 'badge-danger' : 'badge-success';
                const estadoTexto = inc.estado === 'activa' ? '🔴 Activa' : '🟢 Finalizada';
                
                // Mapa de códigos a texto legible
                const tipoMap = {
                    'EG': 'Enfermedad General',
                    'AT': 'Accidente de Trabajo',
                    'EL': 'Enfermedad Laboral',
                    'LM': 'Lic. Maternidad',
                    'LP': 'Lic. Paternidad',
                    'CIR': 'Cirugía',
                    // Textos completos (por si se guardaron así)
                    'Enfermedad General': 'Enfermedad General',
                    'Accidente de Trabajo': 'Accidente de Trabajo',
                    'Accidente Trabajo': 'Accidente de Trabajo',
                    'Enfermedad Laboral': 'Enfermedad Laboral',
                    'Licencia de Maternidad': 'Lic. Maternidad',
                    'Licencia de Paternidad': 'Lic. Paternidad',
                    'Cirugía': 'Cirugía', 'Cirugia': 'Cirugía'
                };
                // Decodificar entidades HTML antes de mapear
                const tipoRaw = (inc.tipo || '')
                    .replace(/&iacute;/g, 'í').replace(/&aacute;/g, 'á')
                    .replace(/&eacute;/g, 'é').replace(/&oacute;/g, 'ó')
                    .replace(/&uacute;/g, 'ú').replace(/&ntilde;/g, 'ñ');
                const tipoIncapacidad = tipoMap[tipoRaw] || tipoRaw || '-';

                let btnDocumento = '<span class="texto-muted">-</span>';
                if (inc.documento_soporte) {
                    btnDocumento = `<a href="uploads/incapacidades/${inc.documento_soporte}" target="_blank" class="btn-icon" title="Descargar"><i class="fas fa-download"></i></a>`;
                }

                html += `
                    <tr class="fila-incapacidad">
                        <td><strong>${inc.cedula || '-'}</strong></td>
                        <td>${inc.trabajador || '-'}</td>
                        <td><span class="badge-tipo">${tipoIncapacidad}</span></td>
                        <td>${inc.fecha_inicio || '-'}</td>
                        <td>${inc.fecha_fin || '-'}</td>
                        <td><span class="badge-dias">${inc.dias_incapacidad}</span></td>
                        <td>${inc.eps || '-'}</td>
                        <td><small>${inc.descripcion || '-'}</small></td>
                        <td><span class="badge ${estadoClass}">${estadoTexto}</span></td>
                        <td>${btnDocumento}</td>
                        <td>
                            <button class="btn-icon" onclick="editarIncapacidad(${inc.id})" title="Editar"><i class="fas fa-edit"></i></button>
                            <button class="btn-icon btn-danger" onclick="eliminarIncapacidad(${inc.id})" title="Eliminar"><i class="fas fa-trash"></i></button>
                        </td>
                    </tr>
                `;
            });
            
            html += '</tbody></table></div>';
            tabla.innerHTML = html;
        } else {
            tabla.innerHTML = '<p class="info-box">No hay incapacidades registradas.</p>';
        }
        
    } catch (error) {
        console.error('Error cargando incapacidades:', error);
        tabla.innerHTML = '<p class="alert alert-danger">Error al cargar incapacidades</p>';
    }
}

async function cargarTablaDiasEspeciales() {
    const tabla = document.getElementById('tabla-dias-especiales');
    if (!tabla) return;
    
    try {
        const response = await fetch(`${API_BASE}dias_especiales.php`);
        const data = await response.json();
        
        if (data.success && data.data.length > 0) {
            const coloresTipo = {
                'LC': '#6f42c1', 'L': '#0d6efd', 'L8': '#0d6efd',
                'VAC': '#198754', 'SUS': '#dc3545',
                'ADMM': '#fd7e14', 'ADMT': '#fd7e14', 'ADM': '#fd7e14'
            };

            let html = `
                <table>
                    <thead>
                        <tr style="background: linear-gradient(135deg, var(--terminal) 0%, #027433 100%); color: white;">
                            <th>Trabajador</th>
                            <th>Tipo</th>
                            <th>Fecha Inicio</th>
                            <th>Fecha Fin</th>
                            <th>Descripción</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
            `;
            
            data.data.forEach(dia => {
                const color = coloresTipo[dia.tipo] || '#6c757d';
                const estadoBadge = dia.estado === 'programado' ? '#0d6efd' : dia.estado === 'activo' ? '#198754' : '#6c757d';
                html += `
                    <tr>
                        <td>${dia.trabajador}<br><small style="color:#6c757d;">${dia.cedula}</small></td>
                        <td><span style="background:${color};color:white;padding:3px 8px;border-radius:12px;font-size:0.82rem;font-weight:600;">${dia.tipo}</span></td>
                        <td>${dia.fecha_inicio}</td>
                        <td>${dia.fecha_fin || '-'}</td>
                        <td>${dia.descripcion ? `<span title="${dia.descripcion}" style="cursor:help;">📝 ${dia.descripcion.substring(0,25)}${dia.descripcion.length > 25 ? '…' : ''}</span>` : '-'}</td>
                        <td><span style="background:${estadoBadge};color:white;padding:3px 8px;border-radius:12px;font-size:0.82rem;">${dia.estado}</span></td>
                        <td style="white-space:nowrap;">
                            <button class="btn btn-sm btn-outline" onclick="editarDiaEspecial(${dia.id})" title="Editar"><i class="fas fa-edit"></i></button>
                            <button class="btn btn-sm" style="background:#dc3545;color:white;border:none;padding:4px 8px;border-radius:4px;cursor:pointer;" onclick="eliminarDiaEspecial(${dia.id}, '${(dia.trabajador || '').replace(/'/g, "\\'")}')" title="Eliminar"><i class="fas fa-trash"></i></button>
                        </td>
                    </tr>
                `;
            });
            
            html += '</tbody></table>';
            tabla.innerHTML = html;
        } else {
            tabla.innerHTML = '<p class="info-box">No hay días especiales registrados.</p>';
        }
        
    } catch (error) {
        console.error('Error cargando días especiales:', error);
        tabla.innerHTML = '<p class="alert alert-danger">Error al cargar días especiales</p>';
    }
}

async function cargarTablaTrabajadores() {
    const tabla = document.getElementById('tabla-trabajadores');
    if (!tabla) return;

    // Crear controles de filtro si no existen
    const parent = tabla.parentElement || document.body;
    if (!document.getElementById('trabajadores-filter-container')) {
      const filtroDiv = document.createElement('div');
      filtroDiv.id = 'trabajadores-filter-container';
      filtroDiv.style.cssText = 'margin-bottom:12px; display:flex; gap:10px; align-items:center; flex-wrap:wrap;';
      filtroDiv.innerHTML = `
        <div style="position:relative; flex:1; min-width:200px;">
          <i class="fas fa-search" style="position:absolute; left:10px; top:50%; transform:translateY(-50%); color:#adb5bd; font-size:0.85rem;"></i>
          <input type="text" id="buscar-trabajador" placeholder="Buscar por nombre o cédula..."
            style="width:100%; padding:8px 8px 8px 32px; border:1px solid #ced4da; border-radius:6px; font-size:0.9rem; box-sizing:border-box;">
        </div>
        <div>
          <select id="filter-trabajadores" class="filter-select" style="padding:8px 12px; border:1px solid #ced4da; border-radius:6px; font-size:0.9rem;">
            <option value="all">Todos</option>
            <option value="activos">Activos</option>
            <option value="inactivos">Inactivos</option>
          </select>
        </div>
        <div id="trabajadores-count" style="font-size:0.85rem; color:#6c757d; white-space:nowrap;"></div>
      `;
      parent.insertBefore(filtroDiv, tabla);

      // Filtrado instantáneo al escribir (sin nueva llamada al servidor)
      document.getElementById('buscar-trabajador').addEventListener('input', filtrarTablaTrabajadores);
      document.getElementById('filter-trabajadores').addEventListener('change', filtrarTablaTrabajadores);
    }

    try {
      const response = await fetch(API_BASE + 'trabajadores.php?incluir_inactivos=1');
      const data = await response.json();

      if (data.success && Array.isArray(data.data)) {
        // Guardar datos completos en el DOM para filtrar sin volver al servidor
        tabla.dataset.trabajadores = JSON.stringify(data.data);
        filtrarTablaTrabajadores();
      }
    } catch (error) {
      console.error('Error cargando trabajadores:', error);
      tabla.innerHTML = '<p class="alert alert-danger">Error al cargar trabajadores</p>';
    }
}

function filtrarTablaTrabajadores() {
    const tabla = document.getElementById('tabla-trabajadores');
    if (!tabla || !tabla.dataset.trabajadores) return;

    const todos = JSON.parse(tabla.dataset.trabajadores);
    const filtro = document.getElementById('filter-trabajadores')?.value || 'all';
    const busqueda = (document.getElementById('buscar-trabajador')?.value || '').toLowerCase().trim();

    let lista = todos.slice();

    if (filtro === 'activos') {
        lista = lista.filter(t => t.activo == 1 || t.activo === true);
    } else if (filtro === 'inactivos') {
        lista = lista.filter(t => t.activo == 0 || t.activo === false);
    }

    if (busqueda) {
        lista = lista.filter(t =>
            (t.nombre || '').toLowerCase().includes(busqueda) ||
            (t.cedula || '').toLowerCase().includes(busqueda)
        );
    }

    // Actualizar contador
    const counter = document.getElementById('trabajadores-count');
    if (counter) {
        counter.textContent = busqueda || filtro !== 'all'
            ? `${lista.length} de ${todos.length} trabajadores`
            : `${lista.length} trabajadores`;
    }

    if (lista.length > 0) {
        let html = '<table><thead><tr style="background: linear-gradient(135deg, var(--terminal) 0%, #027433 100%); color: white;">';
        html += '<th>Nombre</th><th>Cédula</th><th>Cargo</th><th>Teléfono</th><th>Estado</th><th>Acciones</th>';
        html += '</tr></thead><tbody>';

        lista.forEach(function(trabajador) {
            const estadoClass = trabajador.activo ? 'status-ok' : 'status-empty';
            const estadoTexto = trabajador.activo ? 'Activo' : 'Inactivo';

            // Resaltar coincidencia de búsqueda en nombre y cédula
            const nombre = busqueda ? resaltarTexto(trabajador.nombre, busqueda) : trabajador.nombre;
            const cedula = busqueda ? resaltarTexto(String(trabajador.cedula), busqueda) : trabajador.cedula;

            html += '<tr>';
            html += '<td>' + nombre + '</td>';
            html += '<td>' + cedula + '</td>';
            html += '<td>' + (trabajador.cargo || '-') + '</td>';
            html += '<td>' + (trabajador.telefono || '-') + '</td>';
            html += '<td><span class="puesto-status ' + estadoClass + '">' + estadoTexto + '</span></td>';
            html += '<td>';
            html += '<button class="btn btn-sm btn-secondary" onclick="editarTrabajador(' + trabajador.id + ')" title="Editar">';
            html += '<i class="fas fa-edit"></i></button> ';

            if (trabajador.activo) {
                html += '<button class="btn btn-sm btn-outline" onclick="desactivarTrabajador(' + trabajador.id + ', \'' + trabajador.nombre.replace(/'/g, "\\'") + '\')" title="Desactivar">';
                html += '<i class="fas fa-ban"></i></button> ';
            } else {
                html += '<button class="btn btn-sm btn-primary" onclick="activarTrabajador(' + trabajador.id + ')" title="Activar">';
                html += '<i class="fas fa-check"></i></button> ';
            }

            html += '<button class="btn btn-sm btn-danger" onclick="eliminarTrabajador(' + trabajador.id + ', \'' + trabajador.nombre.replace(/'/g, "\\'") + '\')" title="Eliminar">';
            html += '<i class="fas fa-trash"></i></button>';
            html += '</td>';
            html += '</tr>';
        });

        html += '</tbody></table>';
        tabla.innerHTML = html;
    } else {
        const msg = busqueda
            ? `<p class="info-box">No se encontraron trabajadores con "<strong>${busqueda}</strong>".</p>`
            : '<p class="info-box">No hay trabajadores registrados.</p>';
        tabla.innerHTML = msg;
    }
}

function resaltarTexto(texto, busqueda) {
    if (!busqueda || !texto) return texto;
    const regex = new RegExp('(' + busqueda.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi');
    return texto.replace(regex, '<mark style="background:#fff3cd;padding:0 2px;border-radius:2px;">$1</mark>');
}

function nuevoTrabajador() {
  const modalOverlay = document.getElementById('modal-overlay');
  const modalTitulo = document.getElementById('modal-titulo');
  const modalBody = document.getElementById('modal-body');

  modalTitulo.textContent = 'Nuevo Trabajador';
  modalBody.innerHTML = `
        <form id="form-nuevo-trabajador">
            <div class="form-group">
                <label for="nombre-trabajador">Nombre Completo <span class="required">*</span></label>
                <input type="text" id="nombre-trabajador" required placeholder="Ej: Juan Pérez García">
            </div>
            
            <div class="form-group">
                <label for="cedula-trabajador">Cédula <span class="required">*</span></label>
                <input type="text" id="cedula-trabajador" required placeholder="Ej: 1234567890" pattern="[0-9]{1,}">
            </div>
            
            <div class="form-group">
                <label for="cargo-trabajador">Cargo</label>
                <input type="text" id="cargo-trabajador" placeholder="Ej: Auxiliar de servicio">
                <small style="color: #7f8c8d; font-size: 0.85rem;">Opcional</small>
            </div>
            
            <div class="form-grid">
                <div class="form-group">
                    <label for="telefono-trabajador">Teléfono</label>
                    <input type="tel" id="telefono-trabajador" placeholder="Ej: 3001234567" maxlength="10">
                </div>
                
                <div class="form-group">
                    <label for="email-trabajador">Email</label>
                    <input type="email" id="email-trabajador" placeholder="Ej: juan@correo.com">
                </div>
            </div>
            
            <div class="form-group">
                <label for="fecha-ingreso-trabajador">Fecha de Ingreso <span class="required">*</span></label>
                <input type="date" id="fecha-ingreso-trabajador" required value="${new Date().toISOString().split('T')[0]}">
            </div>
            
            <div class="info-box" style="margin-top: 1rem;">
                <p><strong>ℹ️ Nota:</strong> Llena los campos marcados con * (obligatorios). Las restricciones médicas se pueden agregar después.</p>
            </div>
            
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Guardar
                </button>
                <button type="button" class="btn btn-outline" onclick="cerrarModal()">
                    <i class="fas fa-times"></i> Cancelar
                </button>
            </div>
        </form>
    `;

  modalOverlay.classList.add('active')     
  
  document.getElementById('form-nuevo-trabajador').addEventListener('submit', async function(e) {
    e.preventDefault();

    const nombre = document.getElementById('nombre-trabajador').value.trim();
    const cedula = document.getElementById('cedula-trabajador').value.trim();
    const cargo = document.getElementById('cargo-trabajador').value.trim() || null;
    const telefono = document.getElementById('telefono-trabajador').value.trim() || null;
    const email = document.getElementById('email-trabajador').value.trim() || null;
    const fecha_ingreso = document.getElementById('fecha-ingreso-trabajador').value;

    // Validación de cédula
    if (!/^[0-9]{1,}$/.test(cedula)) {
      mostrarAlerta('La cédula solo debe contener números', 'danger');
      return;
    }

    const datos = {
      nombre: nombre,
      cedula: cedula,
      cargo: cargo,
      telefono: telefono,
      email: email,
      fecha_ingreso: fecha_ingreso
    };

    try {
      const response = await fetch(`${API_BASE}trabajadores.php`, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(datos)
      });

      const data = await response.json();
      if (data.success) {
        mostrarAlerta('Trabajador creado exitosamente!', 'success');
        cerrarModal();

        const workersSection = document.getElementById('trabajadores');
        if (workersSection && workersSection.classList.contains('active')) {
          cargarTablaTrabajadores();
        }
        cargarEstadisticasDashboard();
      } else {
        mostrarAlerta('Error: ' + (data.message || 'No se pudo crear el trabajador'), 'danger');
      }
    } catch (error) {
      console.error('Error creando trabajador:', error);
      mostrarAlerta('Error al crear trabajador: ' + error.message, 'danger')
    }
  });
}

async function editarTrabajador(id) {
    try {
      const response = await fetch(API_BASE + 'trabajadores.php?id=' + id);
      const data = await response.json();

      if (!data.success || !data.data) {
        mostrarAlerta('Error al cargar datos del trabajador', 'danger');
        return;
      }

      const trabajador = data.data;

      const modalOverlay = document.getElementById('modal-overlay');
      const modalTitulo = document.getElementById('modal-titulo');
      const modalBody = document.getElementById('modal-body');

      modalTitulo.textContent = 'Editar trabajador';
      modalBody.innerHTML = '<form id="form-editar-trabajador">' +
            '<input type="hidden" id="trabajador-id" value="' + trabajador.id + '">' +
            '<div class="form-group">' +
            '<label for="edit-nombre-trabajador">Nombre Completo <span class="required">*</span></label>' +
            '<input type="text" id="edit-nombre-trabajador" required value="' + trabajador.nombre + '">' +
            '</div>' +
            '<div class="form-group">' +
            '<label for="edit-cedula-trabajador">Cédula <span class="required">*</span></label>' +
            '<input type="text" id="edit-cedula-trabajador" required value="' + trabajador.cedula + '">' +
            '</div>' +
            '<div class="form-group">' +
            '<label for="edit-cargo-trabajador">Cargo</label>' +
            '<input type="text" id="edit-cargo-trabajador" value="' + (trabajador.cargo || '') + '">' +
            '</div>' +
            '<div class="form-grid">' +
            '<div class="form-group">' +
            '<label for="edit-telefono-trabajador">Teléfono</label>' +
            '<input type="tel" id="edit-telefono-trabajador" value="' + (trabajador.telefono || '') + '">' +
            '</div>' +
            '<div class="form-group">' +
            '<label for="edit-email-trabajador">Email</label>' +
            '<input type="email" id="edit-email-trabajador" value="' + (trabajador.email || '') + '">' +
            '</div>' +
            '</div>' +
            '<div class="form-actions">' +
            '<button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Guardar Cambios</button>' +
            '<button type="button" class="btn btn-outline" onclick="cerrarModal()"><i class="fas fa-times"></i> Cancelar</button>' +
            '</div>' +
            '</form>';
        
      modalOverlay.classList.add('active');

      document.getElementById('form-editar-trabajador').addEventListener('submit', async function(e) {
        e.preventDefault();

        const id = document.getElementById('trabajador-id').value;
        const datos = {
          nombre: document.getElementById('edit-nombre-trabajador').value,
          cedula: document.getElementById('edit-cedula-trabajador').value,
          cargo: document.getElementById('edit-cargo-trabajador').value || null,
          telefono: document.getElementById('edit-telefono-trabajador').value || null,
          email: document.getElementById('edit-email-trabajador').value || null
        };

        try {
          const response = await fetch(API_BASE + 'trabajadores.php?id=' + id, {
            method: 'PUT',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(datos)
          });

          const result = await response.json();

          if (result.success) {
            mostrarAlerta('Trabajador actualizado exitosamente', 'success');
            cerrarModal();
            cargarTablaTrabajadores();
          } else {
            mostrarAlerta('Error: ' + result.message, 'danger');
          }
        } catch (error) {
          console.error('Error actualizando trabajador:', error);
          mostrarAlerta('Error al actualizar trabajador', 'danger');
        }
      });
    } catch (error) {
      console.error('Error:', error);
      mostrarAlerta('Error al cargar trabajador', 'danger');
    }
}

async function desactivarTrabajador(id, nombre) {
  if (!confirm('¿Esta seguro de desactivar a ' + nombre + '?\n\nEl trabajador no podra ser asignado a nuevos turnos.')) {
    return;
  }

  try {
    const response = await fetch(API_BASE + 'trabajadores.php?id=' + id + '&accion=desactivar', {
      method: 'PUT'
    });

    const data = await response.json();

    if (data.success) {
      mostrarAlerta('Trabajador desactivado exitosamente', 'success');
      cargarTablaTrabajadores();
    } else {
      mostrarAlerta('Error: ' + data.message, 'danger');
    }
  } catch (error) {
      console.error('Error:', error);
      mostrarAlerta('Error al desactivar trabajador', 'danger');
  }
}

async function activarTrabajador(id) {
  try {
    const response = await fetch(API_BASE + 'trabajadores.php?id=' + id + '&accion=activar', {
      method: 'PUT'
    });

    const data = await response.json();

    if (data.success) {
      mostrarAlerta('Trabajador activado exitosamente', 'success');
      cargarTablaTrabajadores();
    } else {
      mostrarAlerta('Error: ' + data.message, 'danger');
    }
  } catch (error) {
      console.error('Error:', error);
      mostrarAlerta('Error al activar trabajador', 'danger');
  }
}

async function eliminarTrabajador(id, nombre) {
  if (!confirm('¿ELIMINAR PERMANENTEMENTE a ' + nombre + '?\n\nEsta accion NO se puede deshacer.\n\nSi solo desea que no aparezca en las asignaciones, use "Desactivar" en su lugar.')) {
    return;
  }

  if (!confirm('Confirme nuevamente: ¿Eliminar definitivamente a ' + nombre + '?')) {
    return;
  }

  try {
    const response = await fetch(API_BASE + 'trabajadores.php?id=' + id, {
      method: 'DELETE'
    });

    const data = await response.json();

    if (data.success) {
      mostrarAlerta('Trabajador eliminado', 'success');
      cargarTablaTrabajadores();
    } else {
        mostrarAlerta('Trabajador eliminado', 'success');
    }
  } catch (error) {
      console.error('Error:', error)
      mostrarAlerta('Error al eliminar trabajador', 'danger');
  }
}

function nuevaIncapacidad() {
  const modalOverlay = document.getElementById('modal-overlay');
  const modalTitulo = document.getElementById('modal-titulo');
  const modalBody = document.getElementById('modal-body');

  modalTitulo.textContent = 'Registrar Incapacidad';
  fetch(API_BASE + 'trabajadores.php')
      .then(res => res.json())
      .then(data => {
          let trabajadoresOptions = '<option value="">Seleccione...</option>';
          if (data.success && data.data) {
            data.data.forEach(function(t) {
              if (t.activo) {
                trabajadoresOptions += '<option value="' + t.id + '">' + t.nombre + ' - ' + t.cedula + '</option>'; 
              }
            });
          }

          const hoy = new Date().toISOString().split('T')[0];

          modalBody.innerHTML = `
            <form id="form-nueva-incapacidad">
              <div class="form-group">
                <label for="trabajador-incapacidad">Trabajador <span class="required">*</span></label>
                <select id="trabajador-incapacidad" required>${trabajadoresOptions}</select>
              </div>

              <div class="form-group">
                <label for="tipo-incapacidad">Tipo <span class="required">*</span></label>
                <select id="tipo-incapacidad" required>
                  <option value="">Seleccione...</option>
                  <option value="EG">Enfermedad General</option>
                  <option value="AT">Accidente de Trabajo</option>
                  <option value="EL">Enfermedad Laboral</option>
                  <option value="LM">Licencia de Maternidad</option>
                  <option value="LP">Licencia de Paternidad</option>
                  <option value="CIR">Cirugía</option>
                </select>
              </div>

              <div class="form-grid">
                <div class="form-group">
                  <label for="fecha-inicio-incapacidad">Fecha Inicio <span class="required">*</span></label>
                  <input type="date" id="fecha-inicio-incapacidad" required min="${hoy}">
                </div>
                <div class="form-group">
                  <label for="fecha-fin-incapacidad">Fecha Fin <span class="required">*</span></label>
                  <input type="date" id="fecha-fin-incapacidad" required min="${hoy}">
                </div>
              </div>

              <div id="dias-incapacidad" class="info-box" style="display:none;">Días de incapacidad: <strong id="dias-count">0</strong></div>

              <div class="form-group">
                <label for="descripcion-incapacidad">Descripción</label>
                <textarea id="descripcion-incapacidad" rows="2" placeholder="Ej: Cirugía de rodilla"></textarea>
              </div>

              <div class="form-group">
                <label for="eps-incapacidad">EPS</label>
                <input type="text" id="eps-incapacidad" placeholder="Ej: Sanitas">
              </div>

              <div class="form-group">
                <label for="documento-incapacidad">Documento de soporte (opcional)</label>
                <input type="file" id="documento-incapacidad" accept=".pdf,.jpg,.jpeg,.png,.gif">
                <small style="color:#6c757d; display:block; margin-top:.25rem;">Formatos: PDF, JPG, PNG, GIF (máx 10MB)</small>
              </div>

              <hr style="margin: 1.5rem 0; border: none; border-top: 2px solid #e9ecef;">
              <h4 style="margin-bottom: 1rem; color: #495057;">📋 Restricciones Posteriores</h4>
              <div class="form-group">
                <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                  <input type="checkbox" id="genera-restriccion" onchange="toggleRestriccionFields()">
                  <span>Esta incapacidad genera una restricción médica posterior</span>
                </label>
              </div>

              <div id="campos-restriccion" style="display: none; padding: 1rem; background: #f8f9fa; border-radius: 6px; margin-top: 1rem;">
                <div class="form-group">
                  <label for="tipo-restriccion-generada">Tipo de Restricción <span class="required">*</span></label>
                  <select id="tipo-restriccion-generada">
                    <option value="">Seleccione...</option>
                    <option value="no_fuerza_fisica">No puede hacer fuerza física</option>
                    <option value="movilidad_limitada">Movilidad limitada</option>
                    <option value="no_turno_noche">No puede trabajar turno nocturno</option>
                    <option value="problema_visual">Problema visual</option>
                  </select>
                </div>
                <div class="form-group">
                  <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                    <input type="checkbox" id="restriccion-permanente" onchange="toggleFechaFinRestriccion()">
                    <span>Restricción permanente (indefinida)</span>
                  </label>
                </div>
                <div class="form-group" id="campo-fecha-fin-restriccion">
                  <label for="fecha-fin-restriccion">Fecha Fin de Restricción <span class="required">*</span></label>
                  <input type="date" id="fecha-fin-restriccion" min="${hoy}">
                  <small style="color: #6c757d;">La restricción inicia cuando termina la incapacidad</small>
                </div>
              </div>

              <div class="form-actions" style="margin-top: 1.5rem;">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Registrar Incapacidad</button>
                <button type="button" class="btn btn-outline" onclick="cerrarModal()"><i class="fas fa-times"></i> Cancelar</button>
              </div>
            </form>
          `;

          modalOverlay.classList.add('active');

          // Eventos para calcular días
          const fechaInicioEl = document.getElementById('fecha-inicio-incapacidad');
          const fechaFinEl = document.getElementById('fecha-fin-incapacidad');
          const diasBox = document.getElementById('dias-incapacidad');
          const diasCount = document.getElementById('dias-count');

          function actualizarDias() {
            const fi = fechaInicioEl.value;
            const ff = fechaFinEl.value;
            if (fi && ff) {
              const d1 = new Date(fi);
              const d2 = new Date(ff);
              if (d2 < d1) {
                diasBox.style.display = 'block';
                diasCount.textContent = '0';
                return;
              }
              const diff = Math.ceil((d2 - d1) / (1000*60*60*24)) + 1;
              diasBox.style.display = 'block';
              diasCount.textContent = diff;
            } else {
              diasBox.style.display = 'none';
            }
          }

          fechaInicioEl.addEventListener('change', actualizarDias);
          fechaFinEl.addEventListener('change', actualizarDias);

          document.getElementById('form-nueva-incapacidad').addEventListener('submit', guardarIncapacidad);
      });
}

function toggleRestriccionFields() {
    const checked = document.getElementById('genera-restriccion').checked;
    const campos = document.getElementById('campos-restriccion');
    campos.style.display = checked ? 'block' : 'none';

    if (checked) {
        document.getElementById('tipo-restriccion-generada').required = true;
    } else {
        document.getElementById('tipo-restriccion-generada').required = false;
    }
}

function toggleFechaFinRestriccion() {
  const permanente = document.getElementById('restriccion-permanente').checked;
  const campoFecha = document.getElementById('campo-fecha-fin-restriccion');
  const inputFecha = document.getElementById('fecha-fin-restriccion');

  campoFecha.style.display = permanente ? 'none' : 'block';
  inputFecha.required = !permanente;
}

async function guardarIncapacidad(e) {
    e.preventDefault();

    const generaRestriccion = document.getElementById('genera-restriccion').checked;
    const formData = new FormData();

    formData.append('trabajador_id', document.getElementById('trabajador-incapacidad').value);
    formData.append('tipo', document.getElementById('tipo-incapacidad').value);
    formData.append('fecha_inicio', document.getElementById('fecha-inicio-incapacidad').value);
    formData.append('fecha_fin', document.getElementById('fecha-fin-incapacidad').value);
    formData.append('descripcion', document.getElementById('descripcion-incapacidad').value || '');
    formData.append('eps', document.getElementById('eps-incapacidad').value || '');
    formData.append('genera_restriccion', generaRestriccion ? '1' : '0');

    if (generaRestriccion) {
      formData.append('tipo_restriccion_generada', document.getElementById('tipo-restriccion-generada').value);
      formData.append('restriccion_permanente', document.getElementById('restriccion-permanente').checked ? '1' : '0');

      if (!document.getElementById('restriccion-permanente').checked) {
        formData.append('fecha_fin_restriccion', document.getElementById('fecha-fin-restriccion').value);
      }
    }

    // Agregar archivo si existe
    const fileInput = document.getElementById('documento-incapacidad');
    if (fileInput && fileInput.files.length > 0) {
        formData.append('documento', fileInput.files[0]);
    }

    try {
        const response = await fetch(API_BASE + 'incapacidades_upload.php', {
          method: 'POST',
          body: formData
        });

        const result = await response.json();

        if (result.success) {
            mostrarAlerta('✅ ' + result.message, 'success');
            cerrarModal();
            cargarTablaIncapacidades();
        } else {
            mostrarAlerta('Error: ' + result.message, 'danger');
        }
    } catch (error) {
        console.error('Error:', error);
        mostrarAlerta('Error al registrar incapacidad', 'danger');
    }
}

// helper: leer archivo en base64
function readFileAsBase64(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(reader.result);
    reader.onerror = reject;
    reader.readAsDataURL(file);
  });
}

function solicitarCambio() {
    mostrarAlerta('Funcionalidad en desarrollo...', 'info');
  }
  
async function nuevoDiaEspecial() {
    let trabajadoresOptions = '<option value="">Seleccionar trabajador...</option>';
    try {
        const res = await fetch(API_BASE + 'trabajadores.php');
        const data = await res.json();
        if (data.success) {
            data.data.filter(t => t.activo).forEach(t => {
                trabajadoresOptions += `<option value="${t.id}">${t.nombre} - ${t.cedula}</option>`;
            });
        }
    } catch(e) { console.error('Error cargando trabajadores:', e); }

    const hoy = new Date().toISOString().split('T')[0];

    document.getElementById('modal-titulo').textContent = 'Nuevo Día Especial';
    document.getElementById('modal-body').innerHTML = `
        <form id="form-dia-especial" onsubmit="event.preventDefault(); guardarDiaEspecial();">
            <div style="display:grid; gap:14px;">
                <div>
                    <label style="display:block; margin-bottom:4px; font-weight:600;">Trabajador *</label>
                    <select id="de-trabajador" required style="width:100%; padding:8px; border:1px solid #ced4da; border-radius:4px;">
                        ${trabajadoresOptions}
                    </select>
                </div>
                <div>
                    <label style="display:block; margin-bottom:4px; font-weight:600;">Tipo *</label>
                    <select id="de-tipo" required style="width:100%; padding:8px; border:1px solid #ced4da; border-radius:4px;">
                        <option value="">Seleccionar tipo...</option>
                        <optgroup label="Descansos">
                            <option value="LC">LC - Libre Cumpleaños</option>
                            <option value="L">L - Libre Semana</option>
                            <option value="L8">L8 - Ley 2101 (día completo)</option>
                        </optgroup>
                        <optgroup label="Ausencias">
                            <option value="VAC">VAC - Vacaciones</option>
                            <option value="SUS">SUS - Suspensión</option>
                        </optgroup>
                        <optgroup label="Disponibilidad">
                            <option value="ADMM">ADMM - Disponible Mañana</option>
                            <option value="ADMT">ADMT - Disponible Tarde</option>
                            <option value="ADM">ADM - Disponible Todo el Día</option>
                        </optgroup>
                    </select>
                </div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                    <div>
                        <label style="display:block; margin-bottom:4px; font-weight:600;">Fecha Inicio *</label>
                        <input type="date" id="de-fecha-inicio" required value="${hoy}" style="width:100%; padding:8px; border:1px solid #ced4da; border-radius:4px;">
                    </div>
                    <div>
                        <label style="display:block; margin-bottom:4px; font-weight:600;">Fecha Fin</label>
                        <input type="date" id="de-fecha-fin" style="width:100%; padding:8px; border:1px solid #ced4da; border-radius:4px;">
                    </div>
                </div>
                <div>
                    <label style="display:block; margin-bottom:4px; font-weight:600;">Descripción / Observaciones</label>
                    <textarea id="de-descripcion" rows="2" style="width:100%; padding:8px; border:1px solid #ced4da; border-radius:4px; resize:vertical;" placeholder="Opcional..."></textarea>
                </div>
                <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:6px;">
                    <button type="button" class="btn btn-outline" onclick="cerrarModal()">Cancelar</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Guardar</button>
                </div>
            </div>
        </form>
    `;
    document.getElementById('modal-overlay').classList.add('active');
}

async function guardarDiaEspecial() {
    const datos = {
        trabajador_id: document.getElementById('de-trabajador').value,
        tipo: document.getElementById('de-tipo').value,
        fecha_inicio: document.getElementById('de-fecha-inicio').value,
        fecha_fin: document.getElementById('de-fecha-fin').value || null,
        descripcion: document.getElementById('de-descripcion').value || null
    };
    if (!datos.trabajador_id || !datos.tipo || !datos.fecha_inicio) {
        mostrarAlerta('Por favor completa los campos obligatorios', 'warning');
        return;
    }
    try {
        const response = await fetch(API_BASE + 'dias_especiales.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(datos)
        });
        const result = await response.json();
        if (result.success) {
            mostrarAlerta('Día especial registrado correctamente', 'success');
            cerrarModal();
            cargarTablaDiasEspeciales();
        } else {
            mostrarAlerta('Error: ' + result.message, 'danger');
        }
    } catch(e) {
        mostrarAlerta('Error de conexión al guardar', 'danger');
        console.error(e);
    }
}

async function editarDiaEspecial(id) {
    try {
        const res = await fetch(`${API_BASE}dias_especiales.php`);
        const data = await res.json();
        const dia = data.data?.find(d => d.id == id);
        if (!dia) { mostrarAlerta('No se encontró el registro', 'danger'); return; }

        let trabajadoresOptions = '';
        const resT = await fetch(API_BASE + 'trabajadores.php?incluir_inactivos=1');
        const dataT = await resT.json();
        if (dataT.success) {
            dataT.data.forEach(t => {
                const sel = t.id == dia.trabajador_id ? 'selected' : '';
                trabajadoresOptions += `<option value="${t.id}" ${sel}>${t.nombre} - ${t.cedula}</option>`;
            });
        }

        const tiposOpts = ['LC','L','L8','VAC','SUS','ADMM','ADMT','ADM'].map(t =>
            `<option value="${t}" ${dia.tipo === t ? 'selected' : ''}>${t}</option>`
        ).join('');

        const estadosOpts = ['programado','activo','finalizado','cancelado'].map(e =>
            `<option value="${e}" ${dia.estado === e ? 'selected' : ''}>${e}</option>`
        ).join('');

        document.getElementById('modal-titulo').textContent = 'Editar Día Especial';
        document.getElementById('modal-body').innerHTML = `
            <form onsubmit="event.preventDefault(); actualizarDiaEspecial(${id});">
                <div style="display:grid; gap:14px;">
                    <div>
                        <label style="display:block; margin-bottom:4px; font-weight:600;">Trabajador</label>
                        <select id="de-edit-trabajador" style="width:100%; padding:8px; border:1px solid #ced4da; border-radius:4px;">
                            ${trabajadoresOptions}
                        </select>
                    </div>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                        <div>
                            <label style="display:block; margin-bottom:4px; font-weight:600;">Tipo</label>
                            <select id="de-edit-tipo" style="width:100%; padding:8px; border:1px solid #ced4da; border-radius:4px;">${tiposOpts}</select>
                        </div>
                        <div>
                            <label style="display:block; margin-bottom:4px; font-weight:600;">Estado</label>
                            <select id="de-edit-estado" style="width:100%; padding:8px; border:1px solid #ced4da; border-radius:4px;">${estadosOpts}</select>
                        </div>
                    </div>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                        <div>
                            <label style="display:block; margin-bottom:4px; font-weight:600;">Fecha Inicio</label>
                            <input type="date" id="de-edit-inicio" value="${dia.fecha_inicio || ''}" style="width:100%; padding:8px; border:1px solid #ced4da; border-radius:4px;">
                        </div>
                        <div>
                            <label style="display:block; margin-bottom:4px; font-weight:600;">Fecha Fin</label>
                            <input type="date" id="de-edit-fin" value="${dia.fecha_fin || ''}" style="width:100%; padding:8px; border:1px solid #ced4da; border-radius:4px;">
                        </div>
                    </div>
                    <div>
                        <label style="display:block; margin-bottom:4px; font-weight:600;">Descripción</label>
                        <textarea id="de-edit-desc" rows="2" style="width:100%; padding:8px; border:1px solid #ced4da; border-radius:4px;">${dia.descripcion || ''}</textarea>
                    </div>
                    <div style="display:flex; justify-content:flex-end; gap:10px;">
                        <button type="button" class="btn btn-outline" onclick="cerrarModal()">Cancelar</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Actualizar</button>
                    </div>
                </div>
            </form>
        `;
        document.getElementById('modal-overlay').classList.add('active');
    } catch(e) {
        mostrarAlerta('Error al cargar el registro', 'danger');
        console.error(e);
    }
}

async function actualizarDiaEspecial(id) {
    const datos = {
        trabajador_id: document.getElementById('de-edit-trabajador').value,
        tipo: document.getElementById('de-edit-tipo').value,
        fecha_inicio: document.getElementById('de-edit-inicio').value,
        fecha_fin: document.getElementById('de-edit-fin').value || null,
        descripcion: document.getElementById('de-edit-desc').value || null,
        estado: document.getElementById('de-edit-estado').value
    };
    try {
        const response = await fetch(`${API_BASE}dias_especiales.php?id=${id}`, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(datos)
        });
        const result = await response.json();
        if (result.success) {
            mostrarAlerta('Registro actualizado', 'success');
            cerrarModal();
            cargarTablaDiasEspeciales();
        } else {
            mostrarAlerta('Error: ' + result.message, 'danger');
        }
    } catch(e) {
        mostrarAlerta('Error de conexión', 'danger');
    }
}

async function eliminarDiaEspecial(id, nombre) {
    if (!confirm(`¿Eliminar el día especial de ${nombre}? Esta acción no se puede deshacer.`)) return;
    try {
        const response = await fetch(`${API_BASE}dias_especiales.php?id=${id}`, { method: 'DELETE' });
        const result = await response.json();
        if (result.success) {
            mostrarAlerta('Registro eliminado', 'success');
            cargarTablaDiasEspeciales();
        } else {
            mostrarAlerta('Error: ' + result.message, 'danger');
        }
    } catch(e) {
        mostrarAlerta('Error de conexión', 'danger');
    }
}

function cerrarModal() {
  document.getElementById('modal-overlay').classList.remove('active');
}

async function exportarCalendarioExcel() {
    const mes = mesActual + 1;
    const anio = anioActual;

    try {
      const response = await fetch(`${API_BASE}turnos.php?action=calendario&mes=${mes}&anio=${anio}`);
      const data = await response.json();

      if (!data.success || !data.data || data.data.length === 0) {
        mostrarAlerta('No hay turnos para exportar este mes', 'warning');
        return;
      }

      const turnos = data.data;

      let csv = '\uFEFF'; // BOM para Excel UTF-8
        csv += 'Fecha,Trabajador,Cédula,Puesto,Área,Turno,Horario,Estado\n';
        
        turnos.forEach(turno => {
            csv += `${turno.fecha},`;
            csv += `"${turno.trabajador}",`;
            csv += `${turno.cedula},`;
            csv += `"${turno.puesto_codigo} - ${turno.puesto_nombre}",`;
            csv += `"${turno.area}",`;
            csv += `"${turno.turno_nombre}",`;
            csv += `"${turno.hora_inicio} - ${turno.hora_fin}",`;
            csv += `${turno.estado}\n`;
        });

      const blob = new Blob([csv], {type: 'text/csv;charset=utf-8;'});
      const link = document.createElement('a');
      const url = URL.createObjectURL(blob);

      const mesNombre = new Date(anio, mes -1).toLocaleDateString('es-CO', {month: 'long'});
      link.setAttribute('href', url);
      link.setAttribute('download', `Turnos_${mesNombre}_${anio}.csv`);
      link.style.visibility = 'hidden';

      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);

      mostrarAlerta('Calendario exportado a Excel exitosamente', 'success');
    } catch (error) {
      console.error('Error exportando a Excel:', error);
      mostrarAlerta('Error al exportar a Excel', 'danger');
    }
}
function mostrarAlerta(mensaje, tipo = 'info') {
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${tipo}`;
    alertDiv.innerHTML = mensaje;
    alertDiv.style.position = 'fixed';
    alertDiv.style.top = '20px';
    alertDiv.style.right = '20px';
    alertDiv.style.zIndex = '9999';
    alertDiv.style.minWidth = '300px';
    alertDiv.style.maxWidth = '500px';
    alertDiv.style.boxShadow = '0 4px 12px rgba(0,0,0,0.15)';
    
    document.body.appendChild(alertDiv);
    
    setTimeout(() => {
        alertDiv.style.opacity = '0';
        alertDiv.style.transition = 'opacity 0.3s';
        setTimeout(() => alertDiv.remove(), 300);
    }, 5000);
}

async function editarAsignacion(turnoId, puestoId, fecha, numeroTurno, trabajadorActualId) {
  if (!turnoId || !puestoId) {
    mostrarAlerta('No se puede editar: datos incompletos', 'warning');
    return;
  }

  const modalOverlay = document.getElementById('modal-overlay');
  const modalTitulo = document.getElementById('modal-titulo');
  const modalBody = document.getElementById('modal-body');

  modalTitulo.textContent = 'Editar asignación';
  modalBody.innerHTML = '<p>Cargando trabajadores disponibles...</p>';
  modalOverlay.classList.add('active');

  try {
    const url = `${API_BASE}trabajadores.php?disponibles=1&puesto_id=${puestoId}&turno_id=${numeroTurno}&fecha=${fecha}`;
    const res = await fetch(url);
    const data = await res.json();

    if (!data.success) {
      modalBody.innerHTML = '<p class="alert alert-danger">Error cargando trabajadores disponibles</p>';
      return;
    }

    let opciones = '<option value="">Seleccione nuevo trabajador...</option>';
    data.data.forEach(t => {
      opciones += `<option value="${t.id}" ${t.id == trabajadorActualId ? 'selected' : ''}>${t.nombre} - ${t.cedula}</option>`;
    });

    modalBody.innerHTML = `
      <form id="form-editar-asignacion">
        <div class="form-group">
          <label>Fecha</label>
          <input type="text" disabled value="${fecha}">
        </div>
        <div class="form-group">
          <label>Turno</label>
          <input type="text" disabled value="${numeroTurno}">
        </div>
        <div class="form-group">
          <label for="nuevo-trabajador-select">Nuevo Trabajador</label>
          <select id="nuevo-trabajador-select" required>${opciones}</select>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">Guardar</button>
          <button type="button" class="btn btn-outline" onclick="cerrarModal()">Cancelar</button>
        </div>
      </form>
    `;

    document.getElementById('form-editar-asignacion').addEventListener('submit', async function(e) {
      e.preventDefault();
      const nuevoId = document.getElementById('nuevo-trabajador-select').value;
      if (!nuevoId) { mostrarAlerta('Seleccione un trabajador', 'warning'); return; }

      try {
        const response = await fetch(`${API_BASE}turnos.php?id=${turnoId}`, {
          method: 'PUT',
          headers: {'Content-Type':'application/json'},
          body: JSON.stringify({ trabajador_id: nuevoId, usuario_id: 1 })
        });

        const result = await response.json();
        console.log('Resultado reasignación:', result);
        if (result.success) {
          mostrarAlerta('Asignación actualizada', 'success');
          cerrarModal();
          cargarVistaDiaria();
        } else {
          const errores = result.errores && result.errores.length ? '<ul>' + result.errores.map(e => '<li>' + e + '</li>').join('') + '</ul>' : '';
          const mensaje = result.message ? result.message : 'No se pudo actualizar';
          mostrarAlerta(mensaje + errores, 'danger');
        }
      } catch (err) {
        console.error('Error actualizando asignacion:', err);
        mostrarAlerta('Error al actualizar asignacion', 'danger');
      }
    });

  } catch (err) {
    console.error('Error cargando disponibles:', err);
    modalBody.innerHTML = '<p class="alert alert-danger">Error al cargar datos</p>';
  }
}

// ===== FUNCIONES PARA INCAPACIDADES =====

async function editarIncapacidad(id) {
  const modalOverlay = document.getElementById('modal-overlay');
  const modalTitulo = document.getElementById('modal-titulo');
  const modalBody = document.getElementById('modal-body');

  try {
    // Cargar datos de la incapacidad
    const response = await fetch(API_BASE + 'incapacidades.php?id=' + id);
    const data = await response.json();

    if (!data.success || !data.data) {
      mostrarAlerta('No se pudo cargar la incapacidad', 'danger');
      return;
    }

    const inc = data.data;

    modalTitulo.textContent = 'Editar Incapacidad';

    const hoy = new Date().toISOString().split('T')[0];

    // Normalizar tipo a código corto, cubriendo cualquier formato que venga de la BD
    const tipoNormalMap = {
        'EG': 'EG', 'AT': 'AT', 'EL': 'EL', 'LM': 'LM', 'LP': 'LP', 'CIR': 'CIR',
        'Enfermedad General': 'EG', 'enfermedad general': 'EG',
        'Accidente de Trabajo': 'AT', 'Accidente Trabajo': 'AT', 'accidente de trabajo': 'AT',
        'Enfermedad Laboral': 'EL', 'enfermedad laboral': 'EL',
        'Licencia de Maternidad': 'LM', 'licencia de maternidad': 'LM',
        'Licencia de Paternidad': 'LP', 'licencia de paternidad': 'LP',
        'Cirugía': 'CIR', 'Cirugia': 'CIR', 'cirugia': 'CIR', 'cirug\u00eda': 'CIR'
    };

    // Decodificar entidades HTML si las hubiera y luego mapear
    const tipoRaw = (inc.tipo || '')
        .replace(/&iacute;/g, 'í').replace(/&aacute;/g, 'á')
        .replace(/&eacute;/g, 'é').replace(/&oacute;/g, 'ó')
        .replace(/&uacute;/g, 'ú').replace(/&ntilde;/g, 'ñ')
        .replace(/&#(\d+);/g, (_, n) => String.fromCharCode(n));
    const tipoCodigo = tipoNormalMap[tipoRaw] || tipoNormalMap[inc.tipo] || tipoRaw || '';

    modalBody.innerHTML = `
      <form id="form-editar-incapacidad">
        <input type="hidden" id="incapacidad-id" value="${inc.id}">

        <div class="form-group">
          <label for="editar-trabajador">Trabajador</label>
          <input type="text" disabled value="${inc.trabajador} (${inc.cedula})">
        </div>

        <div class="form-group">
          <label for="editar-tipo-incapacidad">Tipo <span class="required">*</span></label>
          <select id="editar-tipo-incapacidad" required>
            <option value="">Seleccione...</option>
            <option value="EG" ${tipoCodigo === 'EG' ? 'selected' : ''}>Enfermedad General</option>
            <option value="AT" ${tipoCodigo === 'AT' ? 'selected' : ''}>Accidente de Trabajo</option>
            <option value="EL" ${tipoCodigo === 'EL' ? 'selected' : ''}>Enfermedad Laboral</option>
            <option value="LM" ${tipoCodigo === 'LM' ? 'selected' : ''}>Licencia de Maternidad</option>
            <option value="LP" ${tipoCodigo === 'LP' ? 'selected' : ''}>Licencia de Paternidad</option>
            <option value="CIR" ${tipoCodigo === 'CIR' ? 'selected' : ''}>Cirugía</option>
          </select>
        </div>

        <div class="form-grid">
          <div class="form-group">
            <label for="editar-fecha-inicio-incapacidad">Fecha Inicio <span class="required">*</span></label>
            <input type="date" id="editar-fecha-inicio-incapacidad" required value="${inc.fecha_inicio}">
          </div>
          <div class="form-group">
            <label for="editar-fecha-fin-incapacidad">Fecha Fin <span class="required">*</span></label>
            <input type="date" id="editar-fecha-fin-incapacidad" required value="${inc.fecha_fin}">
          </div>
        </div>

        <div id="editar-dias-incapacidad" class="info-box" style="display:none;">Días de incapacidad: <strong id="editar-dias-count">0</strong></div>

        <div class="form-group">
          <label for="editar-descripcion-incapacidad">Descripción</label>
          <textarea id="editar-descripcion-incapacidad" rows="2">${inc.descripcion || ''}</textarea>
        </div>

        <div class="form-group">
          <label for="editar-eps-incapacidad">EPS</label>
          <input type="text" id="editar-eps-incapacidad" value="${inc.eps || ''}">
        </div>

        <div class="form-group">
          <label for="editar-estado-incapacidad">Estado</label>
          <select id="editar-estado-incapacidad" required>
            <option value="activa" ${inc.estado === 'activa' ? 'selected' : ''}>Activa</option>
            <option value="finalizada" ${inc.estado === 'finalizada' ? 'selected' : ''}>Finalizada</option>
          </select>
        </div>

        <div class="form-actions" style="margin-top: 1.5rem;">
          <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Guardar Cambios</button>
          <button type="button" class="btn btn-outline" onclick="cerrarModal()"><i class="fas fa-times"></i> Cancelar</button>
        </div>
      </form>
    `;

    modalOverlay.classList.add('active');

    // Eventos para calcular días
    const fechaInicioEl = document.getElementById('editar-fecha-inicio-incapacidad');
    const fechaFinEl = document.getElementById('editar-fecha-fin-incapacidad');
    const diasBox = document.getElementById('editar-dias-incapacidad');
    const diasCount = document.getElementById('editar-dias-count');

    function actualizarDias() {
      const fi = fechaInicioEl.value;
      const ff = fechaFinEl.value;
      if (fi && ff) {
        const d1 = new Date(fi);
        const d2 = new Date(ff);
        if (d2 < d1) {
          diasBox.style.display = 'block';
          diasCount.textContent = '0';
          return;
        }
        const diff = Math.ceil((d2 - d1) / (1000*60*60*24)) + 1;
        diasBox.style.display = 'block';
        diasCount.textContent = diff;
      } else {
        diasBox.style.display = 'none';
      }
    }

    fechaInicioEl.addEventListener('change', actualizarDias);
    fechaFinEl.addEventListener('change', actualizarDias);

    // Trigger para mostrar días iniciales
    actualizarDias();

    document.getElementById('form-editar-incapacidad').addEventListener('submit', guardarEdicionIncapacidad);

  } catch (error) {
    console.error('Error:', error);
    mostrarAlerta('Error al cargar la incapacidad', 'danger');
  }
}

async function guardarEdicionIncapacidad(e) {
  e.preventDefault();

  const id = document.getElementById('incapacidad-id').value;
  const datos = {
    id: id,
    tipo: document.getElementById('editar-tipo-incapacidad').value,
    fecha_inicio: document.getElementById('editar-fecha-inicio-incapacidad').value,
    fecha_fin: document.getElementById('editar-fecha-fin-incapacidad').value,
    descripcion: document.getElementById('editar-descripcion-incapacidad').value || '',
    eps: document.getElementById('editar-eps-incapacidad').value || '',
    estado: document.getElementById('editar-estado-incapacidad').value
  };

  try {
    const response = await fetch(API_BASE + 'incapacidades.php', {
      method: 'PUT',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify(datos)
    });

    const result = await response.json();

    if (result.success) {
      mostrarAlerta('✅ Incapacidad actualizada correctamente', 'success');
      cerrarModal();
      cargarTablaIncapacidades();
    } else {
      mostrarAlerta('Error: ' + result.message, 'danger');
    }
  } catch (error) {
    console.error('Error:', error);
    mostrarAlerta('Error al guardar cambios', 'danger');
  }
}

async function eliminarIncapacidad(id) {
  if (!confirm('¿Estás seguro de que deseas eliminar esta incapacidad?')) {
    return;
  }

  try {
    const response = await fetch(API_BASE + 'incapacidades.php', {
      method: 'DELETE',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({id: id})
    });

    const result = await response.json();

    if (result.success) {
      mostrarAlerta('✅ Incapacidad eliminada correctamente', 'success');
      cargarTablaIncapacidades();
    } else {
      mostrarAlerta('Error: ' + result.message, 'danger');
    }
  } catch (error) {
    console.error('Error:', error);
    mostrarAlerta('Error al eliminar incapacidad', 'danger');
  }
}