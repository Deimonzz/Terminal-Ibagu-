//Variables Globales

const API_BASE = '/Terminal-Ibagu-/backend/api/';

function cerrarModal() {
    const overlay = document.getElementById('modal-overlay');
    if (overlay) {
        overlay.classList.remove('active');
        overlay.style.display = '';
    }
}
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

    const formEspecial = document.getElementById('form-turno-especial');
    if (formEspecial) {
        formEspecial.addEventListener('submit', guardarTurnoEspecial);
    }

    // Fecha hoy por defecto en el form especial
    const fechaEspecial = document.getElementById('fecha-especial');
    if (fechaEspecial) {
        fechaEspecial.value = new Date().toISOString().split('T')[0];
        fechaEspecial.addEventListener('change', onCambioTipoEspecial);
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
        turnoSelect.addEventListener('change', cargarTrabajadoresDisponibles);
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
    case 'asignar':
      cambiarTabAsignar('normal');
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
    16: [ // G - Equipajes (solo 2 turnos, sin L4)
        {numero: 1, nombre: 'Turno 1 - Mañana', inicio: '06:00', fin: '14:00'},
        {numero: 2, nombre: 'Turno 2 - Tarde',  inicio: '14:00', fin: '22:00'}
    ],
    9: [ // F14 - Fox 14 (horarios especiales, sin L4)
        {numero: 1, nombre: 'Turno 1 - Mañana', inicio: '04:00', fin: '12:00'},
        {numero: 2, nombre: 'Turno 2 - Tarde',  inicio: '12:00', fin: '20:00'},
        {numero: 3, nombre: 'Turno 3 - Noche',  inicio: '20:00', fin: '04:00'}
    ],
    6: [ // F5 - Fox 5 (turnos normales + L4 14:00-18:00)
        {numero: 1, nombre: 'Turno 1 - Mañana', inicio: '06:00', fin: '14:00'},
        {numero: 2, nombre: 'Turno 2 - Tarde',  inicio: '14:00', fin: '22:00'},
        {numero: 3, nombre: 'Turno 3 - Noche',  inicio: '22:00', fin: '06:00'},
        {numero: 4, nombre: 'L4 - Tarde (4h)',   inicio: '14:00', fin: '18:00', esL4: true}
    ],
    10: [ // F15 - Fox 15 (turnos normales + L4 14:00-18:00)
        {numero: 1, nombre: 'Turno 1 - Mañana', inicio: '06:00', fin: '14:00'},
        {numero: 2, nombre: 'Turno 2 - Tarde',  inicio: '14:00', fin: '22:00'},
        {numero: 3, nombre: 'Turno 3 - Noche',  inicio: '22:00', fin: '06:00'},
        {numero: 4, nombre: 'L4 - Tarde (4h)',   inicio: '14:00', fin: '18:00', esL4: true}
    ],
    2: [ // D2 - Delta 2 (turnos normales + L4 16:00-20:00)
        {numero: 1, nombre: 'Turno 1 - Mañana', inicio: '06:00', fin: '14:00'},
        {numero: 2, nombre: 'Turno 2 - Tarde',  inicio: '14:00', fin: '22:00'},
        {numero: 3, nombre: 'Turno 3 - Noche',  inicio: '22:00', fin: '06:00'},
        {numero: 4, nombre: 'L4 - Tarde (4h)',   inicio: '16:00', fin: '20:00', esL4: true}
    ],
    8: [ // F11 - Fox 11 (turnos normales + L4 06:00-10:00)
        {numero: 1, nombre: 'Turno 1 - Mañana', inicio: '06:00', fin: '14:00'},
        {numero: 2, nombre: 'Turno 2 - Tarde',  inicio: '14:00', fin: '22:00'},
        {numero: 3, nombre: 'Turno 3 - Noche',  inicio: '22:00', fin: '06:00'},
        {numero: 4, nombre: 'L4 - Mañana (4h)', inicio: '06:00', fin: '10:00', esL4: true}
    ]
};

function obtenerHorariosPuesto(puestoId) {
    const h = horariosEspeciales[puestoId];
    return (h && h.length > 0) ? h : null;
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

// Códigos de puestos activos en turno nocturno (Turno 3)
const CODIGOS_NOCTURNOS = new Set(['V1','V2','C','D3','F6','F11']); // Vigía 1, Vigía 2, Conduces, Delta 3, Fox 6, Fox 11

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

    // Es turno noche: filtrar áreas y puestos usando CODIGOS_NOCTURNOS
    const areasNocturnas = new Set();
    Object.entries(puestosData).forEach(([area, puestos]) => {
        if (puestos.some(p => CODIGOS_NOCTURNOS.has(p.codigo))) areasNocturnas.add(area);
    });

    // Deshabilitar áreas sin puestos nocturnos
    Array.from(areaSelect.options).forEach(opt => {
        if (!opt.value) return;
        if (!areasNocturnas.has(opt.value)) {
            opt.disabled = true;
            opt.title = 'No opera en Turno 3 (nocturno)';
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
        mostrarAlerta('El área seleccionada no opera en Turno 3 nocturno', 'warning');
        return;
    }

    // Filtrar puestos del área actual a solo los nocturnos (por código)
    const area = areaSelect.value;
    if (area) {
        const puestos = (puestosData[area] || []).filter(p => CODIGOS_NOCTURNOS.has(p.codigo));
        const puestoActualId = puestoSelect.value;
        const puestoActualCodigo = puestoActualId
            ? (puestosData[area] || []).find(p => String(p.id) === String(puestoActualId))?.codigo
            : null;
        puestoSelect.innerHTML = '<option value="">Seleccione...</option>';
        puestos.forEach(puesto => {
            const option = document.createElement('option');
            option.value = puesto.id;
            option.textContent = puesto.codigo + ' - ' + puesto.nombre;
            puestoSelect.appendChild(option);
        });
        puestoSelect.disabled = false;
        // Si el puesto actual ya no opera de noche, limpiarlo
        if (puestoActualCodigo && !CODIGOS_NOCTURNOS.has(puestoActualCodigo)) {
            puestoSelect.value = '';
            mostrarAlerta('El puesto seleccionado no opera en Turno 3 nocturno', 'warning');
        } else if (puestoActualId) {
            puestoSelect.value = puestoActualId;
        }
    }
}

async function cargarEstadisticasDashboard() {
    // Animación del botón refresh
    const icon = document.getElementById('refresh-icon');
    if (icon) { icon.classList.add('fa-spin'); }

    try {
        const hoy = new Date().toISOString().split('T')[0];

        // Calcular inicio y fin de semana actual
        const ahora = new Date();
        const diaSemana = ahora.getDay();
        const diffLunes = diaSemana === 0 ? -6 : 1 - diaSemana;
        const lunes = new Date(ahora); lunes.setDate(ahora.getDate() + diffLunes);
        const domingo = new Date(lunes); domingo.setDate(lunes.getDate() + 6);
        const inicioSemana = lunes.toISOString().split('T')[0];
        const finSemana    = domingo.toISOString().split('T')[0];

        const [rTrab, rTurnosHoy, rTurnosSemana, rIncap, rLibresSemana, rSupervisoresHoy] = await Promise.all([
            fetch(API_BASE + 'trabajadores.php').then(r => r.json()),
            fetch(API_BASE + 'turnos.php?fecha=' + hoy).then(r => r.json()),
            fetch(API_BASE + 'turnos.php?fecha_inicio=' + inicioSemana + '&fecha_fin=' + finSemana).then(r => r.json()),
            fetch(API_BASE + 'incapacidades.php?activas=1').then(r => r.json()),
            fetch(API_BASE + 'dias_especiales.php?fecha_inicio=' + inicioSemana + '&fecha_fin=' + finSemana).then(r => r.json()).catch(() => ({ success: false })),
            fetch(API_BASE + 'supervisores_turno.php?fecha=' + hoy).then(r => r.json()).catch(() => ({ success: false })),
        ]);

        const trabajadores   = (rTrab.success ? rTrab.data : []).filter(t => t.activo);
        const turnosHoy      = (rTurnosHoy.success ? rTurnosHoy.data : []).filter(t => t.estado !== 'cancelado');
        const turnosSemana   = (rTurnosSemana.success ? rTurnosSemana.data : []).filter(t => t.estado !== 'cancelado');
        const incapacidades  = rIncap.success ? (rIncap.data || []) : [];
        const libresSemana   = (rLibresSemana.success ? rLibresSemana.data : [])
                                .filter(d => ['L','L8','LC'].includes(d.tipo) && ['programado','activo'].includes(d.estado));
        const supervisoresHoy = (rSupervisoresHoy.success ? rSupervisoresHoy.data : []);

        // ── Tarjeta trabajadores ──────────────────────────────────────
        const elTrab = document.getElementById('total-trabajadores');
        if (elTrab) elTrab.textContent = trabajadores.length;

        // ── Tarjeta incapacidades ─────────────────────────────────────
        const elIncap = document.getElementById('incapacidades-activas');
        if (elIncap) elIncap.textContent = incapacidades.length;

        // ── Puestos sin cubrir hoy ────────────────────────────────────
        // Puestos y qué turnos hacen cada uno
        // Turno 3 (nocturno): SOLO V1, V2, C, D3, F6, F11
        // Turnos 1 y 2: todos los puestos
        const PUESTOS_SISTEMA = {
            'DELTA':       ['D1','D2','D3','D4'],
            'FOX':         ['F4','F5','F6','F11','F14','F15'],
            'VIGÍA':       ['V1','V2'],
            'TASA DE USO': ['C'],
            'EQUIPAJES':   ['G']
        };
        const SOLO_NOCHE = new Set(['V1','V2','C','D3','F6','F11']); // solo estos hacen T3
        const TURNOS = [1, 2, 3];

        const cubiertosHoy = new Set();
        turnosHoy.forEach(t => {
            if (!t.tipo_especial) {
                let n = Number(t.numero_turno);
                if (n === 4) n = 1; else if (n === 5) n = 2;
                cubiertosHoy.add(n + '|' + t.puesto_codigo);
            }
        });

        const sinCubrir = [];
        Object.entries(PUESTOS_SISTEMA).forEach(([area, puestos]) => {
            puestos.forEach(p => {
                TURNOS.forEach(n => {
                    // Turno 3 solo aplica a puestos nocturnos
                    if (n === 3 && !SOLO_NOCHE.has(p)) return;
                    if (!cubiertosHoy.has(n + '|' + p)) {
                        sinCubrir.push({ area, puesto: p, turno: n });
                    }
                });
            });
        });

        const totalEsperado = Object.values(PUESTOS_SISTEMA)
            .flatMap((ps, i) => {
                const area = Object.keys(PUESTOS_SISTEMA)[i];
                return ps.flatMap(p => TURNOS.filter(n => !(n === 3 && !SOLO_NOCHE.has(p))));
            }).length;

        // Contar solo normales (sin especiales) para el total
        const turnosNormalesHoy = turnosHoy.filter(t => !t.tipo_especial).length;
        const totalTurnosHoy = turnosNormalesHoy + supervisoresHoy.length;

        const elTurnosHoy = document.getElementById('turnos-hoy');
        if (elTurnosHoy) elTurnosHoy.textContent = totalTurnosHoy;
        const ausentes = turnosHoy.filter(t => t.estado === 'no_presentado').length;
        const elTurnosSub = document.getElementById('turnos-hoy-sub');
        if (elTurnosSub) elTurnosSub.textContent = 'de ' + totalEsperado + ' esperados' + (ausentes > 0 ? ' · ' + ausentes + ' TNR' : '') + (supervisoresHoy.length > 0 ? ' · ' + supervisoresHoy.length + ' SUP' : '');

        const elSinCubrir = document.getElementById('puestos-sin-cubrir');
        if (elSinCubrir) {
            elSinCubrir.textContent = sinCubrir.length;
            elSinCubrir.style.color = sinCubrir.length === 0 ? '#28a745' : sinCubrir.length < 5 ? '#856404' : '#dc3545';
        }
        const elSinCubrirSub = document.getElementById('puestos-sin-cubrir-sub');
        if (elSinCubrirSub) elSinCubrirSub.textContent = sinCubrir.length === 0 ? '¡Todo cubierto!' : 'requieren asignación';

        const iconPuestos = document.getElementById('icon-puestos-libres');
        if (iconPuestos) {
            if (sinCubrir.length === 0) {
                iconPuestos.style.background = '#d4edda';
                iconPuestos.innerHTML = '<i class="fas fa-check-circle" style="color:#28a745;"></i>';
            } else if (sinCubrir.length < 5) {
                iconPuestos.style.background = '#fff3cd';
                iconPuestos.innerHTML = '<i class="fas fa-exclamation-triangle" style="color:#856404;"></i>';
            } else {
                iconPuestos.style.background = '#f8d7da';
                iconPuestos.innerHTML = '<i class="fas fa-times-circle" style="color:#dc3545;"></i>';
            }
        }

        // ── Sin día libre esta semana ─────────────────────────────────
        // Los días libres están en dias_especiales, NO en turnos_asignados
        const conLibre = new Set(
            libresSemana.map(d => Number(d.trabajador_id))
        );
        const sinLibre = trabajadores.filter(t => !conLibre.has(t.id));

        const elSinLibre = document.getElementById('sin-libre-semana');
        if (elSinLibre) {
            elSinLibre.textContent = sinLibre.length;
            elSinLibre.style.color = sinLibre.length === 0 ? '#28a745' : sinLibre.length < 3 ? '#856404' : '#dc3545';
        }
        const elSinLibreSub = document.getElementById('sin-libre-sub');
        if (elSinLibreSub) elSinLibreSub.textContent = sinLibre.length === 0 ? 'Todos tienen libre' : 'trabajadores';

        const iconLibre = document.getElementById('icon-sin-libre');
        if (iconLibre) {
            if (sinLibre.length === 0) {
                iconLibre.style.background = '#d4edda';
                iconLibre.innerHTML = '<i class="fas fa-check-circle" style="color:#28a745;"></i>';
            } else {
                iconLibre.style.background = '#e2d9f3';
                iconLibre.innerHTML = '<i class="fas fa-calendar-times" style="color:#6f42c1;"></i>';
            }
        }

        // ── Barras de progreso por turno ──────────────────────────────
        const panel = document.getElementById('panel-progreso');
        if (panel) panel.style.display = 'block';

        const TURNOS_CFG = [
            { num: 1, nombre: 'T1 Mañana', color: '#0c5460', bg: '#d1ecf1' },
            { num: 2, nombre: 'T2 Tarde',  color: '#856404', bg: '#fff3cd' },
            { num: 3, nombre: 'T3 Noche',  color: '#e0e0e0', bg: '#1a1a2e' },
        ];

        const puestosPorTurno = {};
        Object.entries(PUESTOS_SISTEMA).forEach(([area, ps]) => {
            ps.forEach(p => {
                TURNOS.forEach(n => {
                    if (n === 3 && !SOLO_NOCHE.has(p)) return;
                    if (!puestosPorTurno[n]) puestosPorTurno[n] = 0;
                    puestosPorTurno[n]++;
                });
            });
        });

        let barrasHtml = '';
        TURNOS_CFG.forEach(cfg => {
            const esperados  = puestosPorTurno[cfg.num] || 0;
            const asignados  = turnosHoy.filter(t => {
                if (t.tipo_especial) return false;
                let n = Number(t.numero_turno);
                if (n === 4) n = 1; else if (n === 5) n = 2;
                return n === cfg.num;
            }).length;
            const pct = esperados > 0 ? Math.round((asignados / esperados) * 100) : 0;
            const colorBarra = pct === 100 ? '#28a745' : pct >= 60 ? cfg.color : '#dc3545';

            barrasHtml += `
                <div style="margin-bottom:10px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
                        <span style="font-size:0.82rem;font-weight:600;color:#212529;">${cfg.nombre}</span>
                        <span style="font-size:0.8rem;color:#6c757d;">${asignados} / ${esperados} <span style="color:${colorBarra};font-weight:700;">(${pct}%)</span></span>
                    </div>
                    <div style="background:#e9ecef;border-radius:20px;height:10px;overflow:hidden;">
                        <div style="width:${pct}%;height:100%;background:${colorBarra};border-radius:20px;transition:width 0.6s ease;"></div>
                    </div>
                </div>`;
        });

        const elBarras = document.getElementById('barras-turnos');
        if (elBarras) elBarras.innerHTML = barrasHtml;

        const pct = Math.round((turnosNormalesHoy / totalEsperado) * 100);
        const elProgTotal = document.getElementById('progreso-total');
        if (elProgTotal) elProgTotal.textContent = pct + '% del día cubierto';

        // ── Lista puestos sin cubrir ──────────────────────────────────
        const elListaSC = document.getElementById('lista-puestos-sin-cubrir');
        if (elListaSC) {
            if (sinCubrir.length === 0) {
                elListaSC.innerHTML = '<div style="text-align:center;padding:1rem;color:#28a745;"><i class="fas fa-check-circle" style="font-size:1.5rem;"></i><p style="margin:6px 0 0;font-size:0.85rem;">¡Todos los puestos están cubiertos!</p></div>';
            } else {
                const grupos = {};
                sinCubrir.forEach(p => {
                    if (!grupos[p.area]) grupos[p.area] = { 1: [], 2: [], 3: [] };
                    grupos[p.area][p.turno].push(p.puesto);
                });
                const BG_T = { 1: '#d1ecf1', 2: '#fff3cd', 3: '#1a1a2e' };
                const CO_T = { 1: '#0c5460', 2: '#856404', 3: '#e0e0e0' };
                let html = '<div style="max-height:220px;overflow-y:auto;">';
                Object.entries(grupos).forEach(([area, ts]) => {
                    html += `<div style="margin-bottom:8px;"><span style="font-size:0.75rem;font-weight:700;color:#495057;text-transform:uppercase;letter-spacing:0.5px;">${area}</span><div style="display:flex;flex-wrap:wrap;gap:4px;margin-top:4px;">`;
                    [1,2,3].forEach(n => {
                        ts[n].forEach(p => {
                            html += `<span style="background:${BG_T[n]};color:${CO_T[n]};padding:2px 8px;border-radius:4px;font-size:0.78rem;font-weight:700;">${n}${p}</span>`;
                        });
                    });
                    html += '</div></div>';
                });
                html += '</div>';
                elListaSC.innerHTML = html;
            }
        }

        // ── Lista sin libre ───────────────────────────────────────────
        const elListaL = document.getElementById('lista-sin-libre');
        if (elListaL) {
            if (sinLibre.length === 0) {
                elListaL.innerHTML = '<div style="text-align:center;padding:1rem;color:#28a745;"><i class="fas fa-check-circle" style="font-size:1.5rem;"></i><p style="margin:6px 0 0;font-size:0.85rem;">¡Todos tienen día libre asignado!</p></div>';
            } else {
                let html = '<div style="max-height:220px;overflow-y:auto;display:flex;flex-direction:column;gap:4px;">';
                sinLibre.forEach(t => {
                    html += `<div style="display:flex;align-items:center;gap:8px;padding:6px 10px;background:#f8f9fa;border-radius:6px;border-left:3px solid #6f42c1;">
                        <i class="fas fa-user" style="font-size:0.75rem;color:#6f42c1;"></i>
                        <span style="font-size:0.85rem;color:#212529;">${t.nombre}</span>
                    </div>`;
                });
                html += '</div>';
                elListaL.innerHTML = html;
            }
        }

        // Cargar alertas
        await cargarAlertas();

    } catch (error) {
        console.error('Error cargando estadísticas:', error);
        mostrarAlerta('Error cargando el dashboard', 'danger');
    } finally {
        if (icon) icon.classList.remove('fa-spin');
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


        // Alertas de ausentes hoy
        try {
            const hoy2 = new Date().toISOString().split('T')[0];
            const rAus = await fetch(API_BASE + 'turnos.php?fecha=' + hoy2).then(r => r.json());
            const ausentes2 = (rAus.success ? rAus.data : []).filter(t => t.estado === 'no_presentado');
            ausentes2.forEach(t => {
                alertas.push({
                    tipo: 'danger',
                    icono: 'fa-user-times',
                    titulo: 'TNR — ' + t.trabajador,
                    descripcion: (t.puesto_codigo || '') + ' · ' + (t.turno_nombre || ''),
                    badge: 'TNR',
                    badgeClass: 'danger'
                });
            });
        } catch(e) {}

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
    const turnoSelect = document.getElementById('turno-select');
    
    if (!areaSelect || !puestoSelect) return;
    
    const area = areaSelect.value;

    // Resetear puesto y turno
    puestoSelect.innerHTML = '<option value="">Seleccione...</option>';
    puestoSelect.disabled = true;
    if (turnoSelect) {
        turnoSelect.innerHTML = '<option value="">Seleccione puesto primero...</option>';
        turnoSelect.disabled = true;
    }
    resetearTrabajadores();
    
    if (!area) return;
    
    const puestos = puestosData[area] || [];
    puestos.forEach(puesto => {
        const option = document.createElement('option');
        option.value = puesto.id;
        option.textContent = puesto.codigo + ' - ' + puesto.nombre;
        puestoSelect.appendChild(option);
    });
    
    puestoSelect.disabled = false;
}

function actualizarTurnosDisponibles() {
  const puestoSelect = document.getElementById('puesto-select');
  const turnoSelect = document.getElementById('turno-select');

  if (!puestoSelect || !turnoSelect) return;

  const puestoId = parseInt(puestoSelect.value);

  if (!puestoId) {
    turnoSelect.innerHTML = '<option value="">Seleccione puesto primero...</option>';
    turnoSelect.disabled = true;
    return;
  }

  turnoSelect.innerHTML = '<option value="">Seleccione...</option>';
  turnoSelect.disabled = false;

  const horariosEsp = obtenerHorariosPuesto(puestoId);

  if (horariosEsp) {
    // Puesto con horarios propios (especiales o L4)
    const normales = horariosEsp.filter(h => !h.esL4);
    const l4s      = horariosEsp.filter(h => h.esL4);

    normales.forEach(horario => {
      const option = document.createElement('option');
      option.value = horario.numero;
      option.textContent = horario.nombre + ' (' + horario.inicio + ' - ' + horario.fin + ')';
      turnoSelect.appendChild(option);
    });

    if (l4s.length > 0) {
      const sep = document.createElement('option');
      sep.disabled = true;
      sep.textContent = '--- Turno L4 (4 Horas) ---';
      turnoSelect.appendChild(sep);
      l4s.forEach(horario => {
        const option = document.createElement('option');
        option.value = horario.numero;
        option.textContent = horario.nombre + ' (' + horario.inicio + ' - ' + horario.fin + ')';
        option.dataset.l4 = 'true';
        turnoSelect.appendChild(option);
      });
    }
  } else {
    // Puesto normal: turnos de la BD, ocultar Turno 3 si el puesto no es nocturno
    // Construir set de IDs de puestos nocturnos desde puestosData
    const puestosNocturnosIds = new Set();
    Object.values(puestosData).flat().forEach(p => {
        if (CODIGOS_NOCTURNOS.has(p.codigo)) puestosNocturnosIds.add(p.id);
    });
    const esNocturno = puestosNocturnosIds.has(puestoId);
    turnosData.forEach(turno => {
      if (!esNocturno && turno.numero_turno === 3) return;
      const option = document.createElement('option');
      option.value = turno.id;
      option.textContent = turno.nombre + ' (' + turno.hora_inicio.substring(0, 5) + ' - ' + turno.hora_fin.substring(0, 5) + ')';
      turnoSelect.appendChild(option);
    });
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
            const disponibles = data.data.filter(t => String(t.cargo || '').toLowerCase() !== 'supervisor');
            disponibles.forEach(trabajador => {
                const option = document.createElement('option');
                option.value = trabajador.id;
                option.textContent = `${trabajador.nombre} - ${trabajador.cedula}`;
                if (trabajador.restricciones) {
                    option.dataset.restricciones = trabajador.restricciones;
                }
                trabajadorSelect.appendChild(option);
            });
            if (disponibles.length > 0) {
                trabajadorSelect.disabled = false;
            } else {
                trabajadorSelect.innerHTML = '<option value="">No hay trabajadores disponibles para asignar</option>';
                trabajadorSelect.disabled = true;
            }
        } else {
            trabajadorSelect.innerHTML = '<option value="">No hay trabajadores disponibles</option>';
            trabajadorSelect.disabled = true;
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

  if (!datos.trabajador_id || !datos.puesto_trabajo_id || !datos.turno_id || !datos.fecha) {
    mostrarAlerta('Complete todos los campos requeridos', 'warning');
    return;
  }

  // Validar automáticamente antes de guardar
  try {
    const response = await fetch(`${API_BASE}turnos.php?action=validar`, {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify(datos)
    });

    const data = await response.json();
    
    if (!data.success || !data.data.valido) {
      const errores = data.data?.errores || ['Error desconocido en validación'];
      mostrarAlerta('No se puede asignar el turno:<br>' + errores.map(e => '• ' + e).join('<br>'), 'danger');
      return;
    }
  } catch (error) {
    console.error('Error validando:', error);
    mostrarAlerta('Error al validar la asignación', 'danger');
    return;
  }

  mostrarSpinner('Asignando turno...');

  try {
    const response = await fetch(`${API_BASE}turnos.php`, {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify(datos)
    });

    const data = await response.json();

    if(data.success) {
      ocultarSpinner();
      mostrarAlerta('Turno asignado exitosamente', 'success');
      registrarCambio('Turno asignado', data.data ? (data.data.trabajador || '') + ' · ' + (data.data.fecha || '') : 'Nueva asignación');
      limpiarFormulario();
      cargarEstadisticasDashboard();
    } else {
      ocultarSpinner();
      const mensaje = data.message || 'Error al asignar turno';
      const errores = data.errores ? '<ul>' + data.errores.map(e => `<li>${e}</li>`).join('') + '</ul>' : '';
      mostrarAlerta(mensaje + errores, 'danger');
    }
  } catch (error) {
    ocultarSpinner();
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
    htmlContent += '<li>Los supervisores NO se asignan automáticamente</li>';
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
    

    
    htmlContent += '<div class="alert alert-warning" style="margin-top: 1rem;">';
    htmlContent += '<strong>⚠️ Advertencia:</strong> Si ya existen turnos asignados para este mes, NO se sobrescribirán. Solo se llenarán los espacios vacíos.';
    htmlContent += '</div>';
    
    htmlContent += '<div class="form-actions">';
    htmlContent += '<button type="submit" class="btn btn-primary"><i class="fas fa-magic"></i> Generar Asignaciones</button>';
    htmlContent += '<button type="button" class="btn btn-outline" style="border-color:#dc3545;color:#dc3545;" onclick="deshacerMesAutomatico()"><i class="fas fa-undo"></i> Deshacer mes</button>';
    htmlContent += '<button type="button" class="btn btn-outline" onclick="cerrarModal()"><i class="fas fa-times"></i> Cancelar</button>';
    htmlContent += '</div>';
    htmlContent += '</form>';
    
    modalBody.innerHTML = htmlContent;
    
    document.getElementById('mes-asignacion').value = mesActualNum;
    modalOverlay.classList.add('active');
    
    document.getElementById('form-asignacion-automatica').addEventListener('submit', ejecutarAsignacionAutomatica);
}

// ─── DESHACER MES AUTOMÁTICO ─────────────────────────────────────────────────
async function deshacerMesAutomatico() {
    const mes  = document.getElementById('mes-asignacion')?.value;
    const anio = document.getElementById('anio-asignacion')?.value;
    if (!mes || !anio) {
        mostrarAlerta('Selecciona el mes y año primero', 'warning');
        return;
    }

    const meses = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio',
                   'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];

    const ok = await confirmarAccion({
        titulo:   '¿Deshacer asignaciones de ' + meses[mes] + ' ' + anio + '?',
        mensaje:  'Se eliminarán <strong>todos los turnos y días libres automáticos</strong> de ese mes. ' +
                  'Las asignaciones manuales también se borrarán. Esta acción no se puede deshacer.',
        textoBtn: 'Sí, eliminar todo el mes',
        tipoBtn:  'danger',
        icono:    'fa-undo'
    });
    if (!ok) return;

    cerrarModal();
    mostrarSpinner('Deshaciendo asignaciones de ' + meses[mes] + ' ' + anio + '...');

    try {
        const res  = await fetch(API_BASE + 'asignacion_automatica.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'deshacer_mes', mes: parseInt(mes), anio: parseInt(anio) })
        });
        const data = await res.json();
        ocultarSpinner();

        if (data.success) {
            mostrarAlerta(
                '✅ Eliminados: ' + data.turnos_eliminados + ' turnos y ' +
                data.libres_eliminados + ' días libres de ' + meses[mes] + ' ' + anio,
                'success'
            );
            registrarCambio('Mes deshecho', meses[mes] + ' ' + anio);
            cargarVistaDiaria();
            cargarEstadisticasDashboard();
        } else {
            mostrarAlerta('Error: ' + (data.message || 'No se pudo deshacer'), 'danger');
        }
    } catch(e) {
        ocultarSpinner();
        mostrarAlerta('Error de conexión: ' + e.message, 'danger');
    }
}


async function ejecutarAsignacionAutomatica(e) {
    e.preventDefault();
    
    const mesSelect = document.getElementById('mes-asignacion');
    const anioInput = document.getElementById('anio-asignacion');
    if (!mesSelect || !anioInput) {
        mostrarAlerta('Error: elementos del formulario no encontrados', 'danger');
        return;
    }

    const mes  = parseInt(mesSelect.value);
    const anio = parseInt(anioInput.value);
    const saltarDomingos = false; // Terminal trabaja 24/7, incluidos domingos
    
    const nombresMeses = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 
                          'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
    
    const mesNombre = nombresMeses[mes] || 'Desconocido';
    
    const okAuto = await confirmarAccion({ titulo: 'Generar asignaciones automáticas', mensaje: 'Se generarán asignaciones para ' + mesNombre + ' ' + anio + '. Los turnos ya asignados no se tocarán.', textoBtn: 'Generar', tipoBtn: 'primary', icono: 'fa-magic' });
    if (!okAuto) return;
    
    cerrarModal();

    // Animación de progreso simulada mientras el backend trabaja
    const pasos = [
        { pct: 8,  msg: 'Cargando trabajadores y puestos...' },
        { pct: 18, msg: 'Calculando disponibilidad...' },
        { pct: 32, msg: 'Asignando Turno 1 - Mañana...' },
        { pct: 52, msg: 'Asignando Turno 2 - Tarde...' },
        { pct: 68, msg: 'Asignando Turno 3 - Noche...' },
        { pct: 82, msg: 'Generando días libres automáticos...' },
        { pct: 93, msg: 'Finalizando y guardando...' },
    ];

    mostrarProgreso('Generando ' + mesNombre + ' ' + anio, pasos[0].msg, pasos[0].pct);

    // Avanzar la barra mientras espera la respuesta
    let pasoIdx = 1;
    const intervalo = setInterval(() => {
        if (pasoIdx < pasos.length) {
            mostrarProgreso('Generando ' + mesNombre + ' ' + anio, pasos[pasoIdx].msg, pasos[pasoIdx].pct);
            pasoIdx++;
        }
    }, 900);

    try {
        const response = await fetch(API_BASE + 'asignacion_automatica.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ mes, anio, opciones: {} })
        });

        clearInterval(intervalo);

        if (!response.ok) throw new Error('Error HTTP: ' + response.status);

        // Completar al 100%
        mostrarProgreso('Generando ' + mesNombre + ' ' + anio, '¡Completado!', 100);
        await new Promise(r => setTimeout(r, 600));
        ocultarProgreso();

        const result = await response.json();

        if (result.success) {
            cargarEstadisticasDashboard();
            cargarVistaDiaria();

            if (result.errores > 0 && result.detalle_errores?.length > 0) {
                // Agrupar puestos sin cubrir por fecha
                const sinCubrir = {};
                result.detalle_errores
                    .filter(e => e.error === 'Sin trabajadores disponibles')
                    .forEach(e => {
                        if (!sinCubrir[e.fecha]) sinCubrir[e.fecha] = [];
                        sinCubrir[e.fecha].push('T' + e.turno + ' · ' + e.puesto);
                    });

                const fechas = Object.keys(sinCubrir).sort();
                const DIAS = ['Dom','Lun','Mar','Mié','Jue','Vie','Sáb'];

                let filasHTML = fechas.map(f => {
                    const dObj = new Date(f + 'T00:00:00');
                    const dia  = DIAS[dObj.getDay()];
                    const num  = dObj.getDate();
                    const puestos = sinCubrir[f];
                    const esDom = dObj.getDay() === 0;
                    return `<tr>
                        <td style="padding:5px 10px;font-weight:700;white-space:nowrap;color:${esDom?'#dc3545':'#212529'};">
                            ${dia} ${num}
                        </td>
                        <td style="padding:5px 10px;">
                            ${puestos.map(p => {
                                const t = p.charAt(1);
                                const bg = t==='1'?'#d1ecf1':t==='2'?'#fff3cd':'#e2d9f3';
                                const col = t==='1'?'#0c5460':t==='2'?'#856404':'#3a1060';
                                return `<span style="background:${bg};color:${col};padding:2px 6px;border-radius:4px;font-size:0.78rem;font-weight:600;margin:2px;display:inline-block;">${p}</span>`;
                            }).join('')}
                        </td>
                    </tr>`;
                }).join('');

                const modalOverlay = document.getElementById('modal-overlay');
                const modalTitulo  = document.getElementById('modal-titulo');
                const modalBody    = document.getElementById('modal-body');
                modalTitulo.textContent = 'Resultado de asignación automática';
                modalBody.innerHTML = `
                    <div style="display:flex;gap:16px;margin-bottom:16px;flex-wrap:wrap;">
                        <div style="flex:1;min-width:120px;background:#d4edda;border-radius:8px;padding:12px;text-align:center;">
                            <div style="font-size:1.6rem;font-weight:800;color:#155724;">${result.asignaciones}</div>
                            <div style="font-size:0.78rem;color:#155724;">turnos asignados</div>
                        </div>
                        <div style="flex:1;min-width:120px;background:#d1ecf1;border-radius:8px;padding:12px;text-align:center;">
                            <div style="font-size:1.6rem;font-weight:800;color:#0c5460;">${result.libres_asignados}</div>
                            <div style="font-size:0.78rem;color:#0c5460;">días libres generados</div>
                        </div>
                        <div style="flex:1;min-width:120px;background:#f8d7da;border-radius:8px;padding:12px;text-align:center;">
                            <div style="font-size:1.6rem;font-weight:800;color:#842029;">${result.errores}</div>
                            <div style="font-size:0.78rem;color:#842029;">puestos sin cubrir</div>
                        </div>
                    </div>
                    <div style="font-size:0.85rem;font-weight:700;color:#495057;margin-bottom:8px;">
                        📋 Puestos que quedaron sin trabajador disponible:
                    </div>
                    <div style="max-height:340px;overflow-y:auto;border:1px solid #dee2e6;border-radius:6px;">
                        <table style="width:100%;border-collapse:collapse;">
                            <thead>
                                <tr style="background:#f8f9fa;position:sticky;top:0;">
                                    <th style="padding:6px 10px;font-size:0.78rem;text-align:left;border-bottom:2px solid #dee2e6;width:80px;">Fecha</th>
                                    <th style="padding:6px 10px;font-size:0.78rem;text-align:left;border-bottom:2px solid #dee2e6;">Puestos sin cubrir</th>
                                </tr>
                            </thead>
                            <tbody>${filasHTML}</tbody>
                        </table>
                    </div>
                    <div style="margin-top:12px;font-size:0.78rem;color:#6c757d;">
                        💡 Estos puestos no tienen trabajadores disponibles ese día. Puedes asignarlos manualmente desde la vista diaria.
                    </div>
                    <div class="form-actions" style="margin-top:16px;">
                        <button class="btn btn-outline" onclick="cerrarModal()">Cerrar</button>
                    </div>`;
                modalOverlay.style.display = 'flex';
            } else {
                let mensaje = '✅ ' + mesNombre + ' ' + anio + ': ' + result.asignaciones + ' turnos asignados';
                if (result.libres_asignados > 0) mensaje += ' · ' + result.libres_asignados + ' días libres';
                mostrarAlerta(mensaje, 'success');
            }
        } else {
            mostrarAlerta('Error: ' + result.message, 'danger');
        }

    } catch (error) {
        clearInterval(intervalo);
        ocultarProgreso();
        console.error('Error completo:', error);
        mostrarAlerta('Error en asignacion automatica: ' + error.message, 'danger');
    }
}

// ─── PESTAÑA TURNO ESPECIAL ────────────────────────────────

function cambiarTabAsignar(tab) {
    const esNormal = tab === 'normal';
    document.getElementById('panel-turno-normal').style.display  = esNormal ? '' : 'none';
    document.getElementById('panel-turno-especial').style.display = esNormal ? 'none' : '';

    const tNormal  = document.getElementById('tab-normal');
    const tEspecial = document.getElementById('tab-especial');

    tNormal.style.borderBottomColor  = esNormal ? 'var(--terminal)' : 'transparent';
    tNormal.style.color              = esNormal ? 'var(--terminal)' : '#6c757d';
    tNormal.style.fontWeight         = esNormal ? '700' : '600';

    tEspecial.style.borderBottomColor = esNormal ? 'transparent' : 'var(--terminal)';
    tEspecial.style.color             = esNormal ? '#6c757d' : 'var(--terminal)';
    tEspecial.style.fontWeight        = esNormal ? '600' : '700';
}

async function onCambioTipoEspecial() {
    const tipo  = document.getElementById('tipo-especial').value;
    const fecha = document.getElementById('fecha-especial').value;
    const sel   = document.getElementById('trabajador-especial');
    const aviso = document.getElementById('aviso-libre');
    const panelHoras = document.getElementById('panel-horas-sup');

    sel.innerHTML = '<option value="">Cargando...</option>';
    sel.disabled  = true;
    aviso.style.display = 'none';

    // Mostrar/ocultar panel de horas según tipo
    if (panelHoras) panelHoras.style.display = tipo === 'SUP' ? '' : 'none';

    // Limpiar horas al cambiar tipo
    const hi = document.getElementById('sup-hora-inicio');
    const hf = document.getElementById('sup-hora-fin');
    const res = document.getElementById('sup-horas-resumen');
    if (hi) hi.value = '';
    if (hf) hf.value = '';
    if (res) res.style.display = 'none';

    if (!tipo || (!fecha && tipo !== 'SUP')) {
        sel.innerHTML = '<option value="">Seleccione fecha y tipo primero...</option>';
        return;
    }

    await cargarTrabajadoresEspecial(tipo, fecha);
}

async function cargarTrabajadoresEspecial(tipo, fecha) {
    const sel   = document.getElementById('trabajador-especial');
    const aviso = document.getElementById('aviso-libre');
    const lista = document.getElementById('lista-sin-libre');

    try {
        const res  = await fetch(API_BASE + 'trabajadores.php');
        const data = await res.json();

        if (!data.success) return;

        let todos = data.data.filter(t => t.activo);
        sel.innerHTML = '<option value="">Seleccione trabajador...</option>';

        if (tipo === 'SUP') {
            // todos = todos.filter(t => String(t.cargo || '').toLowerCase() === 'supervisor');
        }

        if (tipo === 'L') {
            // Obtener quiénes ya tienen libre esta semana
            const resSL = await fetch(API_BASE + 'turnos.php?action=sin_libre_semana&fecha=' + fecha);
            const dataSL = await resSL.json();
            const sinLibre = dataSL.success ? dataSL.data.map(t => t.id) : [];

            // Mostrar aviso con la lista de quiénes no tienen libre
            if (sinLibre.length > 0) {
                const nombres = dataSL.data.map(t => t.nombre).join(', ');
                lista.innerHTML = `<small style="color:#0d6efd;">${nombres}</small>`;
                aviso.style.display = 'block';
            } else {
                aviso.style.display = 'none';
            }

            // Poner primero los que NO tienen libre (sugeridos), luego los demás
            const sugeridos = todos.filter(t => sinLibre.includes(t.id));
            const otros     = todos.filter(t => !sinLibre.includes(t.id));

            if (sugeridos.length > 0) {
                const sep = document.createElement('option');
                sep.disabled = true;
                sep.textContent = '── Sin libre esta semana (sugeridos) ──';
                sel.appendChild(sep);
                sugeridos.forEach(t => {
                    const o = document.createElement('option');
                    o.value = t.id;
                    o.textContent = t.nombre + ' (' + t.cedula + ')';
                    sel.appendChild(o);
                });
            }
            if (otros.length > 0) {
                const sep2 = document.createElement('option');
                sep2.disabled = true;
                sep2.textContent = '── Ya tienen libre esta semana ──';
                sel.appendChild(sep2);
                otros.forEach(t => {
                    const o = document.createElement('option');
                    o.value = t.id;
                    o.textContent = t.nombre + ' (' + t.cedula + ')';
                    sel.appendChild(o);
                });
            }
        } else {
            // ADM / ADMM / ADMT: traer turnos y especiales del día para clasificar
            const [rT, rE] = await Promise.all([
                fetch(API_BASE + 'turnos.php?fecha=' + fecha).then(r => r.json()),
                fetch(API_BASE + 'dias_especiales.php?fecha_inicio=' + fecha + '&fecha_fin=' + fecha).then(r => r.json())
            ]);

            const turnosDia    = (rT.success ? rT.data : []).filter(t => t.estado !== 'cancelado' && !t.tipo_especial);
            const especialesDia = (rE.success ? rE.data : []);

            // Sets de IDs con ocupación ese día
            const conTurno = new Set(turnosDia.map(t => parseInt(t.trabajador_id)));
            const conEsp   = new Set(especialesDia.map(e => parseInt(e.trabajador_id)));

            // Mapa turno por trabajador para mostrar detalle
            const detalleTurno = {};
            turnosDia.forEach(t => {
                let n = Number(t.numero_turno);
                if ([4,9].includes(n)) n = 1;
                if ([5,10].includes(n)) n = 2;
                detalleTurno[parseInt(t.trabajador_id)] = 'T' + n + (t.puesto_codigo ? ' · ' + t.puesto_codigo : '');
            });
            const detalleEsp = {};
            especialesDia.forEach(e => {
                detalleEsp[parseInt(e.trabajador_id)] = e.tipo;
            });

            const libres    = todos.filter(t => !conTurno.has(t.id) && !conEsp.has(t.id));
            const conTurnos = todos.filter(t =>  conTurno.has(t.id));
            const conEsps   = todos.filter(t => !conTurno.has(t.id) && conEsp.has(t.id));

            if (libres.length > 0) {
                const sep = document.createElement('option');
                sep.disabled = true;
                sep.textContent = '✅ Disponibles ese día (' + libres.length + ')';
                sel.appendChild(sep);
                libres.forEach(t => {
                    const o = document.createElement('option');
                    o.value = t.id;
                    o.textContent = t.nombre + ' (' + t.cedula + ')';
                    sel.appendChild(o);
                });
            }
            if (conTurnos.length > 0) {
                const sep = document.createElement('option');
                sep.disabled = true;
                sep.textContent = '⚠️ Ya tienen turno ese día (' + conTurnos.length + ')';
                sel.appendChild(sep);
                conTurnos.forEach(t => {
                    const o = document.createElement('option');
                    o.value = t.id;
                    o.textContent = t.nombre + ' (' + t.cedula + ') — ' + (detalleTurno[t.id] || '');
                    sel.appendChild(o);
                });
            }
            if (conEsps.length > 0) {
                const sep = document.createElement('option');
                sep.disabled = true;
                sep.textContent = '🔵 Con especial ese día (' + conEsps.length + ')';
                sel.appendChild(sep);
                conEsps.forEach(t => {
                    const o = document.createElement('option');
                    o.value = t.id;
                    o.textContent = t.nombre + ' (' + t.cedula + ') — ' + (detalleEsp[t.id] || '');
                    sel.appendChild(o);
                });
            }
        }

        sel.disabled = false;

        // Advertencia si ya tiene libre al cambiar selección
        sel.onchange = async function() {
            const avisoYa   = document.getElementById('aviso-ya-tiene-libre');
            const validacion = document.getElementById('validacion-especial');
            avisoYa.style.display   = 'none';
            validacion.style.display = 'none';
            validacion.className     = 'alert';

            if (!sel.value || !fecha) return;

            // Validar día libre
            if (tipo === 'L') {
                const r = await fetch(API_BASE + 'turnos.php?action=tiene_libre&trabajador_id=' + sel.value + '&fecha=' + fecha);
                const d = await r.json();
                if (d.success && d.data.tiene_libre) avisoYa.style.display = 'block';
                return;
            }

            // Validar ADM / ADMM / ADMT — consultar conflictos
            if (['ADM','ADMM','ADMT'].includes(tipo)) {
                validacion.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Verificando disponibilidad...';
                validacion.className = 'alert alert-info';
                validacion.style.display = 'block';

                try {
                    // Verificar turnos del día + días especiales en paralelo
                    const [rTurnos, rEsp] = await Promise.all([
                        fetch(API_BASE + 'turnos.php?fecha=' + fecha).then(r => r.json()),
                        fetch(API_BASE + 'dias_especiales.php?fecha_inicio=' + fecha + '&fecha_fin=' + fecha).then(r => r.json())
                    ]);

                    const tid = parseInt(sel.value);
                    const conflictos = [];

                    // ¿Tiene turno normal ese día?
                    const turnosDia = (rTurnos.success ? rTurnos.data : [])
                        .filter(t => t.estado !== 'cancelado' && !t.tipo_especial && parseInt(t.trabajador_id) === tid);
                    if (turnosDia.length > 0) {
                        const t = turnosDia[0];
                        let n = Number(t.numero_turno);
                        if ([4,9].includes(n)) n = 1;
                        if ([5,10].includes(n)) n = 2;
                        const hora = n===1?'06:00-14:00': n===2?'14:00-22:00':'22:00-06:00';
                        conflictos.push('⚠️ Tiene turno T' + n + ' asignado ese día (' + hora + ')');
                    }

                    // ¿Tiene día especial ese día?
                    const espDia = (rEsp.success ? rEsp.data : [])
                        .filter(e => parseInt(e.trabajador_id) === tid);
                    espDia.forEach(e => {
                        if (e.tipo === tipo) {
                            conflictos.push('❌ Ya tiene ' + tipo + ' asignado ese día');
                        } else {
                            conflictos.push('⚠️ Ya tiene ' + e.tipo + ' asignado ese día');
                        }
                    });

                    if (conflictos.length === 0) {
                        const nombre = sel.options[sel.selectedIndex]?.text?.split(' (')[0] || '';
                        validacion.innerHTML = '<i class="fas fa-check-circle"></i> <strong>' + nombre + '</strong> está disponible para ' + tipo + ' el ' + fecha;
                        validacion.className = 'alert alert-success';
                    } else {
                        const listaHTML = conflictos.map(c => '<li>' + c + '</li>').join('');
                        validacion.innerHTML = '<strong>Conflictos encontrados:</strong><ul style="margin:6px 0 0 16px;padding:0;">' + listaHTML + '</ul>';
                        validacion.className = 'alert alert-warning';
                    }
                } catch(err) {
                    validacion.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Error al verificar disponibilidad';
                    validacion.className = 'alert alert-danger';
                }
                validacion.style.display = 'block';
            }
        };

    } catch(e) {
        sel.innerHTML = '<option value="">Error al cargar trabajadores</option>';
        console.error(e);
    }
}

async function guardarTurnoEspecial(e) {
    e.preventDefault();

    const fecha  = document.getElementById('fecha-especial').value;
    const tipo   = document.getElementById('tipo-especial').value;
    const trabId = document.getElementById('trabajador-especial').value;
    const aviso  = document.getElementById('validacion-especial');

    if (!fecha || !tipo || !trabId) {
        aviso.className = 'alert alert-warning';
        aviso.textContent = 'Complete todos los campos.';
        aviso.style.display = 'block';
        return;
    }

    mostrarSpinner('Guardando turno especial...');

    // SUP → supervisores_turno.php (tiene horas libres)
    if (tipo === 'SUP') {
        const horaInicio = document.getElementById('sup-hora-inicio')?.value;
        const horaFin    = document.getElementById('sup-hora-fin')?.value;
        if (!horaInicio || !horaFin) {
            const aviso = document.getElementById('validacion-especial');
            aviso.className = 'alert alert-warning';
            aviso.textContent = 'Ingresa la hora de entrada y salida del supervisor.';
            aviso.style.display = 'block';
            ocultarSpinner();
            return;
        }
        try {
            const res = await fetch(API_BASE + 'supervisores_turno.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ trabajador_id: trabId, fecha, hora_inicio: horaInicio, hora_fin: horaFin, usuario_id: 1 })
            });
            const r = await res.json();
            ocultarSpinner();
            if (r.success) {
                mostrarAlerta('✅ Turno supervisor guardado', 'success');
                limpiarFormularioEspecial();
                cargarVistaDiaria();
            } else {
                const aviso = document.getElementById('validacion-especial');
                aviso.className = 'alert alert-danger';
                aviso.textContent = r.message || 'Error al guardar';
                aviso.style.display = 'block';
            }
        } catch(err) {
            ocultarSpinner();
            mostrarAlerta('Error de conexión: ' + err.message, 'danger');
        }
        return;
    }

    // L, L8, LC, VAC, SUS, ADM, ADMM, ADMT → dias_especiales.php
    const tiposDiasEspeciales = ['L','L8','LC','VAC','SUS','ADM','ADMM','ADMT'];
    const esDiaEspecial = tiposDiasEspeciales.includes(tipo);

    const datos = { 
        trabajador_id: trabId, 
        tipo, 
        fecha_inicio: fecha, 
        fecha_fin: null, 
        horas_inicio: null,
        horas_fin: null,
        descripcion: '', 
        estado: 'programado' 
    };
    const endpoint = 'dias_especiales.php';

    try {
        const res = await fetch(API_BASE + endpoint, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(datos)
        });
        const r = await res.json();
        if (r.success) {
            ocultarSpinner();
            mostrarAlerta('✅ Turno especial asignado correctamente', 'success');
            limpiarFormularioEspecial();
            cargarEstadisticasDashboard();
            cargarVistaDiaria();
        } else {
            ocultarSpinner();
            aviso.className = 'alert alert-danger';
            aviso.innerHTML = r.message || 'Error al guardar';
            aviso.style.display = 'block';
        }
    } catch(e) {
        ocultarSpinner();
        mostrarAlerta('Error de conexión: ' + e.message, 'danger');
    }
}

function limpiarFormularioEspecial() {
    document.getElementById('form-turno-especial').reset();
    document.getElementById('aviso-libre').style.display = 'none';
    document.getElementById('aviso-ya-tiene-libre').style.display = 'none';
    document.getElementById('validacion-especial').style.display = 'none';
    const sel = document.getElementById('trabajador-especial');
    sel.innerHTML = '<option value="">Seleccione fecha y tipo primero...</option>';
    sel.disabled = true;

    // Fecha de hoy por defecto
    document.getElementById('fecha-especial').value = new Date().toISOString().split('T')[0];
}

function limpiarFormulario() {
  const form = document.getElementById('form-asignar-turno');
  if (form) form.reset();

  const hoy = new Date().toISOString().split('T')[0];
  const fechaTurno = document.getElementById('fecha-turno');
  if (fechaTurno) fechaTurno.value = hoy;

  const puestoSelect = document.getElementById('puesto-select');
  if (puestoSelect) {
    puestoSelect.disabled = true;
    puestoSelect.innerHTML = '<option value="">Seleccione área primero...</option>';
  }

  const turnoSelect = document.getElementById('turno-select');
  if (turnoSelect) {
    turnoSelect.disabled = true;
    turnoSelect.innerHTML = '<option value="">Seleccione puesto primero...</option>';
  }

  resetearTrabajadores();

  const validacionMensaje = document.getElementById('validacion-mensaje');
  if (validacionMensaje) validacionMensaje.style.display = 'none';
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

        const [response, respEsp, respSup] = await Promise.all([
            fetch(url),
            fetch(API_BASE + 'dias_especiales.php?fecha_inicio=' + fecha + '&fecha_fin=' + fecha),
            fetch(API_BASE + 'supervisores_turno.php?fecha=' + fecha)
        ]);
        const data    = await response.json();
        const dataEsp = await respEsp.json();
        const dataSup = await respSup.json();

        console.log('Turnos del dia:', data);

        if (data.success) {
            const especiales = (dataEsp.success ? dataEsp.data : [])
                .filter(e => ['ADM','ADMM','ADMT'].includes(e.tipo));
            const supervisores = dataSup.success ? dataSup.data : [];
            renderizarVistaDiaria(data.data || [], fecha, turno, especiales, supervisores);
        } else {
            container.innerHTML = '<div class="no-turnos-message"><i class="fas fa-calendar-times"></i><p>Error al cargar turnos</p></div>';
        }
        cargarNovedad(fecha);
    } catch (error) {
        console.error('Error cargando vista diaria:', error);
        container.innerHTML = '<div class="no-turnos-message"><i class="fas fa-exclamation-triangle"></i><p>Error de conexión</p></div>';
    }
}

function renderizarVistaDiaria(turnos, fecha, filtroTurno, admList = [], supervisores = []) {
    const container = document.getElementById('calendario-vista-diaria');
    if (!container) return;

    const TIPOS_ESPECIALES = ['L', 'ADMM', 'ADMT', 'ADM'];

    // Excluir cancelados
    turnos = turnos.filter(t => t.estado !== 'cancelado');

    // Asignar grupo de turno a los especiales
    turnos.forEach(t => {
        if (!t.tipo_especial) return;
        if (t.tipo_especial === 'ADMM' || t.tipo_especial === 'L') t._grupoTurno = 1;
        else if (t.tipo_especial === 'ADMT') t._grupoTurno = 2;
        else if (t.tipo_especial === 'ADM')  t._grupoTurno = 1;
    });

    // Aplicar filtro de turno
    let turnosFiltrados = turnos;
    if (filtroTurno) {
        turnosFiltrados = turnos.filter(t => {
            if (t.tipo_especial) return String(t._grupoTurno) === String(filtroTurno);
            return String(t.numero_turno) === String(filtroTurno);
        });
    }

    // Agrupar por número de turno
    const turnosPorNumero = {};
    turnosFiltrados.forEach(t => {
        let nums = [];
        if (t.tipo_especial) {
            nums = t.tipo_especial === 'ADM' ? [1, 2] : [t._grupoTurno];
        } else {
            let n = Number(t.numero_turno);
            if (n === 4 || n === 9)  n = 1;
            else if (n === 5 || n === 10) n = 2;
            nums = [n];
        }
        nums.forEach(n => {
            if (!turnosPorNumero[n]) turnosPorNumero[n] = [];
            turnosPorNumero[n].push(t);
        });
    });

    const totalAsignados = turnos.length;
    const puestosDefinidos = 17;
    const turnosEsperados  = puestosDefinidos * 3;

    const fechaObj = new Date(fecha + 'T00:00:00');
    const fechaFormateada = fechaObj.toLocaleDateString('es-CO', {
        weekday: 'long', day: 'numeric', month: 'long', year: 'numeric'
    });

    let html = '<div class="day-header">';
    html += '<div class="day-info">';
    html += '<h3>' + fechaFormateada.charAt(0).toUpperCase() + fechaFormateada.slice(1) + '</h3>';
    html += '<p>Asignaciones del dia</p></div>';
    html += '<div class="day-stats"><span class="stat-badge">' + totalAsignados + '/' + turnosEsperados + ' turnos asignados</span></div>';
    html += '</div>';

    const NOMBRES_TURNO = { 1: 'Turno 1 \u2014 Ma\u00f1ana', 2: 'Turno 2 \u2014 Tarde', 3: 'Turno 3 \u2014 Noche' };
    const HORAS_TURNO   = { 1: '06:00 - 14:00',    2: '14:00 - 22:00',   3: '22:00 - 06:00'  };
    const COLOR_TURNO   = { 1: '#0c5460', 2: '#856404', 3: '#e0e0e0' };
    const BG_TURNO      = { 1: '#d1ecf1', 2: '#fff3cd', 3: '#1a1a2e' };
    const BORDER_TURNO  = { 1: '#0d6efd', 2: '#e65100', 3: '#6a1b9a' };

    const CONFIG_ESP = {
        'L':    { bg: '#cce5ff', color: '#004085', border: '#0d6efd' },
        'ADMM': { bg: '#fde8d8', color: '#7d3800', border: '#fd7e14' },
        'ADMT': { bg: '#fde8d8', color: '#7d3800', border: '#fd7e14' },
        'ADM':  { bg: '#fde8d8', color: '#7d3800', border: '#fd7e14' }
    };

    // Puestos L4: puesto_codigo → { turno que aplica, turno_id del L4 }
    const PUESTOS_L4 = { 'F5': {turno:1, id:9}, 'F15': {turno:1, id:9}, 'D2': {turno:2, id:10}, 'D1': {turno:2, id:10}, 'F11': {turno:1, id:9} };

    // Puestos que SOLO operan en T3 (no aparecen en T1/T2)
    // Puestos que operan en T3 ADEMÁS de T1/T2 (D3, F6, F11 trabajan los 3 turnos)

    // Estructura fija de puestos por área
    // nocturno:true = aparece en T3 | soloNocturno:true = SOLO aparece en T3
    // nocturno:true = también aparece en T3
    // soloNocturno NO existe — todos los puestos trabajan T1, T2 y T3 (terminal 24/7)
    // El T3 solo tiene un subconjunto de puestos activos
    const PUESTOS_T3 = new Set(['V1','V2','C','D3','F6','F11']);

    const ESTRUCTURA = [
        { area: 'DELTA',       puestos: [{cod:'D1',id:1},{cod:'D2',id:2},{cod:'D3',id:3},{cod:'D4',id:4}] },
        { area: 'FOX',         puestos: [{cod:'F4',id:5},{cod:'F5',id:6},{cod:'F6',id:7},{cod:'F11',id:8},{cod:'F14',id:9},{cod:'F15',id:10}] },
        { area: 'VIGIA',       puestos: [{cod:'V1',id:11},{cod:'V2',id:12}] },
        { area: 'TASA DE USO', puestos: [{cod:'C',id:14}] },
        { area: 'EQUIPAJES',   puestos: [{cod:'G',id:16}] }
    ];

    // Indexar turnos asignados por puesto_id + numero_turno_normalizado
    const idxTurnos = {}; // "puestoId_numNorm" → turno
    turnosFiltrados.forEach(t => {
        if (t.tipo_especial) return;
        let n = Number(t.numero_turno);
        if ([4,9].includes(n))  n = 1;
        if ([5,10].includes(n)) n = 2;
        const pid = t.puesto_id || t.puesto_trabajo_id;
        idxTurnos[pid + '_' + n] = t;
    });

    const especiales = turnosFiltrados.filter(t => t.tipo_especial);

    // Determinar qué turnos mostrar
    const numerosAMostrar = filtroTurno ? [Number(filtroTurno)] : [1, 2, 3];

    numerosAMostrar.forEach(num => {
        const nombre      = NOMBRES_TURNO[num] || 'Turno ' + num;
        const horario     = HORAS_TURNO[num] || '';
        const borderColor = BORDER_TURNO[num] || '#dee2e6';

        // Contar asignados en este turno
        let asignadosEste = 0;
        ESTRUCTURA.forEach(areaConf => {
            areaConf.puestos.forEach(p => {
                if (num === 3 && !PUESTOS_T3.has(p.cod)) return;
                // T1/T2 muestran todos los puestos
                if (idxTurnos[p.id + '_' + num]) asignadosEste++;
            });
        });

        // Especiales de este turno
        const especialesEste = especiales.filter(t => {
            if (t.tipo_especial === 'ADM')  return num <= 2;
            if (t.tipo_especial === 'ADMM' || t.tipo_especial === 'L') return num === 1;
            if (t.tipo_especial === 'ADMT') return num === 2;
            return false;
        });

        html += '<div style="margin:1.5rem 0;">';

        // Cabecera del turno
        html += '<div style="display:flex;align-items:center;gap:1rem;padding:0.8rem 1.2rem;';
        html += 'background:linear-gradient(135deg,' + (num===1?'#0c5460,#17a2b8':num===2?'#856404,#ffc107':'#3a1060,#6a1b9a') + ');';
        html += 'border-radius:8px 8px 0 0;color:white;">';
        html += '<span style="font-size:1.4rem;">' + (num===1?'\uD83C\uDF05':num===2?'\uD83C\uDF07':'\uD83C\uDF19') + '</span>';
        html += '<div style="flex:1;"><strong style="font-size:1rem;letter-spacing:0.5px;">' + nombre + '</strong>';
        html += '<span style="margin-left:1rem;opacity:0.85;font-size:0.88rem;">' + horario + '</span></div>';
        html += '<span style="background:rgba(255,255,255,0.2);padding:4px 14px;border-radius:20px;font-size:0.85rem;font-weight:600;">' + asignadosEste + ' asignados</span>';
        html += '</div>';

        // Tabla
        html += '<div style="overflow-x:auto;border:1px solid #dee2e6;border-top:none;border-radius:0 0 8px 8px;background:white;">';
        html += '<table style="width:100%;border-collapse:collapse;font-size:0.88rem;">';

        // Encabezado de áreas — siempre mostrar todas las que tienen puestos en este turno
        const areasVisibles = ESTRUCTURA.filter(areaConf =>
            areaConf.puestos.some(p => {
                if (num === 3 && !PUESTOS_T3.has(p.cod)) return false;
                return true;
            })
        );

        html += '<thead><tr style="background:#f8f9fa;">';
        html += '<th style="padding:8px 12px;text-align:left;border-right:2px solid #dee2e6;color:#495057;font-size:0.8rem;width:130px;">CÓDIGO</th>';
        areasVisibles.forEach(areaConf => {
            html += '<th style="padding:8px 10px;text-align:left;border-right:1px solid #dee2e6;color:#6c757d;font-size:0.78rem;font-weight:700;letter-spacing:0.5px;white-space:nowrap;">';
            html += '<i class="fas fa-map-marker-alt" style="margin-right:4px;color:' + borderColor + ';"></i>' + areaConf.area;
            html += '</th>';
        });
        if (especialesEste.length > 0) {
            html += '<th style="padding:8px 10px;text-align:left;color:#6c757d;font-size:0.78rem;font-weight:700;letter-spacing:0.5px;">';
            html += '<i class="fas fa-star" style="margin-right:4px;color:#fd7e14;"></i>ESPECIALES';
            html += '</th>';
        }
        html += '</tr></thead><tbody>';

        // Calcular max filas: máximo de puestos por área visible en este turno
        const maxFilas = Math.max(
            ...areasVisibles.map(areaConf =>
                areaConf.puestos.filter(p => {
                    if (num === 3 && !PUESTOS_T3.has(p.cod)) return false;
                    return true;
                }).length
            ),
            especialesEste.length,
            1
        );

        for (let fi = 0; fi < maxFilas; fi++) {
            const esPar = fi % 2 === 0;
            html += '<tr style="background:' + (esPar ? '#fff' : '#f8f9fa') + ';border-top:1px solid #e9ecef;">';

            // Columna código T1/T2/T3 (solo primera fila)
            html += '<td style="padding:8px 12px;border-right:2px solid #dee2e6;vertical-align:middle;">';
            if (fi === 0) {
                html += '<span style="background:' + (BG_TURNO[num]||'#e9ecef') + ';color:' + (COLOR_TURNO[num]||'#495057') + ';';
                html += 'padding:3px 10px;border-radius:12px;font-size:0.8rem;font-weight:700;">T' + num + '</span>';
            }
            html += '</td>';

            // Columnas por área
            areasVisibles.forEach(areaConf => {
                const puestosDelTurno = areaConf.puestos.filter(p => {
                    if (num === 3 && !PUESTOS_T3.has(p.cod)) return false;
                    return true;
                });
                const p = puestosDelTurno[fi]; // puesto en esta fila

                html += '<td style="padding:7px 10px;border-right:1px solid #e9ecef;vertical-align:middle;">';

                if (!p) {
                    // Celda vacía de relleno
                    html += '</td>';
                    return;
                }

                const t       = idxTurnos[p.id + '_' + num];
                const l4cfg   = PUESTOS_L4[p.cod];
                const tieneL4 = !!(l4cfg && l4cfg.turno === num);
                const l4param = tieneL4 ? `,true,${l4cfg.id}` : ',false,null';

                if (t) {
                    // Celda CON asignación
                    const origNum = Number(t.numero_turno) || 0;
                    const esL4    = [4,5,9,10].includes(origNum);
                    const numNorm = [4,9].includes(origNum) ? 1 : [5,10].includes(origNum) ? 2 : origNum;
                    const codigo  = esL4
                        ? String(numNorm) + p.cod + 'L4'
                        : String(origNum) + p.cod;
                    const f       = (t.fecha||fecha||'').replace(/'/g, "\\'");
                    const npStyle = t.estado === 'no_presentado';

                    html += '<div style="display:flex;align-items:center;gap:6px;">';
                    html += '<span style="background:' + (npStyle?'#dc3545':(BG_TURNO[num]||'#e9ecef')) + ';color:' + (npStyle?'white':(COLOR_TURNO[num]||'#333')) + ';padding:2px 8px;border-radius:4px;font-weight:800;font-size:0.82rem;font-family:monospace;white-space:nowrap;">' + (npStyle?'TNR':codigo) + '</span>';
                    html += '<span style="color:' + (npStyle?'#dc3545':'#495057') + ';font-size:0.85rem;flex:1;' + (npStyle?'text-decoration:line-through;opacity:0.7;':'') + '">' + (t.trabajador||'') + '</span>';
                    if (npStyle) html += '<span style="background:#dc3545;color:white;font-size:0.75rem;padding:2px 7px;border-radius:4px;font-weight:800;">TNR</span>';
                    html += '<button style="padding:2px 6px;border:none;background:transparent;cursor:pointer;" title="Editar"';
                    html += ' onclick="editarAsignacion(' + (t.id||0) + ',' + p.id + ",'" + f + "'," + origNum + ',' + (t.trabajador_id||'null') + ",'" + p.cod + "'" + l4param + ')"><i class="fas fa-edit" style="font-size:0.8rem;color:#6c757d;"></i></button>';
                    html += '</div>';
                    const esNP = t.estado === 'no_presentado';
                    html += '<div style="display:flex;gap:3px;margin-top:2px;">';
                    html += '<button style="padding:2px 6px;border:none;background:transparent;cursor:pointer;" title="Eliminar" data-turno-id="' + (t.id||0) + '" data-trabajador="' + (t.trabajador||'') + '" data-fecha="' + f + '" onclick="eliminarTurnoBtn(this)"><i class="fas fa-trash-alt" style="font-size:0.8rem;color:#dc3545;"></i></button>';
                    html += '<button style="padding:2px 8px;border:none;border-radius:4px;font-size:0.75rem;cursor:pointer;background:' + (esNP?'#dc3545':'#f8f9fa') + ';color:' + (esNP?'white':'#6c757d') + ';border:1px solid ' + (esNP?'#dc3545':'#dee2e6') + ';" data-turno-id="' + (t.id||0) + '" data-trabajador="' + (t.trabajador||'') + '" data-estado="' + (t.estado||'programado') + '" onclick="toggleNoPresentado(this)">';
                    html += esNP ? '<i class="fas fa-times-circle"></i> TNR' : '<i class="fas fa-user-times"></i> TNR';
                    html += '</button></div>';

                } else {
                    // Celda VACÍA — clickeable para asignar
                    const codBadge = String(num) + p.cod + (tieneL4 ? '' : '');
                    html += '<div style="display:flex;align-items:center;gap:6px;cursor:pointer;opacity:0.55;" '
                          + 'onclick="editarAsignacion(0,' + p.id + ",'" + fecha + "'," + num + ',null,' + "'"+p.cod+"'" + l4param + ')">';
                    html += '<span style="background:' + (BG_TURNO[num]||'#e9ecef') + ';color:' + (COLOR_TURNO[num]||'#333') + ';padding:2px 8px;border-radius:4px;font-weight:800;font-size:0.82rem;font-family:monospace;white-space:nowrap;border:1px dashed;">' + codBadge + '</span>';
                    html += '<span style="font-size:0.82rem;color:#adb5bd;">Sin asignar</span>';
                    html += '<span style="font-size:0.78rem;color:#0d6efd;margin-left:auto;">+ Asignar</span>';
                    html += '</div>';
                }

                html += '</td>';
            });

            // Columna especiales
            if (especialesEste.length > 0) {
                const esp = especialesEste[fi];
                html += '<td style="padding:7px 10px;vertical-align:middle;">';
                if (esp) {
                    const cfg = CONFIG_ESP[esp.tipo_especial] || { bg:'#e9ecef', color:'#495057', border:'#6c757d' };
                    const f = (esp.fecha||fecha||'').replace(/'/g, "\\'");
                    html += '<div style="display:flex;align-items:center;gap:6px;">';
                    const esAutoLibre = esp.tipo_especial === 'L' && (esp.descripcion||'').startsWith('AUTO:');
                    html += '<span style="background:' + cfg.bg + ';color:' + cfg.color + ';padding:2px 8px;border-radius:4px;font-weight:700;font-size:0.82rem;border:1px solid ' + cfg.border + ';white-space:nowrap;">' + esp.tipo_especial + '</span>';
                    if (esAutoLibre) html += '<span style="background:#fd7e14;color:white;font-size:0.68rem;padding:1px 5px;border-radius:3px;font-weight:700;margin-left:3px;">AUTO</span>';
                    html += '<span style="color:#495057;font-size:0.85rem;flex:1;">' + (esp.trabajador||'') + '</span>';
                    html += '<button style="padding:2px 6px;border:none;background:transparent;cursor:pointer;" title="Editar"';
                    html += ' onclick="editarAsignacion(' + (esp.id||0) + ',null,' + "'" + f + "'" + ',null,' + (esp.trabajador_id||'null') + ",'')><i class=\"fas fa-edit\" style=\"font-size:0.8rem;color:#6c757d;\"></i></button>";
                    html += '</div>';
                    const esNPesp = esp.estado === 'no_presentado';
                    html += '<div style="display:flex;gap:3px;margin-top:2px;">';
                    html += '<button style="padding:2px 6px;border:none;background:transparent;cursor:pointer;" title="Eliminar" data-turno-id="' + (esp.id||0) + '" data-trabajador="' + (esp.trabajador||'') + '" data-fecha="' + f + '" onclick="eliminarTurnoBtn(this)"><i class="fas fa-trash-alt" style="font-size:0.8rem;color:#dc3545;"></i></button>';
                    html += '<button style="padding:2px 8px;border:none;border-radius:4px;font-size:0.75rem;cursor:pointer;background:' + (esNPesp?'#dc3545':'#f8f9fa') + ';color:' + (esNPesp?'white':'#6c757d') + ';border:1px solid ' + (esNPesp?'#dc3545':'#dee2e6') + ';" data-turno-id="' + (esp.id||0) + '" data-trabajador="' + (esp.trabajador||'') + '" data-estado="' + (esp.estado||'programado') + '" onclick="toggleNoPresentado(this)">';
                    html += esNPesp ? '<i class="fas fa-times-circle"></i> TNR' : '<i class="fas fa-user-times"></i> TNR';
                    html += '</button></div>';
                }
                html += '</td>';
            }

            html += '</tr>';
        }

        html += '</tbody></table></div>';

        // ── Sección ADM / ADMM / ADMT ──────────────────────────
        const admsEste = admList.filter(e => {
            if (e.tipo === 'ADMM') return num === 1;
            if (e.tipo === 'ADMT') return num === 2;
            if (e.tipo === 'ADM')  return num <= 2;
            return false;
        });

        if (admsEste.length > 0) {
            html += '<div style="margin-top:6px;padding:8px 12px;background:#e8f4fd;border:1px solid #b8daff;border-radius:0 0 8px 8px;border-top:2px dashed #b8daff;">';
            html += '<div style="font-size:0.75rem;font-weight:700;color:#004085;margin-bottom:6px;letter-spacing:0.4px;">';
            html += '<i class="fas fa-user-clock" style="margin-right:5px;"></i>DISPONIBLES ADM</div>';
            html += '<div style="display:flex;flex-wrap:wrap;gap:6px;">';
            admsEste.forEach(e => {
                const colorTipo = e.tipo === 'ADMM' ? '#0c5460' : e.tipo === 'ADMT' ? '#856404' : '#004085';
                const bgTipo    = e.tipo === 'ADMM' ? '#d1ecf1' : e.tipo === 'ADMT' ? '#fff3cd' : '#cce5ff';
                html += '<div style="display:flex;align-items:center;gap:5px;background:white;border:1px solid #b8daff;border-radius:6px;padding:4px 10px;">';
                html += '<span style="background:' + bgTipo + ';color:' + colorTipo + ';padding:1px 6px;border-radius:3px;font-size:0.75rem;font-weight:700;">' + e.tipo + '</span>';
                html += '<span style="font-size:0.83rem;color:#212529;font-weight:600;">' + (e.trabajador_nombre || e.trabajador || '') + '</span>';
                html += '</div>';
            });
            html += '</div></div>';
        }

        html += '</div>';
        });

    // ── Sección de asignación de supervisores (formulario simple) ──────────────────────────────────
    html += '<div style="margin:1.5rem 0 0.5rem;border:1px solid #d8b4fe;border-radius:10px;overflow:hidden;background:white;">';
    html += '<div style="background:linear-gradient(135deg,#6a1b9a,#9c27b0);padding:12px 16px;display:flex;align-items:center;gap:10px;">';
    html += '<span style="font-size:1.2rem;">🟣</span>';
    html += '<strong style="color:white;font-size:0.95rem;letter-spacing:0.5px;">SUPERVISORES</strong>';
    html += '<span style="background:rgba(255,255,255,0.2);color:white;padding:2px 12px;border-radius:20px;font-size:0.82rem;margin-left:auto;">' + supervisores.length + ' en turno</span>';
    html += '</div>';
    
    // Formulario para agregar supervisor
    html += '<div style="padding:12px 16px;border-bottom:1px solid #e9d5ff;background:#fdf4ff;">';
    html += '<div style="display:grid;grid-template-columns:1fr auto auto auto;gap:8px;align-items:end;">';
    html += '<div><label style="display:block;font-size:0.78rem;font-weight:600;color:#6a1b9a;margin-bottom:4px;">Supervisor</label>';
    html += '<select id="sel-supervisor-diaria" style="width:100%;padding:7px 10px;border:1px solid #d8b4fe;border-radius:6px;font-size:0.85rem;background:white;"><option value="">Seleccionar...</option></select></div>';
    html += '<div><label style="display:block;font-size:0.78rem;font-weight:600;color:#6a1b9a;margin-bottom:4px;">Entrada</label>';
    html += '<input type="time" id="sup-hora-inicio-diaria" step="60" style="padding:7px 8px;border:1px solid #d8b4fe;border-radius:6px;font-size:0.85rem;"></div>';
    html += '<div><label style="display:block;font-size:0.78rem;font-weight:600;color:#6a1b9a;margin-bottom:4px;">Salida</label>';
    html += '<input type="time" id="sup-hora-fin-diaria" step="60" style="padding:7px 8px;border:1px solid #d8b4fe;border-radius:6px;font-size:0.85rem;"></div>';
    html += '<button id="btn-agregar-supervisor-diaria" onclick="agregarSupervisorDiaria(\'' + fecha + '\')" style="background:#9c27b0;color:white;border:none;border-radius:6px;padding:6px 12px;font-size:0.85rem;cursor:pointer;font-weight:600;">+ Agregar</button>';
    html += '</div>';
    html += '</div>';
    
    // Lista de supervisores asignados
    html += '<div style="padding:8px 4px;background:white;">';
    if (supervisores.length === 0) {
        html += '<div style="padding:12px;text-align:center;color:#6c757d;font-size:0.9rem;">Sin supervisores asignados para este día</div>';
    } else {
        html += '<table style="width:100%;border-collapse:collapse;font-size:0.88rem;">';
        html += '<thead><tr style="background:#f9f5ff;">';
        html += '<th style="padding:7px 14px;text-align:left;color:#6a1b9a;font-size:0.78rem;border-bottom:1px solid #e9d5ff;">Supervisor</th>';
        html += '<th style="padding:7px 14px;text-align:center;color:#6a1b9a;font-size:0.78rem;border-bottom:1px solid #e9d5ff;width:90px;">Entrada</th>';
        html += '<th style="padding:7px 14px;text-align:center;color:#6a1b9a;font-size:0.78rem;border-bottom:1px solid #e9d5ff;width:90px;">Salida</th>';
        html += '<th style="padding:7px 14px;text-align:center;color:#6a1b9a;font-size:0.78rem;border-bottom:1px solid #e9d5ff;width:80px;">Duración</th>';
        html += '<th style="padding:7px 10px;border-bottom:1px solid #e9d5ff;width:70px;text-align:center;"></th>';
        html += '</tr></thead><tbody>';

        supervisores.forEach((s, i) => {
            const bg = i % 2 === 0 ? '#fff' : '#fdf4ff';
            const [h1,m1] = s.hora_inicio.substring(0,5).split(':').map(Number);
            const [h2,m2] = s.hora_fin.substring(0,5).split(':').map(Number);
            let mins = (h2*60+m2) - (h1*60+m1);
            if (mins < 0) mins += 1440;
            const durStr = Math.floor(mins/60) + 'h' + (mins%60 > 0 ? ' ' + mins%60 + 'min' : '');
            const f = fecha.replace(/'/g,"\'");
            html += '<tr style="background:' + bg + ';border-top:1px solid #f3e8ff;">';
            html += '<td style="padding:8px 14px;font-weight:600;color:#3b0764;">' + (s.trabajador||s.nombre||'') + '</td>';
            html += '<td style="padding:8px 14px;font-family:monospace;font-size:0.9rem;color:#6a1b9a;font-weight:700;text-align:center;">' + s.hora_inicio.substring(0,5) + '</td>';
            html += '<td style="padding:8px 14px;font-family:monospace;font-size:0.9rem;color:#6a1b9a;font-weight:700;text-align:center;">' + s.hora_fin.substring(0,5) + '</td>';
            html += '<td style="padding:8px 14px;color:#7c3aed;font-size:0.84rem;text-align:center;">' + durStr + '</td>';
            html += '<td style="padding:8px 10px;text-align:center;">';
            html += '<button style="padding:3px 6px;border:none;background:transparent;cursor:pointer;" title="Editar" onclick="editarSupervisorTurno(' + s.id + ')"><i class="fas fa-edit" style="color:#9c27b0;font-size:0.82rem;"></i></button>';
            const nomEsc = (s.trabajador||'').replace(/'/g,'&#39;');
            html += '<button style="padding:3px 6px;border:none;background:transparent;cursor:pointer;" title="Eliminar" onclick="eliminarSupervisorTurno(' + s.id + ',\'' + nomEsc + '\',\'' + f + '\')"><i class="fas fa-trash-alt" style="color:#dc3545;font-size:0.82rem;"></i></button>';
            html += '</td>';
            html += '</tr>';
        });

        html += '</tbody></table>';
    }
    html += '</div></div>';

    container.innerHTML = html;
    
    // Cargar supervisores disponibles en el select
    cargarSupervisoresDisponiblesDiaria(fecha);
}



// ─── VISTA MENSUAL GRILLA ──────────────────────────────────

let vistaActual = 'diaria';
let mesGrilla   = new Date().getMonth();
let anioGrilla  = new Date().getFullYear();

function cambiarVista(vista) {
    vistaActual = vista;
    const esDiaria = vista === 'diaria';

    document.getElementById('calendario-vista-diaria').style.display  = esDiaria ? '' : 'none';
    const vistaMensual = document.getElementById('calendario-vista-mensual');
    vistaMensual.style.display = esDiaria ? 'none' : 'flex';
    vistaMensual.classList.toggle('activo', !esDiaria);

    const btnDia = document.getElementById('btn-vista-diaria');
    const btnMes = document.getElementById('btn-vista-mensual');
    btnDia.style.background = esDiaria ? 'var(--terminal)' : '#fff';
    btnDia.style.color      = esDiaria ? 'white' : '#6c757d';
    btnMes.style.background = esDiaria ? '#fff' : 'var(--terminal)';
    btnMes.style.color      = esDiaria ? '#6c757d' : 'white';

    // Filtros de turno/área solo aplican a vista diaria
    document.getElementById('filtro-turno-calendario').style.display = esDiaria ? '' : 'none';
    document.getElementById('filtro-area-calendario').style.display  = esDiaria ? '' : 'none';

    // Panel de novedades solo en vista diaria
    const panelNovedades = document.getElementById('panel-novedades');
    if (panelNovedades) panelNovedades.style.display = esDiaria ? '' : 'none';

    if (!esDiaria) cargarGrillaMensual();
}

// ─── FILTRO DE GRILLA MENSUAL ────────────────────────────────────────────────
function filtrarGrilla(texto) {
    const val = texto.trim().toLowerCase();
    const filas = document.querySelectorAll('#grilla-mensual table tbody tr');
    const btnClear = document.getElementById('filtro-clear');
    const contador = document.getElementById('filtro-contador');
    let visibles = 0;

    filas.forEach(fila => {
        const nombre = (fila.getAttribute('data-nombre') || '').toLowerCase();
        const coincide = !val || nombre.includes(val);
        fila.style.display = coincide ? '' : 'none';
        if (coincide) visibles++;
    });

    // Mostrar/ocultar botón limpiar
    if (btnClear) btnClear.style.display = val ? 'block' : 'none';

    // Mostrar contador solo cuando hay filtro activo
    if (contador) {
        if (val) {
            contador.textContent = visibles + ' resultado' + (visibles !== 1 ? 's' : '');
            contador.style.display = 'inline';
        } else {
            contador.style.display = 'none';
        }
    }
}

function limpiarFiltroGrilla() {
    const input = document.getElementById('filtro-grilla');
    if (input) { input.value = ''; filtrarGrilla(''); input.focus(); }
}

function cambiarMesGrilla(dir) {
    // Limpiar filtro al cambiar de mes
    const inputFiltro = document.getElementById('filtro-grilla');
    if (inputFiltro && inputFiltro.value) { inputFiltro.value = ''; }
    const contador = document.getElementById('filtro-contador');
    if (contador) contador.style.display = 'none';
    const btnClear = document.getElementById('filtro-clear');
    if (btnClear) btnClear.style.display = 'none';
    mesGrilla += dir;
    if (mesGrilla > 11) { mesGrilla = 0; anioGrilla++; }
    if (mesGrilla < 0)  { mesGrilla = 11; anioGrilla--; }
    cargarGrillaMensual();
}

async function cargarGrillaMensual() {
    const grilla  = document.getElementById('grilla-mensual');
    const titulo  = document.getElementById('titulo-mes-grilla');
    if (!grilla) return;

    const MESES = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
    titulo.textContent = MESES[mesGrilla] + ' ' + anioGrilla;
    grilla.innerHTML = '<p style="padding:1rem;color:#6c757d;"><i class="fas fa-spinner fa-spin"></i> Cargando...</p>';

    // Calcular primer y último día del mes
    const primerDia = new Date(anioGrilla, mesGrilla, 1);
    const ultimoDia = new Date(anioGrilla, mesGrilla + 1, 0);
    const fechaInicio = primerDia.toISOString().split('T')[0];
    const fechaFin    = ultimoDia.toISOString().split('T')[0];

    // Guardar mes/año para recarga parcial
    window._grillaMes  = mesGrilla + 1; // mesGrilla es 0-indexed
    window._grillaAnio = anioGrilla;

    try {
        const [resTurnos, resTrabajadores, resDiasEsp, resInc, resSup] = await Promise.all([
            fetch(API_BASE + 'turnos.php?fecha_inicio=' + fechaInicio + '&fecha_fin=' + fechaFin),
            fetch(API_BASE + 'trabajadores.php'),
            fetch(API_BASE + 'dias_especiales.php?fecha_inicio=' + fechaInicio + '&fecha_fin=' + fechaFin),
            fetch(API_BASE + 'incapacidades.php?fecha_inicio=' + fechaInicio + '&fecha_fin=' + fechaFin),
            fetch(API_BASE + 'supervisores_turno.php?fecha_inicio=' + fechaInicio + '&fecha_fin=' + fechaFin)
        ]);
        const dataTurnos  = await resTurnos.json();
        const dataTrab    = await resTrabajadores.json();
        const dataDiasEsp = await resDiasEsp.json();
        const dataInc     = await resInc.json();
        const dataSup     = await resSup.json();

        if (!dataTurnos.success || !dataTrab.success) {
            grilla.innerHTML = '<p class="alert alert-danger">Error al cargar datos</p>';
            return;
        }

        const turnos      = dataTurnos.data.filter(t => t.estado !== 'cancelado');
        const trabajadores = dataTrab.data.filter(t => t.activo);

        trabajadores.sort((a, b) => {
            const aSup = String(a.cargo || '').toLowerCase() === 'supervisor';
            const bSup = String(b.cargo || '').toLowerCase() === 'supervisor';
            if (aSup !== bSup) return aSup ? -1 : 1;
            return a.nombre.localeCompare(b.nombre, 'es', { sensitivity: 'base' });
        });

        // Construir índice: turnosPorTrabajadorFecha[trab_id][fecha] = [item, ...]
        const idx = {};

        // Agregar turnos normales
        turnos.forEach(t => {
            const tid = t.trabajador_id;
            const f   = t.fecha;
            if (!idx[tid]) idx[tid] = {};
            if (!idx[tid][f]) idx[tid][f] = [];
            idx[tid][f].push(t);
        });

        // Agregar días especiales (L, VAC, SUS, LC, L8) como entradas con tipo_especial
        if (dataDiasEsp.success && dataDiasEsp.data) {
            dataDiasEsp.data.forEach(de => {
                if (!['programado','activo'].includes(de.estado)) return;
                // Expandir rango fecha_inicio → fecha_fin
                const inicio = new Date(de.fecha_inicio + 'T00:00:00');
                const fin    = de.fecha_fin ? new Date(de.fecha_fin + 'T00:00:00') : inicio;
                for (let d = new Date(inicio); d <= fin; d.setDate(d.getDate() + 1)) {
                    const f   = d.toISOString().split('T')[0];
                    const tid = de.trabajador_id;
                    if (!idx[tid]) idx[tid] = {};
                    if (!idx[tid][f]) idx[tid][f] = [];
                    // Solo agregar si no hay ya un día especial del mismo tipo ese día
                    const yaExiste = idx[tid][f].some(x => x.tipo_especial === de.tipo);
                    if (!yaExiste) {
                        idx[tid][f].push({
                            id:            de.id || null,
                            tipo_especial: de.tipo,
                            descripcion:   de.descripcion || '',
                            estado:        de.estado,
                            trabajador_id: de.trabajador_id
                        });
                    }
                }
            });
        }

        // Agregar incapacidades al índice
        if (dataInc.success && dataInc.data) {
            dataInc.data.forEach(inc => {
                if (inc.estado !== 'activa') return;
                const inicio = new Date(inc.fecha_inicio + 'T00:00:00');
                const fin    = new Date(inc.fecha_fin    + 'T00:00:00');
                const tid    = inc.trabajador_id;
                for (let d = new Date(inicio); d <= fin; d.setDate(d.getDate() + 1)) {
                    const f = d.toISOString().split('T')[0];
                    if (!idx[tid]) idx[tid] = {};
                    if (!idx[tid][f]) idx[tid][f] = [];
                    const yaExiste = idx[tid][f].some(x => x.tipo_especial === 'INC');
                    if (!yaExiste) {
                        idx[tid][f].push({
                            tipo_especial: 'INC',
                            descripcion:   inc.tipo_incapacidad || 'Incapacidad',
                            estado:        'activa',
                            trabajador_id: tid
                        });
                    }
                }
            });
        }

        // Agregar turnos de supervisores al índice
        if (dataSup.success && dataSup.data) {
            dataSup.data.forEach(s => {
                const tid = s.trabajador_id;
                const f   = s.fecha;
                if (!idx[tid]) idx[tid] = {};
                if (!idx[tid][f]) idx[tid][f] = [];
                const yaExiste = idx[tid][f].some(x => x.tipo_especial === 'SUP');
                if (!yaExiste) {
                    idx[tid][f].push({
                        id:            s.id,
                        tipo_especial: 'SUP',
                        descripcion:   s.hora_inicio.substring(0,5) + '→' + s.hora_fin.substring(0,5),
                        estado:        'programado',
                        trabajador_id: tid,
                        hora_inicio:   s.hora_inicio,
                        hora_fin:      s.hora_fin
                    });
                }
            });
        }

        // Generar días del mes
        const dias = [];
        for (let d = 1; d <= ultimoDia.getDate(); d++) {
            const dt = new Date(anioGrilla, mesGrilla, d);
            dias.push({
                num: d,
                fecha: dt.toISOString().split('T')[0],
                diaSemana: dt.toLocaleDateString('es-CO', { weekday: 'short' }).toUpperCase()
            });
        }

        // Construir tabla
        let html = '<table style="border-collapse:collapse; font-size:0.78rem; width:100%; min-width:' + (200 + dias.length * 52) + 'px;">';

        // Encabezado con días
        html += '<thead><tr>';
        html += '<th style="position:sticky;left:0;z-index:2;background:var(--terminal);color:white;padding:8px 12px;text-align:left;min-width:180px;border-right:2px solid #027433;">Trabajador</th>';
        dias.forEach(d => {
            const hoy = new Date().toISOString().split('T')[0];
            const esHoy = d.fecha === hoy;
            const esFin = d.diaSemana === 'SÁB' || d.diaSemana === 'DOM' || d.diaSemana === 'SAB';
            const bg = esHoy ? '#fff176' : esFin ? '#c8e6c9' : 'var(--terminal)';
            const col = esHoy ? '#333' : esFin ? '#1b5e20' : 'white';
            html += '<th style="background:' + bg + ';color:' + col + ';padding:5px 3px;text-align:center;min-width:50px;border-right:1px solid rgba(255,255,255,0.2);">';
            html += '<div style="font-weight:700;">' + d.num + '</div>';
            html += '<div style="font-size:0.7rem;opacity:0.85;">' + d.diaSemana + '</div>';
            html += '</th>';
        });
        html += '</tr></thead><tbody>';

        // Filas por trabajador
        trabajadores.forEach((trab, i) => {
            const bg = i % 2 === 0 ? '#fff' : '#f8f9fa';
            const cargoBadge = String(trab.cargo || '').toLowerCase() === 'supervisor'
                ? ' <span style="display:inline-block;margin-left:6px;padding:2px 8px;border-radius:999px;background:#f3e8ff;color:#6b21a8;font-size:0.72rem;font-weight:700;letter-spacing:0.2px;">SUP</span>'
                : '';
            html += '<tr data-trab-id="' + trab.id + '" data-nombre="' + trab.nombre.toLowerCase() + '" style="background:' + bg + ';">';
            html += '<td style="position:sticky;left:0;z-index:1;background:' + bg + ';padding:6px 10px;font-weight:600;border-right:2px solid #dee2e6;white-space:nowrap;">' + trab.nombre + cargoBadge + '</td>';

            dias.forEach(d => {
                const asigs = (idx[trab.id] && idx[trab.id][d.fecha]) ? idx[trab.id][d.fecha] : [];
                const esFin = d.diaSemana === 'SÁB' || d.diaSemana === 'DOM' || d.diaSemana === 'SAB';
                const borderFin = esFin ? 'border-right:2px solid #a5d6a7;' : 'border-right:1px solid #e9ecef;';
                // Serializar asigs para el onclick (solo campos necesarios)
                const asigsSafe = JSON.stringify(asigs.map(a => ({
                    id: a.id||null, tipo_especial: a.tipo_especial||null,
                    descripcion: a.descripcion||'', estado: a.estado||'',
                    numero_turno: a.numero_turno||null, puesto_codigo: a.puesto_codigo||null,
                    hora_inicio: a.hora_inicio||null, hora_fin: a.hora_fin||null
                }))).replace(/'/g, '&#39;').replace(/"/g, '&quot;');
                const nombreSafe = trab.nombre.replace(/'/g, '&#39;');
                const cargoSafeAttr = (trab.cargo || '').replace(/'/g, '&#39;');
                // onclick lee data-asigs en tiempo real para siempre tener datos frescos
                html += '<td onclick="abrirPopoverMensualDesdecelda(this)" '
                      + 'data-trab-id-cel="' + trab.id + '" '
                      + 'data-fecha="' + d.fecha + '" data-nombre="' + nombreSafe + '" '
                      + 'data-cargo="' + cargoSafeAttr + '" '
                      + 'data-asigs="' + asigsSafe + '" '
                      + 'style="padding:3px;text-align:center;vertical-align:middle;cursor:pointer;' + borderFin + '"'
                      + ' onmouseenter="this.style.outline=\'2px solid #025B2D\'" onmouseleave="this.style.outline=\'none\'">';

                if (asigs.length === 0) {
                    html += '<span style="color:#ced4da;font-size:0.7rem;">—</span>';
                } else {
                    asigs.forEach(a => {
                        let etiqueta, bg, color;

                        if (a.tipo_especial) {
                            const cfgEsp = {
                                'L':    { bg: '#cce5ff',  color: '#004085' },
                                'L8':   { bg: '#cce5ff',  color: '#004085' },
                                'LC':   { bg: '#b8daff',  color: '#003580' },
                                'VAC':  { bg: '#d4edda',  color: '#155724' },
                                'SUS':  { bg: '#fff3cd',  color: '#856404' },
                                'INC':  { bg: '#f8d7da',  color: '#721c24' },
                                'ADMM': { bg: '#fde8d8',  color: '#7d3800' },
                                'ADMT': { bg: '#fde8d8',  color: '#7d3800' },
                                'ADM':  { bg: '#fde8d8',  color: '#7d3800' },
                                'SUP':  { bg: '#f3e8ff',  color: '#6b21a8' }
                            }[a.tipo_especial] || { bg: '#e9ecef', color: '#495057' };
                            const esAutoL = a.tipo_especial === 'L' && (a.descripcion||'').startsWith('AUTO:');
                            const esSup   = a.tipo_especial === 'SUP';
                            const tooltip_extra = a.descripcion ? ' — ' + a.descripcion : '';
                            // Para SUP mostrar las horas en lugar del código
                            etiqueta = esSup ? (a.descripcion || 'SUP') : esAutoL ? 'L⚡' : a.tipo_especial;
                            bg    = esAutoL ? '#fd7e14' : cfgEsp.bg;
                            color = esAutoL ? 'white'   : cfgEsp.color;
                        } else {
                            const origNum = Number(a.numero_turno) || 0;
                            let num = origNum;
                            if (origNum === 4) num = 1;
                            else if (origNum === 5) num = 2;

                            if (origNum >= 4) {
                                etiqueta = String(num) + (a.puesto_codigo || '') + 'L4';
                            } else {
                                etiqueta = String(num) + (a.puesto_codigo || '');
                            }

                            bg    = num === 1 ? '#d1ecf1' : num === 2 ? '#fff3cd' : '#1a1a2e';
                            color = num === 1 ? '#0c5460' : num === 2 ? '#856404' : '#e0e0e0';
                        }

                        const tooltip = (a.trabajador || '') + ' · ' + (a.puesto_nombre || a.tipo_especial || '') + ' · ' + (a.turno_nombre || a.tipo_especial || '') + (typeof tooltip_extra !== 'undefined' ? tooltip_extra : '');
                        tooltip_extra = undefined; // reset
                        const esAusente = a.estado === 'no_presentado';
                        if (esAusente) { bg = '#dc3545'; color = 'white'; }
                        html += '<span title="' + tooltip + (esAusente?' — TNR: Turno No Realizado':'') + '" ';
                        html += 'style="display:inline-block;background:' + bg + ';color:' + color + ';';
                        html += 'padding:2px 5px;border-radius:4px;font-weight:700;font-size:0.72rem;margin:1px;white-space:nowrap;' + (esAusente ? 'letter-spacing:1px;font-weight:800;' : '') + '">';
                        html += esAusente ? 'TNR' : etiqueta;
                    });
                }
                html += '</td>';
            });
            html += '</tr>';
        });

        html += '</tbody></table>';

        // Leyenda
        html += '<div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:12px;padding:0 4px;">';
        const leyenda = [
            {label:'T1 - Mañana', bg:'#d1ecf1', color:'#0c5460'},
            {label:'T2 - Tarde',  bg:'#fff3cd', color:'#856404'},
            {label:'T3 - Noche',  bg:'#1a1a2e', color:'#e0e0e0'},
            {label:'L4',          bg:'#e2d9f3', color:'#6f42c1'},
            {label:'L - Libre',   bg:'#cce5ff', color:'#004085'},
            {label:'ADM',         bg:'#fde8d8', color:'#7d3800'},
            {label:'SUP - Supervisor', bg:'#f3e8ff', color:'#6b21a8'},
        ];
        leyenda.forEach(l => {
            html += '<span style="background:' + l.bg + ';color:' + l.color + ';padding:3px 10px;border-radius:12px;font-size:0.78rem;font-weight:600;">' + l.label + '</span>';
        });
        html += '</div>';

        grilla.innerHTML = html;

    } catch(e) {
        grilla.innerHTML = '<p class="alert alert-danger">Error al cargar la grilla: ' + e.message + '</p>';
        console.error(e);
    }
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
// ─── ESTRUCTURA FIJA DE PUESTOS (compartida con render y exports) ─────────────
const EXPORT_ESTRUCTURA = [
    { area: 'DELTA',       puestos: [{cod:'D1',id:1},{cod:'D2',id:2},{cod:'D3',id:3},{cod:'D4',id:4}] },
    { area: 'FOX',         puestos: [{cod:'F4',id:5},{cod:'F5',id:6},{cod:'F6',id:7},{cod:'F11',id:8},{cod:'F14',id:9},{cod:'F15',id:10}] },
    { area: 'VIGIA',       puestos: [{cod:'V1',id:11},{cod:'V2',id:12}] },
    { area: 'TASA DE USO', puestos: [{cod:'C',id:14}] },
    { area: 'EQUIPAJES',   puestos: [{cod:'G',id:16}] }
];
const EXPORT_PUESTOS_T3  = new Set(['V1','V2','C','D3','F6','F11']);
const EXPORT_PUESTOS_L4  = { 'F5':{turno:1}, 'F15':{turno:1}, 'D2':{turno:2}, 'D1':{turno:2}, 'F11':{turno:1} };

function exportBuildIdx(turnos) {
    // Indexar por puesto_id + numero_turno_normalizado
    const idx = {};
    turnos.filter(t => !t.tipo_especial && t.estado !== 'cancelado').forEach(t => {
        let n = Number(t.numero_turno);
        if ([4,9].includes(n))  n = 1;
        if ([5,10].includes(n)) n = 2;
        const pid = t.puesto_id || t.puesto_trabajo_id;
        idx[pid + '_' + n] = t;
    });
    return idx;
}

// Función auxiliar para calcular duración en horas
function calcularDuracionHoras(horaInicio, horaFin) {
    const [h1, m1] = horaInicio.split(':').map(Number);
    const [h2, m2] = horaFin.split(':').map(Number);
    let minutos = (h2 * 60 + m2) - (h1 * 60 + m1);
    if (minutos < 0) minutos += 1440; // Si cruza medianoche
    const horas = Math.floor(minutos / 60);
    const mins = minutos % 60;
    return horas + 'h' + (mins > 0 ? ' ' + mins + 'm' : '');
}

async function exportarDiaExcel() {
    const fecha = fechaCalendarioActual.toISOString().split('T')[0];
    try {
        mostrarSpinner('Generando Excel...');
        const [rTurnos] = await Promise.all([
            fetch(API_BASE + 'turnos.php?fecha=' + fecha).then(r => r.json())
        ]);

        const turnos  = (rTurnos.success ? rTurnos.data : []).filter(t => t.estado !== 'cancelado');
        const idx     = exportBuildIdx(turnos);
        const especiales = turnos.filter(t => t.tipo_especial);

        const fechaObj = new Date(fecha + 'T00:00:00');
        const fechaStr = fechaObj.toLocaleDateString('es-CO', { weekday:'long', day:'numeric', month:'long', year:'numeric' });

        const wb = XLSX.utils.book_new();

        const FILL_HDR = { fgColor: { rgb: '025B2D' } };
        const FILL_T1H = { fgColor: { rgb: '0C5460' } };
        const FILL_T2H = { fgColor: { rgb: '856404' } };
        const FILL_T3H = { fgColor: { rgb: '3A1060' } };
        const FILL_T1  = { fgColor: { rgb: 'D1ECF1' } };
        const FILL_T2  = { fgColor: { rgb: 'FFF3CD' } };
        const FILL_T3  = { fgColor: { rgb: '2D2D44' } };
        const FILL_VACIO = { fgColor: { rgb: 'F8F9FA' } };
        const border = { top:{style:'thin',color:{rgb:'DEE2E6'}}, bottom:{style:'thin',color:{rgb:'DEE2E6'}}, left:{style:'thin',color:{rgb:'DEE2E6'}}, right:{style:'thin',color:{rgb:'DEE2E6'}} };
        const center = { horizontal:'center', vertical:'center' };
        const left   = { horizontal:'left',   vertical:'center', wrapText:true };

        const ws_data = [];
        const merges  = [];

        ws_data.push(['Terminal de Transportes de Ibagué — Asignación de Turnos','','','','','']);
        merges.push({ s:{r:0,c:0}, e:{r:0,c:5} });
        ws_data.push([fechaStr.charAt(0).toUpperCase() + fechaStr.slice(1),'','','','','']);
        merges.push({ s:{r:1,c:0}, e:{r:1,c:5} });
        ws_data.push(['','','','','','']);
        ws_data.push(['Código','Área','Turno','Trabajador','Cédula','Estado']);

        const TURNOS_CFG = [
            { num:1, nombre:'Turno 1 — Mañana', horario:'06:00 - 14:00', fillH:FILL_T1H, fill:FILL_T1 },
            { num:2, nombre:'Turno 2 — Tarde',  horario:'14:00 - 22:00', fillH:FILL_T2H, fill:FILL_T2 },
            { num:3, nombre:'Turno 3 — Noche',  horario:'22:00 - 06:00', fillH:FILL_T3H, fill:FILL_T3 },
        ];

        TURNOS_CFG.forEach(cfg => {
            const rowSep = ws_data.length;
            // Contar asignados
            let asig = 0;
            EXPORT_ESTRUCTURA.forEach(a => a.puestos.forEach(p => {
                if (cfg.num === 3 && !EXPORT_PUESTOS_T3.has(p.cod)) return;
                if (idx[p.id + '_' + cfg.num]) asig++;
            }));
            ws_data.push([cfg.nombre,'',cfg.horario,'',asig + ' asignados','']);
            merges.push({ s:{r:rowSep,c:0}, e:{r:rowSep,c:1} });
            merges.push({ s:{r:rowSep,c:3}, e:{r:rowSep,c:4} });

            EXPORT_ESTRUCTURA.forEach(areaConf => {
                areaConf.puestos.forEach(p => {
                    if (cfg.num === 3 && !EXPORT_PUESTOS_T3.has(p.cod)) return;
                    const t   = idx[p.id + '_' + cfg.num];
                    const esL4 = t && [4,5,9,10].includes(Number(t.numero_turno));
                    const cod  = esL4 ? String(cfg.num) + p.cod + 'L4' : String(cfg.num) + p.cod;
                    ws_data.push([
                        cod,
                        areaConf.area,
                        cfg.nombre,
                        t ? (t.trabajador || '') : '— Sin asignar —',
                        t ? (t.cedula || '') : '',
                        t ? (t.estado === 'no_presentado' ? 'TNR' : 'Asignado') : 'Vacante'
                    ]);
                });
            });

            // Especiales de este turno
            const espEste = especiales.filter(e => {
                if (e.tipo_especial === 'ADM')  return cfg.num <= 2;
                if (e.tipo_especial === 'ADMM' || e.tipo_especial === 'L') return cfg.num === 1;
                if (e.tipo_especial === 'ADMT') return cfg.num === 2;
                return false;
            });
            espEste.forEach(e => {
                ws_data.push([e.tipo_especial, 'ESPECIAL', cfg.nombre, e.trabajador || '', 'Especial']);
            });
        });

        const ws = XLSX.utils.aoa_to_sheet(ws_data);
        ws['!merges'] = merges;
        ws['!cols'] = [{ wch:12 },{ wch:14 },{ wch:22 },{ wch:34 },{ wch:10 }];

        ws_data.forEach((row, ri) => {
            ['A','B','C','D','E'].forEach((col, ci) => {
                const addr = col + (ri + 1);
                if (!ws[addr]) ws[addr] = { v:'', t:'s' };
                if (ri === 0) ws[addr].s = { font:{ bold:true, sz:14, name:'Arial', color:{rgb:'FFFFFF'} }, fill:FILL_HDR, alignment:center };
                else if (ri === 1) ws[addr].s = { font:{ sz:11, name:'Arial', color:{rgb:'FFFFFF'} }, fill:FILL_HDR, alignment:center };
                else if (ri === 3) ws[addr].s = { font:{ bold:true, sz:10, name:'Arial', color:{rgb:'FFFFFF'} }, fill:{ fgColor:{rgb:'495057'} }, alignment:center, border };
                else {
                    const val = String(row[0] || '');
                    const esSep = ['Turno 1','Turno 2','Turno 3'].some(t => val.startsWith(t));
                    const esVacante = row[4] === 'Vacante';
                    if (esSep) {
                        const fillH = val.includes('Tarde') ? FILL_T2H : val.includes('Noche') ? FILL_T3H : FILL_T1H;
                        ws[addr].s = { font:{ bold:true, sz:10, name:'Arial', color:{rgb:'FFFFFF'} }, fill:fillH, alignment:ci===0?left:center, border };
                    } else {
                        const num = parseInt(val.charAt(0));
                        const fill = esVacante ? FILL_VACIO : num===1?FILL_T1 : num===2?FILL_T2 : num===3?FILL_T3 : { fgColor:{rgb:'FDE8D8'} };
                        const isDark = num === 3 && !esVacante;
                        ws[addr].s = {
                            font:{ name:'Arial', sz:10, bold:ci===0, color:{rgb: isDark?'E0E0E0': esVacante?'AAAAAA':'212529'}, italic: esVacante },
                            fill, alignment:ci===0?center:left, border
                        };
                    }
                }
            });
        });

        ws['!rows'] = ws_data.map((_,i) => ({ hpt: i===0||i===1?24 : i===3?18 : 16 }));
        XLSX.utils.book_append_sheet(wb, ws, 'Turnos del Día');
        XLSX.writeFile(wb, 'Turnos_' + fechaObj.toLocaleDateString('es-CO').replace(/\//g,'-') + '.xlsx');
        ocultarSpinner();
        mostrarAlerta('✅ Excel exportado correctamente', 'success');
    } catch(err) {
        ocultarSpinner();
        mostrarAlerta('Error al exportar Excel: ' + err.message, 'danger');
    }
}

async function exportarDiaPDF() {
    const fecha = fechaCalendarioActual.toISOString().split('T')[0];
    try {
        mostrarSpinner('Generando PDF...');
        const [rTurnos, rSupervisores, rNovedades] = await Promise.all([
            fetch(API_BASE + 'turnos.php?fecha=' + fecha).then(r => r.json()),
            fetch(API_BASE + 'supervisores_turno.php?fecha=' + fecha).then(r => r.json()),
            fetch(API_BASE + 'novedades.php?fecha=' + fecha).then(r => r.json())
        ]);
        
        const turnos       = (rTurnos.success ? rTurnos.data : []).filter(t => t.estado !== 'cancelado');
        const supervisores = (rSupervisores.success ? rSupervisores.data : []);
        const novedades    = (rNovedades.success ? rNovedades.data : null);
        
        const idx     = exportBuildIdx(turnos);
        const especiales = turnos.filter(t => t.tipo_especial);

        const fechaObj    = new Date(fecha + 'T00:00:00');
        const fechaFmt    = fechaObj.toLocaleDateString('es-CO', { weekday:'long', day:'numeric', month:'long', year:'numeric' });
        const fechaFmtCap = fechaFmt.charAt(0).toUpperCase() + fechaFmt.slice(1);

        const { jsPDF } = window.jspdf;
        const pdf     = new jsPDF('p','mm','a4');
        const PAGE_W  = 210, PAGE_H = 297, MARGIN = 12;
        const CONTENT_W = PAGE_W - MARGIN * 2;
        const VERDE   = [2, 91, 45];
        const VERDE_L = [235, 245, 239]; // verde muy suave para filas alternas

        const TURNOS_CFG = [
            { num:1, nombre:'Turno 1 — Mañana', horario:'06:00 - 14:00' },
            { num:2, nombre:'Turno 2 — Tarde',  horario:'14:00 - 22:00' },
            { num:3, nombre:'Turno 3 — Noche',  horario:'22:00 - 06:00' },
        ];

        let isFirst = true;

        for (const cfg of TURNOS_CFG) {
            const filas = [];
            EXPORT_ESTRUCTURA.forEach(areaConf => {
                areaConf.puestos.forEach(p => {
                    if (cfg.num === 3 && !EXPORT_PUESTOS_T3.has(p.cod)) return;
                    const t    = idx[p.id + '_' + cfg.num];
                    const esNP = t?.estado === 'no_presentado';
                    const esL4 = t && [4,5,9,10].includes(Number(t.numero_turno));
                    const cod  = esL4 ? String(cfg.num)+p.cod+'L4' : String(cfg.num)+p.cod;
                    filas.push({ cod, area: areaConf.area, trabajador: t ? (t.trabajador||'') : '', esNP, esVacante:!t, esEspecial:false });
                });
            });
            const espEste = especiales.filter(e => {
                if (e.tipo_especial === 'ADM')  return cfg.num <= 2;
                if (e.tipo_especial === 'ADMM' || e.tipo_especial === 'L') return cfg.num === 1;
                if (e.tipo_especial === 'ADMT') return cfg.num === 2;
                return false;
            });
            espEste.forEach(e => filas.push({ cod: e.tipo_especial, area:'Especial', trabajador: e.trabajador||'', esNP:false, esVacante:false, esEspecial:true }));

            const asignados = filas.filter(f => !f.esVacante).length;

            if (!isFirst) pdf.addPage();
            isFirst = false;

            // Banda verde superior
            pdf.setFillColor(...VERDE);
            pdf.rect(0, 0, PAGE_W, 22, 'F');
            pdf.setFont('helvetica','bold');
            pdf.setFontSize(13);
            pdf.setTextColor(255,255,255);
            pdf.text('Terminal de Transportes de Ibagué', PAGE_W/2, 10, { align:'center' });
            pdf.setFont('helvetica','normal');
            pdf.setFontSize(9);
            pdf.setTextColor(200, 230, 210);
            pdf.text(fechaFmtCap, PAGE_W/2, 17, { align:'center' });

            // Nombre del turno
            pdf.setFont('helvetica','bold');
            pdf.setFontSize(10);
            pdf.setTextColor(...VERDE);
            pdf.text(cfg.nombre, MARGIN, 32);
            pdf.setFont('helvetica','normal');
            pdf.setFontSize(8);
            pdf.setTextColor(120,120,120);
            pdf.text(cfg.horario + '  ·  ' + asignados + ' trabajadores asignados', MARGIN, 38);
            pdf.setDrawColor(...VERDE);
            pdf.setLineWidth(0.4);
            pdf.line(MARGIN, 41, PAGE_W - MARGIN, 41);
            pdf.setLineWidth(0.2);

            // Tabla
            const tableStartY = 44;
            const tableH = PAGE_H - tableStartY - MARGIN - 10;
            const rowH   = Math.max(7, tableH / (filas.length + 1));

            pdf.autoTable({
                head: [['Puesto','Área','Trabajador']],
                body: filas.map(f => [f.cod, f.area, f.esVacante ? 'Sin asignar' : (f.esNP ? '⚠ TNR — ' + f.trabajador : f.trabajador)]),
                startY: tableStartY,
                margin: { left: MARGIN, right: MARGIN },
                tableWidth: CONTENT_W,
                styles: {
                    fontSize: 9,
                    cellPadding: { top:2.5, bottom:2.5, left:4, right:4 },
                    lineColor: [220,220,220],
                    lineWidth: 0.2,
                    valign: 'middle',
                    textColor: [40,40,40],
                    fillColor: [255,255,255],
                    minCellHeight: rowH,
                },
                headStyles: {
                    fillColor: VERDE,
                    textColor: [255,255,255],
                    fontStyle: 'bold',
                    fontSize: 8.5,
                    minCellHeight: rowH,
                },
                alternateRowStyles: { fillColor: VERDE_L },
                columnStyles: {
                    0: { cellWidth: 22, fontStyle:'bold', halign:'center' },
                    1: { cellWidth: 30 },
                    2: { cellWidth: 'auto' },
                },
                didParseCell(data) {
                    if (data.section !== 'body') return;
                    const fila = filas[data.row.index];
                    if (!fila) return;
                    if (fila.esVacante) {
                        data.cell.styles.textColor = [180,180,180];
                        data.cell.styles.fontStyle = 'italic';
                    } else if (fila.esNP) {
                        data.cell.styles.textColor = [180,40,40];
                        if (data.column.index === 2) data.cell.styles.fontStyle = 'bold';
                    } else if (fila.esEspecial) {
                        data.cell.styles.textColor = [100,70,0];
                        if (data.column.index === 0) data.cell.styles.fontStyle = 'bold';
                    }
                }
            });

            // Pie
            pdf.setDrawColor(200,200,200);
            pdf.setLineWidth(0.2);
            pdf.line(MARGIN, PAGE_H - 9, PAGE_W - MARGIN, PAGE_H - 9);
            pdf.setFont('helvetica','normal');
            pdf.setFontSize(7);
            pdf.setTextColor(180,180,180);
            pdf.text('Generado: ' + new Date().toLocaleDateString('es-CO') + ' ' + new Date().toLocaleTimeString('es-CO') + '  —  Sistema de Gestión de Turnos', PAGE_W/2, PAGE_H - 5, { align:'center' });
        }

        // ── SECCIÓN SUPERVISORES ──────────────────────────────────────────────
        if (supervisores.length > 0) {
            pdf.addPage();
            
            // Banda verde superior
            pdf.setFillColor(...VERDE);
            pdf.rect(0, 0, PAGE_W, 22, 'F');
            pdf.setFont('helvetica','bold');
            pdf.setFontSize(13);
            pdf.setTextColor(255,255,255);
            pdf.text('Terminal de Transportes de Ibagué', PAGE_W/2, 10, { align:'center' });
            pdf.setFont('helvetica','normal');
            pdf.setFontSize(9);
            pdf.setTextColor(200, 230, 210);
            pdf.text(fechaFmtCap, PAGE_W/2, 17, { align:'center' });

            // Nombre de la sección
            pdf.setFont('helvetica','bold');
            pdf.setFontSize(10);
            pdf.setTextColor(...VERDE);
            pdf.text('SUPERVISORES', MARGIN, 32);
            pdf.setFont('helvetica','normal');
            pdf.setFontSize(8);
            pdf.setTextColor(120,120,120);
            pdf.text('Turnos de supervisión  ·  ' + supervisores.length + ' supervisores asignados', MARGIN, 38);
            pdf.setDrawColor(...VERDE);
            pdf.setLineWidth(0.4);
            pdf.line(MARGIN, 41, PAGE_W - MARGIN, 41);
            pdf.setLineWidth(0.2);

            // Tabla de supervisores
            const filasSup = supervisores.map(s => [
                s.trabajador || '',
                s.hora_inicio.substring(0,5) + ' → ' + s.hora_fin.substring(0,5),
                calcularDuracionHoras(s.hora_inicio, s.hora_fin)
            ]);

            pdf.autoTable({
                head: [['Supervisor','Horario','Duración']],
                body: filasSup,
                startY: 44,
                margin: { left: MARGIN, right: MARGIN },
                tableWidth: CONTENT_W,
                styles: {
                    fontSize: 9,
                    cellPadding: { top:3, bottom:3, left:4, right:4 },
                    lineColor: [220,220,220],
                    lineWidth: 0.2,
                    valign: 'middle',
                    textColor: [40,40,40],
                    fillColor: [255,255,255],
                    minCellHeight: 8,
                },
                headStyles: {
                    fillColor: VERDE,
                    textColor: [255,255,255],
                    fontStyle: 'bold',
                    fontSize: 8.5,
                    minCellHeight: 8,
                },
                alternateRowStyles: { fillColor: VERDE_L },
                columnStyles: {
                    0: { cellWidth: 'auto', fontStyle:'bold' },
                    1: { cellWidth: 50, halign:'center', fontStyle:'bold' },
                    2: { cellWidth: 35, halign:'center' },
                }
            });
        }

        // ── SECCIÓN NOVEDADES ────────────────────────────────────────────────
        if (novedades && novedades.contenido && novedades.contenido.trim()) {
            pdf.addPage();
            
            // Banda verde superior
            pdf.setFillColor(...VERDE);
            pdf.rect(0, 0, PAGE_W, 22, 'F');
            pdf.setFont('helvetica','bold');
            pdf.setFontSize(13);
            pdf.setTextColor(255,255,255);
            pdf.text('Terminal de Transportes de Ibagué', PAGE_W/2, 10, { align:'center' });
            pdf.setFont('helvetica','normal');
            pdf.setFontSize(9);
            pdf.setTextColor(200, 230, 210);
            pdf.text(fechaFmtCap, PAGE_W/2, 17, { align:'center' });

            // Nombre de la sección
            pdf.setFont('helvetica','bold');
            pdf.setFontSize(10);
            pdf.setTextColor(...VERDE);
            pdf.text('NOVEDADES DEL DÍA', MARGIN, 32);
            pdf.setDrawColor(...VERDE);
            pdf.setLineWidth(0.4);
            pdf.line(MARGIN, 35, PAGE_W - MARGIN, 35);
            pdf.setLineWidth(0.2);

            // Contenido de novedades
            pdf.setFont('helvetica','normal');
            pdf.setFontSize(10);
            pdf.setTextColor(40,40,40);
            
            const lineas = pdf.splitTextToSize(novedades.contenido, CONTENT_W - 10);
            pdf.text(lineas, MARGIN + 5, 45);
        }

        ocultarSpinner();
        pdf.save('Turnos_' + fechaObj.toLocaleDateString('es-CO').replace(/\//g,'-') + '.pdf');
        mostrarAlerta('✅ PDF descargado correctamente', 'success');
    } catch(err) {
        ocultarSpinner();
        mostrarAlerta('Error al exportar PDF: ' + err.message, 'danger');
        console.error(err);
    }
}


//Exportacion mensual
async function exportarMesExcel() {
  const mes  = fechaCalendarioActual.getMonth() + 1;
  const anio = fechaCalendarioActual.getFullYear();
  const MESES = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
  const mesNombre = MESES[mes - 1];

  // Función para formatear hora sin ceros iniciales
  const formatoHora = (hora) => {
    if (!hora) return '';
    // Convertir "08:00" a "8" (solo hora sin minutos si son :00)
    const [h, m] = hora.replace(/^0+/, '').split(':');
    return m === '00' ? h : `${h}:${m}`;
  };

  try {
    const primerDia = new Date(anio, mes - 1, 1).toISOString().split('T')[0];
    const ultimoDia = new Date(anio, mes, 0).toISOString().split('T')[0];

    const [resTurnos, resSupervisores, resNovedades] = await Promise.all([
      fetch(API_BASE + 'turnos.php?fecha_inicio=' + primerDia + '&fecha_fin=' + ultimoDia),
      fetch(API_BASE + 'supervisores_turno.php?fecha_inicio=' + primerDia + '&fecha_fin=' + ultimoDia),
      fetch(API_BASE + 'novedades.php?fecha_inicio=' + primerDia + '&fecha_fin=' + ultimoDia)
    ]);
    const dataTurnos = await resTurnos.json();
    const dataSupervisores = await resSupervisores.json();
    const dataNovedades = await resNovedades.json();

    if (!dataTurnos.success || !dataTurnos.data || dataTurnos.data.length === 0) {
      mostrarAlerta('No hay turnos para exportar este mes', 'warning');
      return;
    }

    const turnos = dataTurnos.data.filter(t => t.estado !== 'cancelado');
    const supervisores = dataSupervisores.success ? dataSupervisores.data : [];
    const novedades = dataNovedades.success ? dataNovedades.data : [];

    // Generar lista de días del mes
    const diasEnMes = new Date(anio, mes, 0).getDate();
    const diasMes = Array.from({length: diasEnMes}, (_, i) => ({
      fecha: anio + '-' + String(mes).padStart(2, '0') + '-' + String(i + 1).padStart(2, '0')
    }));

    // Combinar turnos normales y supervisores para la exportación
    const todosLosRegistros = [...turnos];

    // Agregar supervisores como registros especiales
    supervisores.forEach(s => {
      todosLosRegistros.push({
        fecha: s.fecha,
        tipo_especial: 'SUP',
        trabajador: s.trabajador,
        trabajador_id: s.trabajador_id,
        hora_inicio: s.hora_inicio,
        hora_fin: s.hora_fin,
        estado: 'programado'
      });
    });

    // ── Hoja 1: Lista completa ordenada ──────────────────────────────────
    todosLosRegistros.sort((a, b) => {
      if (a.fecha !== b.fecha) return a.fecha.localeCompare(b.fecha);
      if (a.tipo_especial === 'SUP' && b.tipo_especial !== 'SUP') return -1;
      if (a.tipo_especial !== 'SUP' && b.tipo_especial === 'SUP') return 1;
      return (a.numero_turno || 99) - (b.numero_turno || 99);
    });

    const DIAS = ['Domingo','Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'];
    const border = {
      top:    { style: 'thin', color: { rgb: 'DEE2E6' } },
      bottom: { style: 'thin', color: { rgb: 'DEE2E6' } },
      left:   { style: 'thin', color: { rgb: 'DEE2E6' } },
      right:  { style: 'thin', color: { rgb: 'DEE2E6' } }
    };
    const center = { horizontal: 'center', vertical: 'center' };
    const left   = { horizontal: 'left',   vertical: 'center' };
    const FILL_HDR = { fgColor: { rgb: '025B2D' } };
    const FILL_T1  = { fgColor: { rgb: 'D1ECF1' } };
    const FILL_T2  = { fgColor: { rgb: 'FFF3CD' } };
    const FILL_T3  = { fgColor: { rgb: '2D2D44' } };
    const FILL_ESP = { fgColor: { rgb: 'CCE5FF' } };
    const FILL_ALT = { fgColor: { rgb: 'F8F9FA' } };

    const ws1_data = [
      ['Terminal de Transportes de Ibagué — Turnos ' + mesNombre + ' ' + anio, '', '', '', '', '', ''],
      ['', '', '', '', '', '', '', ''],
      ['Fecha', 'Día', 'Código', 'Turno', 'Horario', 'Trabajador', 'Área']
    ];
    const merges1 = [{ s: { r: 0, c: 0 }, e: { r: 0, c: 6 } }];

    let fechaAnterior = '';
    todosLosRegistros.forEach((t, idx) => {
      const fechaObj = new Date(t.fecha + 'T00:00:00');
      const dia = DIAS[fechaObj.getDay()];
      const orig = Number(t.numero_turno) || 0;
      const base = orig === 4 ? 1 : orig === 5 ? 2 : orig;
      let codigo = '';
      if (t.tipo_especial) {
        codigo = t.tipo_especial;
      } else {
        codigo = orig >= 4 ? String(base) + (t.puesto_codigo||'') + 'L4' : String(base) + (t.puesto_codigo||'');
      }

      ws1_data.push([
        t.fecha !== fechaAnterior ? t.fecha : '',
        t.fecha !== fechaAnterior ? dia : '',
        codigo,
        t.tipo_especial === 'SUP' ? 'SUPERVISIÓN' : (t.tipo_especial ? t.tipo_especial : (t.turno_nombre || '')),
        t.tipo_especial === 'SUP' ? (formatoHora((t.hora_inicio||'').substring(0,5)) + ' - ' + formatoHora((t.hora_fin||'').substring(0,5))) : (t.tipo_especial ? '—' : ((t.hora_inicio||'').substring(0,5) + ' - ' + (t.hora_fin||'').substring(0,5))),
        t.trabajador || '',
        t.tipo_especial === 'SUP' ? 'SUPERVISIÓN' : (t.tipo_especial ? 'ESPECIAL' : (t.area || ''))
      ]);
      fechaAnterior = t.fecha;
    });

    const ws1 = XLSX.utils.aoa_to_sheet(ws1_data);
    ws1['!merges'] = merges1;
    ws1['!cols'] = [
      { wch: 12 }, { wch: 12 }, { wch: 10 }, { wch: 22 }, { wch: 16 }, { wch: 30 }, { wch: 14 }
    ];

    const cols1 = ['A','B','C','D','E','F','G'];
    ws1_data.forEach((row, ri) => {
      cols1.forEach((col, ci) => {
        const addr = col + (ri + 1);
        if (!ws1[addr]) ws1[addr] = { v: '', t: 's' };

        if (ri === 0) {
          ws1[addr].s = { font: { bold: true, sz: 14, name: 'Arial', color: { rgb: 'FFFFFF' } }, fill: FILL_HDR, alignment: center };
        } else if (ri === 2) {
          ws1[addr].s = { font: { bold: true, sz: 10, name: 'Arial', color: { rgb: 'FFFFFF' } }, fill: { fgColor: { rgb: '495057' } }, alignment: center, border };
        } else if (ri > 2) {
          const codigo = String(row[2] || '');
          let fill = ri % 2 === 0 ? FILL_ALT : { fgColor: { rgb: 'FFFFFF' } };
          let fontColor = '212529';
          if (codigo.startsWith('1') || codigo === 'L' || codigo === 'ADMM')       { fill = FILL_T1; }
          else if (codigo.startsWith('2') || codigo === 'ADMT')                    { fill = FILL_T2; }
          else if (codigo.startsWith('3'))                                          { fill = FILL_T3; fontColor = 'E0E0E0'; }
          else if (codigo === 'ADM')                                                { fill = FILL_ESP; }

          ws1[addr].s = {
            font: { name: 'Arial', sz: 10, bold: ci === 2, color: { rgb: fontColor } },
            fill, alignment: ci <= 1 ? center : left, border
          };
        }
      });
    });

    ws1['!rows'] = ws1_data.map((_, i) => ({ hpt: i === 0 ? 24 : 15 }));

    // ── Hoja 2: Grilla trabajador × día ─────────────────────────────────
    const resTrab = await fetch(API_BASE + 'trabajadores.php');
    const dataTrab = await resTrab.json();
    let trabajadores = (dataTrab.success ? dataTrab.data : []).filter(t => t.activo);

    // Agregar supervisores que no estén en la lista de trabajadores
    supervisores.forEach(s => {
      if (!trabajadores.find(t => t.id === s.trabajador_id)) {
        trabajadores.push({
          id: s.trabajador_id,
          nombre: s.trabajador,
          activo: 1
        });
      }
    });
    
    trabajadores = trabajadores.sort((a,b) => a.nombre.localeCompare(b.nombre));

    const diasMesGrilla = [];
    const totalDias = new Date(anio, mes, 0).getDate();
    for (let d = 1; d <= totalDias; d++) {
      const dt = new Date(anio, mes - 1, d);
      diasMesGrilla.push({ num: d, fecha: dt.toISOString().split('T')[0], dia: ['D','L','M','X','J','V','S'][dt.getDay()] });
    }

    // Índice de turnos por trabajador y fecha
    const idx = {};
    turnos.forEach(t => {
      const tid = t.trabajador_id;
      const f   = t.fecha;
      if (!idx[tid]) idx[tid] = {};
      if (!idx[tid][f]) idx[tid][f] = [];
      idx[tid][f].push(t);
    });

    // Agregar supervisores al índice
    supervisores.forEach(s => {
      const tid = s.trabajador_id;
      const f   = s.fecha;
      if (!idx[tid]) idx[tid] = {};
      if (!idx[tid][f]) idx[tid][f] = [];
      idx[tid][f].push({
        tipo_especial: 'SUP',
        hora_inicio: s.hora_inicio,
        hora_fin: s.hora_fin
      });
    });

    const hdrGrilla = ['Trabajador', ...diasMesGrilla.map(d => d.num + '\n' + d.dia)];
    const ws2_data = [
      [mesNombre + ' ' + anio + ' — Vista mensual por trabajador', ...Array(totalDias).fill('')],
      hdrGrilla
    ];
    const merges2 = [{ s: { r: 0, c: 0 }, e: { r: 0, c: totalDias } }];

    trabajadores.forEach(trab => {
      const fila = [trab.nombre];
      diasMesGrilla.forEach(d => {
        const asigs = (idx[trab.id] && idx[trab.id][d.fecha]) || [];
        if (asigs.length === 0) {
          fila.push('');
        } else {
          const etiq = asigs.map(a => {
            if (a.tipo_especial === 'SUP') {
              return (formatoHora((a.hora_inicio||'').substring(0,5)) + '-' + formatoHora((a.hora_fin||'').substring(0,5)));
            } else if (a.tipo_especial) {
              return a.tipo_especial;
            }
            const orig = Number(a.numero_turno) || 0;
            const base = orig === 4 ? 1 : orig === 5 ? 2 : orig;
            return orig >= 4 ? String(base) + (a.puesto_codigo||'') + 'L4' : String(base) + (a.puesto_codigo||'');
          }).join('/');
          fila.push(etiq);
        }
      });
      // Solo agregar filas que tengan al menos una asignación
      if (fila.some((cell, i) => i > 0 && cell !== '')) {
        ws2_data.push(fila);
      }
    });

    const ws2 = XLSX.utils.aoa_to_sheet(ws2_data);
    ws2['!merges'] = merges2;
    ws2['!cols'] = [{ wch: 28 }, ...Array(totalDias).fill({ wch: 12 })];

    const colsG = ['A', ...diasMes.map((_, i) => {
      const n = i + 1;
      return n <= 25 ? String.fromCharCode(65 + n) : 'A' + String.fromCharCode(65 + n - 26);
    })];

    ws2_data.forEach((row, ri) => {
      colsG.forEach((col, ci) => {
        const addr = col + (ri + 1);
        if (!ws2[addr]) ws2[addr] = { v: '', t: 's' };

        if (ri === 0) {
          ws2[addr].s = { font: { bold: true, sz: 13, name: 'Arial', color: { rgb: 'FFFFFF' } }, fill: FILL_HDR, alignment: center };
        } else if (ri === 1) {
          const esFin = row[ci] && (String(row[ci]).endsWith('D') || String(row[ci]).endsWith('S'));
          ws2[addr].s = {
            font: { bold: true, sz: 9, name: 'Arial', color: { rgb: esFin ? '1B5E20' : 'FFFFFF' } },
            fill: ci === 0 ? { fgColor: { rgb: '495057' } } : (esFin ? { fgColor: { rgb: 'C8E6C9' } } : { fgColor: { rgb: '495057' } }),
            alignment: center, border
          };
        } else {
          const val = String(row[ci] || '');
          let fill = ri % 2 === 0 ? FILL_ALT : { fgColor: { rgb: 'FFFFFF' } };
          let fontColor = '212529';
          if (ci === 0) {
            ws2[addr].s = { font: { bold: true, sz: 9, name: 'Arial', color: { rgb: fontColor } }, fill, alignment: left, border };
          } else {
            const hdrDia = String(hdrGrilla[ci] || '');
            const esFin = hdrDia.endsWith('D') || hdrDia.endsWith('S');
            if (val.startsWith('1')) fill = FILL_T1;
            else if (val.startsWith('2')) fill = FILL_T2;
            else if (val.startsWith('3')) { fill = FILL_T3; fontColor = 'E0E0E0'; }
            else if (val === 'SUP') { fill = { fgColor: { rgb: 'D4AF37' } }; fontColor = '212529'; }
            else if (['L','ADMM','ADMT','ADM'].includes(val)) fill = FILL_ESP;
            else if (esFin && !val) fill = { fgColor: { rgb: 'E8F5E9' } };

            ws2[addr].s = {
              font: { bold: false, sz: 8, name: 'Arial', color: { rgb: fontColor } },
              fill, alignment: center, border
            };
          }
        }
      });
    });

    ws2['!rows'] = ws2_data.map((_, i) => ({ hpt: i <= 1 ? 20 : 14 }));

    // ── Hoja 3: Novedades del mes ────────────────────────────────────────
    const ws3_data = [
      ['Terminal de Transportes de Ibagué — Novedades ' + mesNombre + ' ' + anio, '', ''],
      ['', '', ''],
      ['Fecha', 'Día', 'Novedades']
    ];

    // Crear mapa de novedades por fecha
    const novedadesPorFecha = {};
    if (novedades && Array.isArray(novedades)) {
      novedades.forEach(n => {
        if (n.fecha && n.contenido && n.contenido.trim()) {
          novedadesPorFecha[n.fecha] = n.contenido.trim();
        }
      });
    }

    // Agregar filas para cada día del mes que tenga novedades
    diasMes.forEach(d => {
      if (novedadesPorFecha[d.fecha]) {
        const fechaObj = new Date(d.fecha + 'T00:00:00');
        const dia = DIAS[fechaObj.getDay()];
        ws3_data.push([
          d.fecha,
          dia,
          novedadesPorFecha[d.fecha]
        ]);
      }
    });

    const ws3 = XLSX.utils.aoa_to_sheet(ws3_data);
    ws3['!cols'] = [{ wch: 12 }, { wch: 12 }, { wch: 80 }];
    ws3['!rows'] = ws3_data.map((_, i) => ({ hpt: i <= 2 ? 20 : 60 })); // Más altura para el contenido

    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws1, 'Lista Completa');
    XLSX.utils.book_append_sheet(wb, ws2, 'Vista Mensual');
    if (ws3_data.length > 3) { // Solo agregar si hay novedades
      XLSX.utils.book_append_sheet(wb, ws3, 'Novedades');
    }

    XLSX.writeFile(wb, 'Turnos_' + mesNombre + '_' + anio + '.xlsx');
    mostrarAlerta('✅ Excel mensual exportado (' + turnos.length + ' turnos, ' + supervisores.length + ' supervisores, 2 hojas)', 'success');

  } catch (error) {
    console.error('Error exportando mes:', error);
    mostrarAlerta('Error al exportar: ' + error.message, 'danger');
  }
}

async function exportarMesPDF() {
    const mes  = fechaCalendarioActual.getMonth() + 1;
    const anio = fechaCalendarioActual.getFullYear();
    const MESES = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
    
    // Función para formatear hora sin ceros iniciales
    const formatoHora = (hora) => {
      if (!hora) return '';
      // Convertir "08:00" a "8" (solo hora sin minutos si son :00)
      const [h, m] = hora.replace(/^0+/, '').split(':');
      return m === '00' ? h : `${h}:${m}`;
    };
    const mesNombre = MESES[mes-1] + ' ' + anio;

    try {
        mostrarSpinner('Generando PDF del mes...');
        const primerDia = new Date(anio, mes-1, 1).toISOString().split('T')[0];
        const ultimoDia = new Date(anio, mes,   0).toISOString().split('T')[0];
        const diasEnMes = new Date(anio, mes, 0).getDate();

        const [rTurnos, rEsp, rTrab, rSupervisores, rNovedades] = await Promise.all([
            fetch(API_BASE + 'turnos.php?fecha_inicio=' + primerDia + '&fecha_fin=' + ultimoDia).then(r => r.json()),
            fetch(API_BASE + 'dias_especiales.php?fecha_inicio=' + primerDia + '&fecha_fin=' + ultimoDia).then(r => r.json()),
            fetch(API_BASE + 'trabajadores.php').then(r => r.json()),
            fetch(API_BASE + 'supervisores_turno.php?fecha_inicio=' + primerDia + '&fecha_fin=' + ultimoDia).then(r => r.json()),
            fetch(API_BASE + 'novedades.php?fecha_inicio=' + primerDia + '&fecha_fin=' + ultimoDia).then(r => r.json())
        ]);

        const turnos      = (rTurnos.success ? rTurnos.data : []).filter(t => t.estado !== 'cancelado');
        const especiales  = rEsp.success ? rEsp.data : [];
        const trabajadores = (rTrab.success ? rTrab.data : []).filter(t => t.activo);
        const supervisores = (rSupervisores.success ? rSupervisores.data : []);
        const novedades   = (rNovedades.success ? rNovedades.data : []);

        if (turnos.length === 0 && especiales.length === 0) {
            ocultarSpinner(); mostrarAlerta('No hay turnos para exportar este mes', 'warning'); return;
        }

        const mapa = {};
        const trabIds = new Set();
        turnos.forEach(t => {
            const tid = t.trabajador_id;
            const dia = parseInt(t.fecha.split('-')[2]);
            if (!mapa[tid]) mapa[tid] = {};
            let n = Number(t.numero_turno);
            if ([4,9].includes(n)) n = 1;
            if ([5,10].includes(n)) n = 2;
            const esL4 = [4,5,9,10].includes(Number(t.numero_turno));
            const cod  = t.tipo_especial ? t.tipo_especial : (esL4 ? 'T'+n+(t.puesto_codigo||'')+'L4' : 'T'+n+(t.puesto_codigo||''));
            mapa[tid][dia] = { cod, estado: t.estado };
            trabIds.add(tid);
        });
        especiales.forEach(e => {
            const tid = e.trabajador_id;
            const dia = parseInt(e.fecha_inicio.split('-')[2]);
            if (!mapa[tid]) mapa[tid] = {};
            mapa[tid][dia] = { cod: e.tipo, estado: 'especial' };
            trabIds.add(tid);
        });
        supervisores.forEach(s => {
            const tid = s.trabajador_id;
            const dia = parseInt(s.fecha.split('-')[2]);
            if (!mapa[tid]) mapa[tid] = {};
            const horaInicio = formatoHora((s.hora_inicio || '').substring(0, 5));
            const horaFin = formatoHora((s.hora_fin || '').substring(0, 5));
            mapa[tid][dia] = { cod: 'SUP', hora_inicio: horaInicio, hora_fin: horaFin, estado: 'programado' };
            trabIds.add(tid);
        });

        const listaTrab = trabajadores.filter(t => trabIds.has(t.id)).sort((a,b) => a.nombre.localeCompare(b.nombre));
        const dias = Array.from({length: diasEnMes}, (_,i) => i+1);
        const DIAS_CORTOS = ['D','L','M','X','J','V','S'];

        const VERDE   = [2, 91, 45];
        const VERDE_L = [235, 245, 239];

        const { jsPDF } = window.jspdf;
        const CHUNK_DIAS = 16;
        const chunksDias = [];
        for (let i = 0; i < diasEnMes; i += CHUNK_DIAS) chunksDias.push(dias.slice(i, i + CHUNK_DIAS));

        const mitad = Math.ceil(listaTrab.length / 2);
        const gruposTrab = [listaTrab.slice(0, mitad), listaTrab.slice(mitad)].filter(g => g.length > 0);

        const pdf = new jsPDF('l','mm','a4');
        const PAGE_W = 297, PAGE_H = 210, MARGIN = 10;
        const CONTENT_W = PAGE_W - MARGIN * 2;
        let isFirst = true;

        for (const dChunk of chunksDias) {
            for (let gi = 0; gi < gruposTrab.length; gi++) {
                const trabChunk = gruposTrab[gi];
                if (!isFirst) pdf.addPage();
                isFirst = false;

                const d1 = dChunk[0], d2 = dChunk[dChunk.length-1];

                // Banda verde
                pdf.setFillColor(...VERDE);
                pdf.rect(0, 0, PAGE_W, 16, 'F');
                pdf.setFont('helvetica','bold');
                pdf.setFontSize(11);
                pdf.setTextColor(255,255,255);
                pdf.text('Terminal de Transportes de Ibagué', MARGIN, 8);
                pdf.setFont('helvetica','normal');
                pdf.setFontSize(8);
                pdf.setTextColor(200, 230, 210);
                pdf.text('Turnos ' + mesNombre + '  ·  Días ' + d1 + '–' + d2, MARGIN, 13);
                pdf.setFontSize(7);
                pdf.text('Generado: ' + new Date().toLocaleDateString('es-CO'), PAGE_W - MARGIN, 8, { align:'right' });

                // Tabla
                const tableStartY = 19;
                const tableH = PAGE_H - tableStartY - MARGIN - 8;
                const rowH   = Math.max(5, tableH / (trabChunk.length + 1));
                const COL_NOMBRE = 40;
                const colDiaW = (CONTENT_W - COL_NOMBRE) / dChunk.length;

                const head = [['Trabajador', ...dChunk.map(d => {
                    const dObj = new Date(anio, mes-1, d);
                    return String(d) + '\n' + DIAS_CORTOS[dObj.getDay()];
                })]];

                const body = trabChunk.map(trab => [
                    trab.nombre.split(' ').slice(0,3).join(' '),
                    ...dChunk.map(d => { 
                        const e = mapa[trab.id]?.[d]; 
                        if (!e) return '';
                        if (e.cod === 'SUP') {
                            return (e.hora_inicio || '') + '-' + (e.hora_fin || '');
                        }
                        return e.cod;
                    })
                ]);

                const colStyles = { 0: { cellWidth: COL_NOMBRE, fontStyle:'bold', halign:'left' } };
                dChunk.forEach((d, i) => {
                    const esDom = new Date(anio, mes-1, d).getDay() === 0;
                    colStyles[i+1] = { cellWidth: colDiaW, halign:'center',
                        textColor: esDom ? [160,40,40] : [40,40,40] };
                });

                pdf.autoTable({
                    head, body,
                    startY: tableStartY,
                    margin: { left: MARGIN, right: MARGIN },
                    tableWidth: CONTENT_W,
                    styles: {
                        fontSize: 7,
                        cellPadding: { top:1.5, bottom:1.5, left:2, right:2 },
                        lineColor: [220,220,220],
                        lineWidth: 0.2,
                        valign: 'middle',
                        textColor: [40,40,40],
                        fillColor: [255,255,255],
                        minCellHeight: rowH,
                        overflow: 'ellipsize',
                    },
                    headStyles: {
                        fillColor: VERDE,
                        textColor: [255,255,255],
                        fontStyle: 'bold',
                        fontSize: 7,
                        halign: 'center',
                        minCellHeight: rowH,
                    },
                    alternateRowStyles: { fillColor: VERDE_L },
                    columnStyles: colStyles,
                    didParseCell(data) {
                        if (data.section !== 'body' || data.column.index === 0) return;
                        const trab = trabChunk[data.row.index];
                        const dia  = dChunk[data.column.index - 1];
                        const e    = mapa[trab?.id]?.[dia];
                        if (!e) return;
                        if (e.estado === 'no_presentado') {
                            data.cell.styles.textColor = [180,40,40];
                            data.cell.styles.fontStyle = 'bold';
                        } else if (e.cod === 'SUP') {
                            data.cell.styles.textColor = [184,134,11];
                            data.cell.styles.fontStyle = 'bold';
                        } else if (['L','L8','LC'].includes(e.cod)) {
                            data.cell.styles.textColor = [2,91,45];
                            data.cell.styles.fontStyle = 'bold';
                        } else if (['ADM','ADMM','ADMT'].includes(e.cod)) {
                            data.cell.styles.textColor = [60,90,160];
                        }
                    }
                });

                // Pie
                pdf.setDrawColor(200,200,200);
                pdf.setLineWidth(0.2);
                pdf.line(MARGIN, PAGE_H - 7, PAGE_W - MARGIN, PAGE_H - 7);
                pdf.setFont('helvetica','normal');
                pdf.setFontSize(6.5);
                pdf.setTextColor(180,180,180);
                pdf.text('Sistema de Gestión de Turnos — Terminal de Transportes de Ibagué', PAGE_W/2, PAGE_H - 4, { align:'center' });
            }
        }

        // ── SECCIÓN NOVEDADES DEL MES ──────────────────────────────────────
        if (novedades && Array.isArray(novedades) && novedades.length > 0) {
            // Filtrar solo las que tienen contenido
            const novedadesConContenido = novedades.filter(n => n.contenido && n.contenido.trim());
            
            if (novedadesConContenido.length > 0) {
                pdf.addPage();
                
                // Banda verde superior
                pdf.setFillColor(...VERDE);
                pdf.rect(0, 0, PAGE_W, 16, 'F');
                pdf.setFont('helvetica','bold');
                pdf.setFontSize(11);
                pdf.setTextColor(255,255,255);
                pdf.text('Terminal de Transportes de Ibagué', MARGIN, 8);
                pdf.setFont('helvetica','normal');
                pdf.setFontSize(8);
                pdf.setTextColor(200, 230, 210);
                pdf.text('Novedades ' + mesNombre, MARGIN, 13);

                // Título de la sección
                pdf.setFont('helvetica','bold');
                pdf.setFontSize(10);
                pdf.setTextColor(...VERDE);
                pdf.text('NOVEDADES DEL MES', MARGIN, 25);
                pdf.setDrawColor(...VERDE);
                pdf.setLineWidth(0.4);
                pdf.line(MARGIN, 28, PAGE_W - MARGIN, 28);
                pdf.setLineWidth(0.2);

                // Contenido de novedades
                pdf.setFont('helvetica','normal');
                pdf.setFontSize(9);
                pdf.setTextColor(40,40,40);
                
                let yPos = 35;
                novedadesConContenido.forEach(nov => {
                    const fechaObj = new Date(nov.fecha + 'T00:00:00');
                    const fechaFmt = fechaObj.toLocaleDateString('es-CO', { weekday:'short', day:'numeric', month:'short' });
                    
                    // Fecha
                    pdf.setFont('helvetica','bold');
                    pdf.setFontSize(9);
                    pdf.setTextColor(...VERDE);
                    pdf.text(fechaFmt.charAt(0).toUpperCase() + fechaFmt.slice(1) + ':', MARGIN, yPos);
                    
                    // Contenido
                    pdf.setFont('helvetica','normal');
                    pdf.setFontSize(9);
                    pdf.setTextColor(40,40,40);
                    const lineas = pdf.splitTextToSize(nov.contenido, CONTENT_W - 40);
                    pdf.text(lineas, MARGIN + 35, yPos);
                    
                    yPos += lineas.length * 5 + 8;
                    
                    // Nueva página si es necesario
                    if (yPos > PAGE_H - 30) {
                        pdf.addPage();
                        yPos = 25;
                        
                        // Banda verde en nueva página
                        pdf.setFillColor(...VERDE);
                        pdf.rect(0, 0, PAGE_W, 16, 'F');
                        pdf.setFont('helvetica','bold');
                        pdf.setFontSize(11);
                        pdf.setTextColor(255,255,255);
                        pdf.text('Terminal de Transportes de Ibagué — Novedades ' + mesNombre, MARGIN, 10);
                    }
                });
            }
        }

        ocultarSpinner();
        pdf.save('Turnos_' + mesNombre.replace(/ /g,'_') + '.pdf');
        mostrarAlerta('✅ PDF del mes generado', 'success');
    } catch(err) {
        ocultarSpinner();
        mostrarAlerta('Error al generar PDF: ' + err.message, 'danger');
        console.error(err);
    }
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
      csv += 'Fecha,Trabajador,Puesto,Área,Turno,Horario,Estado\n';
        
        turnos.forEach(turno => {
            csv += `${turno.fecha},`;
            csv += `"${turno.trabajador}",`;
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
// ─── NO SE PRESENTÓ ──────────────────────────────────────────────────────────
async function toggleNoPresentado(btn) {
    const turnoId    = btn.getAttribute('data-turno-id');
    const trabajador = btn.getAttribute('data-trabajador');
    const estadoActual = btn.getAttribute('data-estado');
    const esNP = estadoActual === 'no_presentado';

    if (!esNP) {
        // Confirmar antes de marcar ausente
        const ok = await confirmarAccion({
            titulo:  '¿Registrar TNR?',
            mensaje: '<strong>' + trabajador + '</strong> no realizó su turno. Se registrará como <strong>TNR</strong> (Turno No Realizado).',
            textoBtn: 'Sí, registrar TNR',
            tipoBtn:  'danger',
            icono:    'fa-user-times'
        });
        if (!ok) return;
    }

    const nuevoEstado = esNP ? 'programado' : 'no_presentado';
    mostrarSpinner(esNP ? 'Desmarcando TNR...' : 'Registrando TNR...');

    try {
        const res = await fetch(API_BASE + 'turnos.php?id=' + turnoId, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ estado: nuevoEstado, observaciones: esNP ? null : 'No se presentó al turno' })
        });
        const data = await res.json();
        ocultarSpinner();

        if (data.success) {
            const msg = esNP ? 'TNR desmarcado' : '⚠️ ' + trabajador + ' — TNR registrado';
            mostrarAlerta(msg, esNP ? 'success' : 'warning');
            registrarCambio(
                esNP ? 'TNR desmarcado' : 'TNR registrado',
                trabajador
            );
            cargarVistaDiaria();
            cargarEstadisticasDashboard();
        } else {
            mostrarAlerta(data.message || 'Error al actualizar estado', 'danger');
        }
    } catch (e) {
        ocultarSpinner();
        mostrarAlerta('Error de conexión', 'danger');
    }
}


// Wrapper que lee data attributes para evitar problemas de escaping con nombres
function eliminarTurnoBtn(btn) {
    const id     = btn.getAttribute('data-turno-id');
    const nombre = btn.getAttribute('data-trabajador');
    const fecha  = btn.getAttribute('data-fecha');
    eliminarTurno(id, nombre, fecha);
}

// ─── ELIMINAR TURNO INDIVIDUAL ───────────────────────────────────────────────
async function eliminarTurno(turnoId, nombreTrabajador, fecha) {
    const ok = await confirmarAccion({
        titulo:  'Eliminar asignación',
        mensaje: '¿Eliminar el turno de <strong>' + nombreTrabajador + '</strong> del <strong>' + fecha + '</strong>? Esta acción no se puede deshacer.',
        textoBtn: 'Eliminar turno',
        tipoBtn:  'danger',
        icono:    'fa-calendar-times'
    });
    if (!ok) return;

    mostrarSpinner('Eliminando turno...');
    try {
        const res = await fetch(API_BASE + 'turnos.php?id=' + turnoId, { method: 'DELETE' });
        const data = await res.json();
        ocultarSpinner();
        if (data.success) {
            mostrarAlerta('Turno eliminado correctamente', 'success');
            registrarCambio('Turno eliminado', nombreTrabajador + ' · ' + fecha);
            cargarVistaDiaria();
            // Recargar grilla mensual si está visible
            if (document.getElementById('calendario-vista-mensual') &&
                document.getElementById('calendario-vista-mensual').style.display !== 'none') {
                cargarGrillaMensual();
            }
        } else {
            mostrarAlerta(data.message || 'No se pudo eliminar el turno', 'danger');
        }
    } catch (e) {
        ocultarSpinner();
        mostrarAlerta('Error al eliminar el turno', 'danger');
    }
}

// ─── SPINNER GLOBAL ──────────────────────────────────────────────────────────
// ─── POPOVER EDICIÓN VISTA MENSUAL ───────────────────────────────────────────

// Wrapper que lee datos frescos del atributo data-asigs en tiempo real
function abrirPopoverMensualDesdecelda(celda) {
    const trabId = Number(celda.getAttribute('data-trab-id-cel'));
    const fecha  = celda.getAttribute('data-fecha');
    const nombre = celda.getAttribute('data-nombre').replace(/&#39;/g, "'");
    const cargo  = celda.getAttribute('data-cargo') || '';
    const raw    = celda.getAttribute('data-asigs') || '[]';
    abrirPopoverMensual(celda, trabId, fecha, nombre, raw, cargo);
}

function abrirPopoverMensual(celda, trabId, fecha, nombre, asigsSafeStr, cargo = '') {
    // Cerrar cualquier popover previo
    const prev = document.getElementById('popover-mensual');
    if (prev) prev.remove();

    let asigs = [];
    try {
        // Decodificar entidades HTML que se escaparon al guardar en data-asigs
        const decoded = asigsSafeStr
            .replace(/&quot;/g, '"')
            .replace(/&#39;/g, "'")
            .replace(/&amp;/g, '&');
        asigs = JSON.parse(decoded);
    } catch(e) { asigs = []; }

    const fechaFmt = new Date(fecha + 'T00:00:00').toLocaleDateString('es-CO', {weekday:'short', day:'numeric', month:'short'});

    // Construir contenido según lo que haya ese día
    let itemsHtml = '';
    asigs.forEach(a => {
        if (a.tipo_especial === 'L' || a.tipo_especial === 'L8' || a.tipo_especial === 'LC') {
            const esAuto = (a.descripcion||'').startsWith('AUTO:');
            const badge = esAuto
                ? '<span style="background:#fd7e14;color:white;padding:1px 7px;border-radius:10px;font-size:0.75rem;font-weight:700;">L⚡ AUTO</span>'
                : '<span style="background:#cce5ff;color:#004085;padding:1px 7px;border-radius:10px;font-size:0.75rem;font-weight:700;">' + a.tipo_especial + '</span>';
            itemsHtml += `
                <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;padding:6px 0;border-bottom:1px solid #f0f0f0;">
                    <div style="display:flex;align-items:center;gap:6px;">
                        ${badge}
                        <span style="font-size:0.8rem;color:#6c757d;">${esAuto ? 'Generado automáticamente' : 'Día libre'}</span>
                    </div>
                    <div style="display:flex;gap:4px;">
                        <button onclick="cambiarDiaLibreMensual(${trabId},'${fecha}','${nombre}')" 
                            style="background:#0d6efd;color:white;border:none;border-radius:6px;padding:3px 8px;font-size:0.75rem;cursor:pointer;">
                            ✏️ Cambiar fecha
                        </button>
                        <button onclick="eliminarDiaLibreMensual(${trabId},'${fecha}','${nombre}')"
                            style="background:#dc3545;color:white;border:none;border-radius:6px;padding:3px 8px;font-size:0.75rem;cursor:pointer;">
                            🗑️
                        </button>
                    </div>
                </div>`;
        } else if (a.tipo_especial === 'VAC') {
            itemsHtml += `<div style="padding:6px 0;border-bottom:1px solid #f0f0f0;">
                <span style="background:#d4edda;color:#155724;padding:1px 7px;border-radius:10px;font-size:0.75rem;font-weight:700;">VAC</span>
                <span style="font-size:0.8rem;color:#6c757d;margin-left:6px;">Vacaciones</span>
            </div>`;
        } else if (a.tipo_especial === 'INC') {
            itemsHtml += `<div style="padding:6px 0;border-bottom:1px solid #f0f0f0;">
                <span style="background:#f8d7da;color:#721c24;padding:1px 7px;border-radius:10px;font-size:0.75rem;font-weight:700;">INC</span>
                <span style="font-size:0.8rem;color:#6c757d;margin-left:6px;">${a.descripcion || 'Incapacidad'}</span>
            </div>`;
        } else if (a.tipo_especial === 'SUP') {
            const horas = a.hora_inicio && a.hora_fin ? `${a.hora_inicio.substring(0,5)} → ${a.hora_fin.substring(0,5)}` : 'Horario no definido';
            itemsHtml += `<div style="display:flex;align-items:center;justify-content:space-between;gap:8px;padding:6px 0;border-bottom:1px solid #f0f0f0;">
                <div style="display:flex;align-items:center;gap:6px;">
                    <span style="background:#f3e8ff;color:#6b21a8;padding:1px 7px;border-radius:10px;font-size:0.75rem;font-weight:700;">SUP</span>
                    <span style="font-size:0.8rem;color:#6c757d;">${horas}</span>
                </div>
                <div style="display:flex;gap:4px;">
                    <button onclick="editarSupervisorTurno(${a.id})"
                        style="background:#9c27b0;color:white;border:none;border-radius:6px;padding:3px 8px;font-size:0.75rem;cursor:pointer;">
                        ✏️ Editar
                    </button>
                    <button onclick="eliminarSupervisorTurno(${a.id},'${nombre.replace(/'/g, "\\'")}','${fecha}')"
                        style="background:#dc3545;color:white;border:none;border-radius:6px;padding:3px 8px;font-size:0.75rem;cursor:pointer;">
                        🗑️
                    </button>
                </div>
            </div>`;
        } else if (['ADM','ADMM','ADMT'].includes(a.tipo_especial)) {
            const tipoLabel = a.tipo_especial === 'ADM' ? 'Disponible Día Completo' : a.tipo_especial === 'ADMM' ? 'Disponible Mañana' : 'Disponible Tarde';
            itemsHtml += `<div style="display:flex;align-items:center;justify-content:space-between;gap:8px;padding:6px 0;border-bottom:1px solid #f0f0f0;">
                <div style="display:flex;align-items:center;gap:6px;">
                    <span style="background:#fde8d8;color:#7d3800;padding:1px 7px;border-radius:10px;font-size:0.75rem;font-weight:700;">${a.tipo_especial}</span>
                    <span style="font-size:0.8rem;color:#6c757d;">${tipoLabel}</span>
                </div>
                <div style="display:flex;gap:4px;">
                    <button onclick="cambiarDisponibilidadMensual(${trabId},'${fecha}','${nombre}','${a.tipo_especial}')"
                        style="background:#6c757d;color:white;border:none;border-radius:6px;padding:3px 8px;font-size:0.75rem;cursor:pointer;">
                        ✏️ Cambiar
                    </button>
                    <button onclick="eliminarDiaEspecialMensual(${trabId},'${fecha}','${a.tipo_especial}','${nombre}')"
                        style="background:#dc3545;color:white;border:none;border-radius:6px;padding:3px 8px;font-size:0.75rem;cursor:pointer;">
                        🗑️
                    </button>
                </div>
            </div>`;
        } else if (a.numero_turno) {
            const origNum = Number(a.numero_turno);
            const esL4 = [4,5,9,10].includes(origNum);
            const num = [4,9].includes(origNum) ? 1 : [5,10].includes(origNum) ? 2 : origNum;
            const etiqueta = esL4 ? `${num}${a.puesto_codigo||''}L4` : `T${num} ${a.puesto_codigo||''}`;
            const nombreT = esL4 ? `L4 ${num===1?'Mañana':'Tarde'}` : num===1?'Mañana':num===2?'Tarde':num===3?'Noche':'Especial';
            const bgT = num===1?'#d1ecf1':num===2?'#fff3cd':'#1a1a2e';
            const colT = num===1?'#0c5460':num===2?'#856404':'#e0e0e0';
            itemsHtml += `<div style="display:flex;align-items:center;justify-content:space-between;gap:8px;padding:6px 0;border-bottom:1px solid #f0f0f0;">
                <div style="display:flex;align-items:center;gap:6px;">
                    <span style="background:${bgT};color:${colT};padding:1px 7px;border-radius:10px;font-size:0.75rem;font-weight:700;">${etiqueta}</span>
                    <span style="font-size:0.8rem;color:#6c757d;">${nombreT}</span>
                </div>
                <div style="display:flex;gap:4px;">
                    <button onclick="cambiarTurnoMensual(${trabId},'${fecha}','${nombre}',${a.id},'${a.puesto_codigo||''}',${origNum},'normal',null,null)"
                        style="background:#6c757d;color:white;border:none;border-radius:6px;padding:3px 8px;font-size:0.75rem;cursor:pointer;">
                        ✏️ Cambiar
                    </button>
                    <button onclick="eliminarTurnoMensual(${trabId},'${fecha}','${nombre}',${a.id})"
                        style="background:#dc3545;color:white;border:none;border-radius:6px;padding:3px 8px;font-size:0.75rem;cursor:pointer;">
                        🗑️
                    </button>
                </div>
            </div>`;
        }
    });

    if (itemsHtml === '') {
        itemsHtml = '<div style="padding:8px 0;color:#adb5bd;font-size:0.82rem;text-align:center;">Sin asignaciones este día</div>';
    }

    // Botones de acción rápida según lo que tenga ese día
    const tieneLibre  = asigs.some(a => ['L','L8','LC'].includes(a.tipo_especial));
    const tieneTurno  = asigs.some(a => a.numero_turno);
    const tieneImpedimento = asigs.some(a => ['VAC','INC','SUS'].includes(a.tipo_especial));
    const esSupervisor = String(cargo || '').toLowerCase() === 'supervisor';
    const cargoSafe = (cargo || '').replace(/'/g, "\\'");

    let botonesAccion = '';
    if (!tieneLibre && !tieneImpedimento) {
        botonesAccion += `
        <button onclick="agregarDiaLibreMensual(${trabId},'${fecha}','${nombre}')"
            style="width:100%;margin-top:8px;background:#025B2D;color:white;border:none;border-radius:8px;
                   padding:7px;font-size:0.82rem;font-weight:600;cursor:pointer;">
            📅 Asignar día libre aquí
        </button>`;
    }
    if (!tieneTurno && !tieneLibre && !tieneImpedimento) {
        const label = esSupervisor ? '➕ Asignar turno supervisor aquí' : '➕ Asignar turno aquí';
        botonesAccion += `
        <button onclick="asignarTurnoRapidoMensual(${trabId},'${fecha}','${nombre}','${cargoSafe}')"
            style="width:100%;margin-top:6px;background:#0d6efd;color:white;border:none;border-radius:8px;
                   padding:7px;font-size:0.82rem;font-weight:600;cursor:pointer;">
            ${label}
        </button>`;
    }
    const btnAgregar = botonesAccion;

    const pop = document.createElement('div');
    pop.id = 'popover-mensual';
    pop.style.cssText = `
        position:fixed;z-index:9999;background:white;border-radius:12px;
        box-shadow:0 8px 30px rgba(0,0,0,0.18);padding:14px 16px;
        min-width:300px;max-width:360px;
        border-top:3px solid #025B2D;
    `;
    pop.innerHTML = `
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
            <div>
                <div style="font-weight:700;font-size:0.9rem;color:#1a1a2e;">${nombre}</div>
                <div style="font-size:0.78rem;color:#6c757d;">${fechaFmt}</div>
            </div>
            <button onclick="document.getElementById('popover-mensual').remove()"
                style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:#6c757d;line-height:1;">×</button>
        </div>
        <div>${itemsHtml}</div>
        ${btnAgregar}
    `;

    document.body.appendChild(pop);

    // Posicionar cerca de la celda
    const rect = celda.getBoundingClientRect();
    let top  = rect.bottom + 6;
    let left = rect.left;
    if (left + 360 > window.innerWidth) left = window.innerWidth - 370;
    if (top + 250 > window.innerHeight) top = rect.top - 260;
    pop.style.top  = top  + 'px';
    pop.style.left = left + 'px';

    // Cerrar al hacer clic fuera
    setTimeout(() => {
        document.addEventListener('click', function cerrar(ev) {
            if (!pop.contains(ev.target) && ev.target !== celda) {
                pop.remove();
                document.removeEventListener('click', cerrar);
            }
        });
    }, 100);
}

async function eliminarDiaEspecialMensual(trabId, fecha, tipo, nombre) {
    const conf = await confirmarAccion({ titulo: 'Eliminar día especial', mensaje: `¿Eliminar <strong>${tipo}</strong> del ${fecha} de <strong>${nombre}</strong>?`, textoBtn: 'Eliminar', tipoBtn: 'danger', icono: 'fa-trash' });
    if (!conf) return;
    mostrarSpinner('Eliminando...');
    try {
        const res = await fetch(API_BASE + 'dias_especiales.php', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ action: 'eliminar', trabajador_id: trabId, fecha: fecha, tipo: tipo })
        });
        const r = await res.json();
        ocultarSpinner();
        if (r.success) {
            mostrarAlerta('✅ ' + tipo + ' eliminado', 'success');
            recargarFilaMensual(trabId);
        } else {
            mostrarAlerta('❌ Error: ' + (r.message || 'No se pudo eliminar'), 'danger');
        }
    } catch(e) { ocultarSpinner(); mostrarAlerta('Error de conexión', 'danger'); }
}

async function cambiarDisponibilidadMensual(trabId, fecha, nombre, tipoActual) {
    document.getElementById('popover-mensual')?.remove();
    document.getElementById('overlay-disponibilidad-mensual')?.remove();
    document.getElementById('popover-disponibilidad-mensual')?.remove();

    const overlay = document.createElement('div');
    overlay.id = 'overlay-disponibilidad-mensual';
    overlay.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9998;';
    const pop = document.createElement('div');
    pop.id = 'popover-disponibilidad-mensual';
    pop.style.cssText = `position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);
        z-index:9999;background:white;border-radius:14px;padding:24px 28px;
        box-shadow:0 12px 40px rgba(0,0,0,0.25);min-width:340px;`;
    pop.innerHTML = `
        <div style="font-size:1.3rem;text-align:center;margin-bottom:8px;">✏️</div>
        <div style="font-weight:700;font-size:1rem;text-align:center;margin-bottom:4px;">Cambiar disponibilidad</div>
        <div style="font-size:0.82rem;color:#6c757d;text-align:center;margin-bottom:18px;">${nombre} — ${fecha}</div>
        <label style="font-size:0.82rem;font-weight:600;color:#495057;">Tipo de disponibilidad</label>
        <select id="sel-nuevo-tipo-disponibilidad" style="width:100%;padding:7px 10px;border:1px solid #dee2e6;border-radius:8px;margin-bottom:18px;margin-top:4px;font-size:0.88rem;">
            <option value="ADMM" ${tipoActual === 'ADMM' ? 'selected' : ''}>ADMM — Disponible Mañana</option>
            <option value="ADMT" ${tipoActual === 'ADMT' ? 'selected' : ''}>ADMT — Disponible Tarde</option>
            <option value="ADM" ${tipoActual === 'ADM' ? 'selected' : ''}>ADM — Disponible Día Completo</option>
        </select>
        <div style="display:flex;gap:8px;">
            <button id="btn-guardar-cambio-disponibilidad" style="flex:1;background:#025B2D;color:white;border:none;border-radius:8px;padding:9px;font-weight:600;cursor:pointer;">Guardar cambio</button>
            <button id="btn-cancelar-cambio-disponibilidad" style="background:#6c757d;color:white;border:none;border-radius:8px;padding:9px 16px;cursor:pointer;">Cancelar</button>
        </div>
    `;
    document.body.appendChild(overlay);
    document.body.appendChild(pop);

    const cerrarDisponibilidad = () => { pop.remove(); overlay.remove(); };
    overlay.addEventListener('click', e => { if (e.target === overlay) cerrarDisponibilidad(); });
    pop.querySelector('#btn-cancelar-cambio-disponibilidad')?.addEventListener('click', cerrarDisponibilidad);

    pop.querySelector('#btn-guardar-cambio-disponibilidad').onclick = async () => {
        const nuevoTipo = pop.querySelector('#sel-nuevo-tipo-disponibilidad').value;
        if (nuevoTipo === tipoActual) { pop.remove(); overlay.remove(); return; }
        pop.remove(); overlay.remove();
        mostrarSpinner('Guardando cambio...');
        try {
            // Eliminar el actual
            await fetch(API_BASE + 'dias_especiales.php', {
                method: 'POST',
                headers: {'Content-Type':'application/json'},
                body: JSON.stringify({ action: 'eliminar', trabajador_id: trabId, fecha: fecha, tipo: tipoActual })
            });
            // Crear el nuevo
            const res = await fetch(API_BASE + 'dias_especiales.php', {
                method: 'POST',
                headers: {'Content-Type':'application/json'},
                body: JSON.stringify({
                    trabajador_id: trabId,
                    tipo: nuevoTipo,
                    fecha_inicio: fecha,
                    fecha_fin: fecha,
                    descripcion: nuevoTipo + ' asignado desde vista mensual',
                    estado: 'programado'
                })
            });
            const r = await res.json();
            ocultarSpinner();
            if (r.success) {
                mostrarAlerta('✅ Disponibilidad cambiada a ' + nuevoTipo, 'success');
                recargarFilaMensual(trabId);
            } else {
                mostrarAlerta('❌ Error: ' + (r.message || 'No se pudo guardar'), 'danger');
            }
        } catch(e) { ocultarSpinner(); mostrarAlerta('Error de conexión', 'danger'); }
    };
}

async function cambiarTurnoMensual(trabId, fecha, nombre, turnoAsigId, puestoCodigo, numTurnoActual, tipoOrigen='normal', tipoEspecialActual=null, diaEspecialId=null) {
    document.getElementById('popover-mensual')?.remove();

    // Cargar puestos y turnos disponibles
    mostrarSpinner('Cargando opciones...');
    let puestos = [], turnosConf = [];
    try {
        const [rP, rT] = await Promise.all([
            fetch(API_BASE + 'turnos.php?action=puestos').then(r => r.json()),
            fetch(API_BASE + 'turnos.php?action=configuracion').then(r => r.json())
        ]);
        puestos     = (rP.success && rP.data)  ? rP.data  : [];
        turnosConf  = (rT.success && rT.data)  ? rT.data.filter(t => [1,2,3,4,5,7,8].includes(Number(t.numero_turno))) : [];
    } catch(e) {}
    ocultarSpinner();

    // Agrupar puestos por área
    const areasPuestos = {};
    puestos.forEach(p => {
        if (!areasPuestos[p.area]) areasPuestos[p.area] = [];
        areasPuestos[p.area].push(p);
    });

    let optsPuestos = '';
    Object.entries(areasPuestos).forEach(([area, ps]) => {
        optsPuestos += `<optgroup label="${area}">`;
        ps.forEach(p => {
            const sel = p.codigo === puestoCodigo ? 'selected' : '';
            optsPuestos += `<option value="${p.id}" data-codigo="${p.codigo}" ${sel}>${p.codigo} — ${p.nombre}</option>`;
        });
        optsPuestos += '</optgroup>';
    });

    // IDs correctos por numero_turno (preferir L4 sobre Turno Especial)
    // En BD: id=9 es L4 Mañana (num=4), id=10 es L4 Tarde (num=5)
    // id=4 y id=5 son "Turno Especial" legacy — usar los L4 para el select
    const ID_PREFERIDO = { 4: 9, 5: 10 }; // numero_turno → id preferido

    const turnosUnicos = [];
    const vistos = new Set();
    // Primero pasar los preferidos
    turnosConf
        .filter(t => Object.values(ID_PREFERIDO).includes(Number(t.id)))
        .forEach(t => {
            if (!vistos.has(Number(t.numero_turno))) {
                vistos.add(Number(t.numero_turno));
                turnosUnicos.push(t);
            }
        });
    // Luego el resto sin duplicar
    turnosConf.forEach(t => {
        if (!vistos.has(Number(t.numero_turno))) {
            vistos.add(Number(t.numero_turno));
            turnosUnicos.push(t);
        }
    });

    // Turnos normales (van a turnos_asignados)
    const nombresNormales = {
        1: 'T1 — Mañana (06:00-14:00)',
        2: 'T2 — Tarde (14:00-22:00)',
        3: 'T3 — Noche (22:00-06:00)',
        4: 'L4 — 4h Mañana',
        5: 'L4 — 4h Tarde'
    };
    let optsTurnos = '<optgroup label="Turnos normales">';
    optsTurnos += turnosUnicos
        .filter(t => [1,2,3,4,5].includes(Number(t.numero_turno)))
        .map(t => {
            const sel = Number(t.numero_turno) === numTurnoActual ? 'selected' : '';
            const label = nombresNormales[Number(t.numero_turno)] || t.nombre || 'T'+t.numero_turno;
            return `<option value="${t.id}" data-numero="${t.numero_turno}" data-tipo="normal" ${sel}>${label}</option>`;
        }).join('');
    optsTurnos += '</optgroup>';
    // Turnos especiales administrativos (van a dias_especiales)
    optsTurnos += '<optgroup label="Disponibilidades">';
    optsTurnos += `<option value="ADMM" data-tipo="especial">ADMM — Disponible Mañana</option>`;
    optsTurnos += `<option value="ADMT" data-tipo="especial">ADMT — Disponible Tarde</option>`;
    optsTurnos += `<option value="ADM"  data-tipo="especial">ADM — Disponible Día Completo</option>`;
    optsTurnos += '</optgroup>';

    document.getElementById('overlay-cambio-turno')?.remove();
    document.getElementById('popover-cambio-turno')?.remove();

    const overlay = document.createElement('div');
    overlay.id = 'overlay-cambio-turno';
    overlay.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9998;';
    const pop = document.createElement('div');
    pop.id = 'popover-cambio-turno';
    pop.style.cssText = `position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);
        z-index:9999;background:white;border-radius:14px;padding:24px 28px;
        box-shadow:0 12px 40px rgba(0,0,0,0.25);min-width:340px;`;
    pop.innerHTML = `
        <div style="font-size:1.3rem;text-align:center;margin-bottom:8px;">✏️</div>
        <div style="font-weight:700;font-size:1rem;text-align:center;margin-bottom:4px;">Cambiar turno</div>
        <div style="font-size:0.82rem;color:#6c757d;text-align:center;margin-bottom:18px;">${nombre} — ${fecha}</div>
        <label style="font-size:0.82rem;font-weight:600;color:#495057;">Turno</label>
        <select id="sel-nuevo-turno" style="width:100%;padding:7px 10px;border:1px solid #dee2e6;border-radius:8px;margin-bottom:12px;margin-top:4px;font-size:0.88rem;">${optsTurnos}</select>
        <div id="wrap-puesto-cambio">
        <label style="font-size:0.82rem;font-weight:600;color:#495057;">Puesto</label>
        <select id="sel-nuevo-puesto" style="width:100%;padding:7px 10px;border:1px solid #dee2e6;border-radius:8px;margin-bottom:18px;margin-top:4px;font-size:0.88rem;">${optsPuestos}</select>
        </div>
        <div style="display:flex;gap:8px;">
            <button id="btn-guardar-cambio-turno" style="flex:1;background:#025B2D;color:white;border:none;border-radius:8px;padding:9px;font-weight:600;cursor:pointer;">Guardar cambio</button>
            <button id="btn-cancelar-cambio-turno" style="background:#6c757d;color:white;border:none;border-radius:8px;padding:9px 16px;cursor:pointer;">Cancelar</button>
        </div>
    `;
    document.body.appendChild(overlay);
    document.body.appendChild(pop);

    const cerrarCambioTurno = () => { pop.remove(); overlay.remove(); };
    overlay.addEventListener('click', e => { if (e.target === overlay) cerrarCambioTurno(); });
    pop.querySelector('#btn-cancelar-cambio-turno')?.addEventListener('click', cerrarCambioTurno);

    // Datos de puestos L4 para filtrar dinámicamente
    const PUESTOS_L4_CODIGOS = new Set(['F5','F15','D2','D1','F11']);
    const todosLosPuestosOpts = optsPuestos; // guardar opciones completas

    // Construir opciones solo con puestos L4
    const optsL4 = (() => {
        let html = '';
        puestos.filter(p => PUESTOS_L4_CODIGOS.has(p.codigo)).forEach(p => {
            html += `<option value="${p.id}" data-codigo="${p.codigo}">${p.codigo} — ${p.nombre}</option>`;
        });
        return html;
    })();

    pop.querySelector('#sel-nuevo-turno').addEventListener('change', function() {
        const num = Number(this.selectedOptions[0]?.dataset?.numero);
        const tipo = this.selectedOptions[0]?.dataset?.tipo;
        const wrap = pop.querySelector('#wrap-puesto-cambio');
        const selPuesto = pop.querySelector('#sel-nuevo-puesto');
        if (tipo === 'especial' || ['ADMM','ADMT','ADM'].includes(this.value)) {
            // ADM no tienen puesto — ocultar selector
            wrap.style.display = 'none';
        } else if (num === 4 || num === 5) {
            // L4 — solo puestos F5, F15, D2, F11
            wrap.style.display = '';
            selPuesto.innerHTML = optsL4;
        } else {
            // Turno normal — todos los puestos
            wrap.style.display = '';
            selPuesto.innerHTML = todosLosPuestosOpts;
        }
    });

    // Disparar el evento al cargar para el turno actual
    pop.querySelector('#sel-nuevo-turno').dispatchEvent(new Event('change'));

    pop.querySelector('#btn-guardar-cambio-turno').onclick = async () => {
        const selTurno      = pop.querySelector('#sel-nuevo-turno');
        const nuevoTurnoVal = selTurno.value;
        const nuevoPuestoEl = pop.querySelector('#sel-nuevo-puesto');
        const nuevoPuestoId = nuevoPuestoEl?.value || null;
        const tipoDestino   = selTurno.selectedOptions[0]?.dataset?.tipo || 'normal';
        const esDestEspecial = tipoDestino === 'especial' || ['ADMM','ADMT','ADM'].includes(nuevoTurnoVal);
        pop.remove(); overlay.remove();
        mostrarSpinner('Guardando cambio...');
        try {
            // PASO 1: Eliminar/cancelar el turno/especial actual
            if (tipoOrigen === 'especial' && diaEspecialId) {
                // Origen es dias_especiales — eliminar por id
                await fetch(API_BASE + 'dias_especiales.php', {
                    method: 'POST',
                    headers: {'Content-Type':'application/json'},
                    body: JSON.stringify({ action: 'eliminar', id: diaEspecialId })
                });
            } else if (tipoOrigen === 'especial' && !diaEspecialId) {
                // Sin id — eliminar por trabajador+fecha+tipo
                await fetch(API_BASE + 'dias_especiales.php', {
                    method: 'POST',
                    headers: {'Content-Type':'application/json'},
                    body: JSON.stringify({ action: 'eliminar', trabajador_id: trabId, fecha: fecha, tipo: tipoEspecialActual })
                });
            } else if (turnoAsigId) {
                // Origen es turnos_asignados — cancelar
                await fetch(API_BASE + 'turnos.php', {
                    method: 'POST',
                    headers: {'Content-Type':'application/json'},
                    body: JSON.stringify({ action: 'cancelar', id: turnoAsigId })
                });
            }

            // PASO 2: Crear el nuevo turno/especial
            if (esDestEspecial) {
                // Verificar si ya existe este tipo especial ese día
                const checkRes = await fetch(API_BASE + 'dias_especiales.php?fecha_inicio=' + fecha + '&fecha_fin=' + fecha + '&trabajador_id=' + trabId);
                const checkData = await checkRes.json();
                if (checkData.success && checkData.data.some(de => de.tipo === nuevoTurnoVal)) {
                    ocultarSpinner();
                    mostrarAlerta('❌ Ya tiene ' + nuevoTurnoVal + ' asignado ese día', 'danger');
                    return;
                }
                // Nuevo es ADMM/ADMT/ADM → crear en dias_especiales
                const resEsp = await fetch(API_BASE + 'dias_especiales.php', {
                    method: 'POST',
                    headers: {'Content-Type':'application/json'},
                    body: JSON.stringify({
                        trabajador_id: trabId, 
                        tipo: nuevoTurnoVal,
                        fecha_inicio: fecha, 
                        fecha_fin: fecha,
                        horas_inicio: null,
                        horas_fin: null,
                        descripcion: nuevoTurnoVal + ' asignado desde vista mensual',
                        estado: 'programado'
                    })
                });
                const dataEsp = await resEsp.json();
                ocultarSpinner();
                if (dataEsp.success) {
                    mostrarAlerta('✅ Cambiado a ' + nuevoTurnoVal, 'success');
                    recargarFilaMensual(trabId);
                } else {
                    mostrarAlerta('❌ Error: ' + (dataEsp.message || 'No se pudo guardar'), 'danger');
                }
            } else {
                // Nuevo es turno normal/L4 → crear en turnos_asignados
                const res = await fetch(API_BASE + 'turnos.php', {
                    method: 'POST',
                    headers: {'Content-Type':'application/json'},
                    body: JSON.stringify({
                        trabajador_id: trabId, puesto_trabajo_id: nuevoPuestoId,
                        turno_id: nuevoTurnoVal, fecha, estado: 'programado', created_by: 1
                    })
                });
                const r = await res.json();
                ocultarSpinner();
                if (r.success) {
                    mostrarAlerta('✅ Turno actualizado', 'success');
                    recargarFilaMensual(trabId);
                } else {
                    mostrarAlerta('❌ Error: ' + (r.message || r.error), 'danger');
                }
            }
        } catch(e) { ocultarSpinner(); mostrarAlerta('Error de conexión', 'danger'); }
    };
}

async function asignarTurnoRapidoMensual(trabId, fecha, nombre, cargo = '') {
    document.getElementById('popover-mensual')?.remove();

    const esSupervisor = String(cargo || '').toLowerCase() === 'supervisor';
    if (esSupervisor) {
        document.getElementById('overlay-rapido')?.remove();
        document.getElementById('popover-rapido')?.remove();
        const overlay = document.createElement('div');
        overlay.id = 'overlay-rapido';
        overlay.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;';

        const pop = document.createElement('div');
        pop.id = 'popover-rapido';
        pop.style.cssText = `position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);
            z-index:10000;background:white;border-radius:14px;padding:24px 28px;
            box-shadow:0 12px 40px rgba(0,0,0,0.25);min-width:340px;`;

        pop.innerHTML = `
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                <div>
                    <div style="font-weight:700;font-size:1rem;color:#1a1a2e;">Asignar turno supervisor</div>
                    <div style="font-size:0.82rem;color:#6c757d;">${nombre} — ${fecha}</div>
                </div>
                <button id="btn-cancelar-sup-rapido" style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:#6c757d;line-height:1;">×</button>
            </div>
            <div style="margin-bottom:16px;color:#495057;font-size:0.9rem;">Los supervisores se asignan manualmente con horario. No se pueden asignar a puestos normales desde esta vista.</div>
            <div style="display:grid;gap:12px;">
                <div>
                    <label style="display:block;font-size:0.82rem;font-weight:600;color:#495057;margin-bottom:4px;">Hora inicio</label>
                    <input type="time" id="sup-hora-inicio-rapido" style="width:100%;padding:8px 10px;border:1px solid #dee2e6;border-radius:8px;font-size:0.9rem;">
                </div>
                <div>
                    <label style="display:block;font-size:0.82rem;font-weight:600;color:#495057;margin-bottom:4px;">Hora fin</label>
                    <input type="time" id="sup-hora-fin-rapido" style="width:100%;padding:8px 10px;border:1px solid #dee2e6;border-radius:8px;font-size:0.9rem;">
                </div>
                <div id="sup-horas-resumen-rapido" style="display:none;padding:10px 12px;border-radius:10px;background:#f3e8ff;color:#6b21a8;font-size:0.88rem;"></div>
                <div id="sup-validacion-rapido" style="display:none;padding:10px 12px;border-radius:10px;font-size:0.88rem;"></div>
            </div>
            <div style="display:flex;gap:10px;margin-top:20px;">
                <button id="btn-asignar-sup-rapido" style="flex:1;background:#0d6efd;color:white;border:none;border-radius:8px;padding:10px 0;font-weight:700;cursor:pointer;">Guardar supervisor</button>
                <button id="btn-cancelar-sup-rapido-2" style="flex:1;background:#6c757d;color:white;border:none;border-radius:8px;padding:10px 0;font-weight:700;cursor:pointer;">Cancelar</button>
            </div>
        `;

        document.body.appendChild(overlay);
        document.body.appendChild(pop);

        const hi = pop.querySelector('#sup-hora-inicio-rapido');
        const hf = pop.querySelector('#sup-hora-fin-rapido');
        const resumen = pop.querySelector('#sup-horas-resumen-rapido');
        const validacion = pop.querySelector('#sup-validacion-rapido');
        const btnGuardar = pop.querySelector('#btn-asignar-sup-rapido');
        const cerrar = () => { pop.remove(); overlay.remove(); };
        overlay.addEventListener('click', e => { if (e.target === overlay) cerrar(); });

        const actualizarResumen = () => {
            if (!hi.value || !hf.value) {
                resumen.style.display = 'none';
                return;
            }
            const [h1, m1] = hi.value.split(':').map(Number);
            const [h2, m2] = hf.value.split(':').map(Number);
            let minutos = (h2 * 60 + m2) - (h1 * 60 + m1);
            if (minutos < 0) minutos += 24 * 60;
            const hrs = Math.floor(minutos / 60);
            const mins = minutos % 60;
            resumen.innerHTML = `<i class="fas fa-clock" style="margin-right:6px;"></i>Horario: <strong>${hi.value} → ${hf.value}</strong> · Duración: <strong>${hrs}h${mins > 0 ? ' ' + mins + 'min' : ''}</strong>`;
            resumen.style.display = '';
        };

        [hi, hf].forEach(el => el.addEventListener('change', actualizarResumen));
        pop.querySelector('#btn-cancelar-sup-rapido')?.addEventListener('click', cerrar);
        pop.querySelector('#btn-cancelar-sup-rapido-2')?.addEventListener('click', cerrar);

        btnGuardar.addEventListener('click', async () => {
            validacion.style.display = 'none';
            if (!hi.value || !hf.value) {
                validacion.style.display = '';
                validacion.style.background = '#fff3cd';
                validacion.style.color = '#856404';
                validacion.textContent = 'Ingresa la hora de entrada y salida del supervisor.';
                return;
            }
            btnGuardar.disabled = true;
            btnGuardar.textContent = 'Guardando...';
            try {
                const res = await fetch(API_BASE + 'supervisores_turno.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ trabajador_id: trabId, fecha, hora_inicio: hi.value, hora_fin: hf.value, usuario_id: 1 })
                });
                const r = await res.json();
                if (r.success) {
                    mostrarAlerta('✅ Turno supervisor guardado', 'success');
                    cerrar();
                    recargarFilaMensual(trabId);
                } else {
                    validacion.style.display = '';
                    validacion.style.background = '#f8d7da';
                    validacion.style.color = '#721c24';
                    validacion.textContent = r.message || 'Error al guardar turno supervisor.';
                }
            } catch (e) {
                validacion.style.display = '';
                validacion.style.background = '#f8d7da';
                validacion.style.color = '#721c24';
                validacion.textContent = 'Error de conexión.';
            } finally {
                btnGuardar.disabled = false;
                btnGuardar.textContent = 'Guardar supervisor';
            }
        });
        return;
    }

    mostrarSpinner('Cargando opciones...');
    let puestos = [], turnosConf = [];
    try {
        const [rP, rT] = await Promise.all([
            fetch(API_BASE + 'turnos.php?action=puestos').then(r => r.json()),
            fetch(API_BASE + 'turnos.php?action=configuracion').then(r => r.json())
        ]);
        puestos    = (rP.success && rP.data) ? rP.data : [];
        turnosConf = (rT.success && rT.data) ? rT.data.filter(t => [1,2,3,4,5].includes(Number(t.numero_turno))) : [];
    } catch(e) {}
    ocultarSpinner();

    // Deduplicar turnos — preferir L4 (id=9,10) sobre Turno Especial (id=4,5)
    const ID_PREFERIDO_R = { 4: 9, 5: 10 };
    const turnosUnicos = [];
    const vistos = new Set();
    turnosConf
        .filter(t => Object.values(ID_PREFERIDO_R).includes(Number(t.id)))
        .forEach(t => { if (!vistos.has(Number(t.numero_turno))) { vistos.add(Number(t.numero_turno)); turnosUnicos.push(t); }});
    turnosConf.forEach(t => { if (!vistos.has(Number(t.numero_turno))) { vistos.add(Number(t.numero_turno)); turnosUnicos.push(t); }});

    const areasPuestos = {};
    puestos.forEach(p => { if (!areasPuestos[p.area]) areasPuestos[p.area] = []; areasPuestos[p.area].push(p); });
    let optsPuestos = '';
    Object.entries(areasPuestos).forEach(([area, ps]) => {
        optsPuestos += `<optgroup label="${area}">`;
        ps.forEach(p => { optsPuestos += `<option value="${p.id}">${p.codigo} — ${p.nombre}</option>`; });
        optsPuestos += '</optgroup>';
    });
    const nombresR = {1:'T1 — Mañana (06-14h)', 2:'T2 — Tarde (14-22h)', 3:'T3 — Noche (22-06h)', 4:'L4 — 4h Mañana', 5:'L4 — 4h Tarde'};
    let optsTurnos = '<optgroup label="Turnos">';
    optsTurnos += turnosUnicos.filter(t=>[1,2,3].includes(Number(t.numero_turno))).map(t => `<option value="${t.id}" data-numero="${t.numero_turno}">${nombresR[Number(t.numero_turno)]||t.nombre}</option>`).join('');
    optsTurnos += '</optgroup><optgroup label="L4 (4 horas)">';
    optsTurnos += turnosUnicos.filter(t=>[4,5].includes(Number(t.numero_turno))).map(t => `<option value="${t.id}" data-numero="${t.numero_turno}">${nombresR[Number(t.numero_turno)]||t.nombre}</option>`).join('');
    optsTurnos += '</optgroup><optgroup label="Disponibilidades">';
    optsTurnos += `<option value="ADMM" data-tipo="especial">ADMM — Disponible Mañana</option>`;
    optsTurnos += `<option value="ADMT" data-tipo="especial">ADMT — Disponible Tarde</option>`;
    optsTurnos += `<option value="ADM"  data-tipo="especial">ADM — Disponible Día Completo</option>`;
    optsTurnos += '</optgroup>';

    document.getElementById('overlay-rapido-normal')?.remove();
    document.getElementById('popover-rapido-normal')?.remove();
    const overlay = document.createElement('div');
    overlay.id = 'overlay-rapido-normal';
    overlay.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;';
    const pop = document.createElement('div');
    pop.id = 'popover-rapido-normal';
    pop.style.cssText = `position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);
        z-index:10000;background:white;border-radius:14px;padding:24px 28px;
        box-shadow:0 12px 40px rgba(0,0,0,0.25);min-width:340px;`;
    pop.innerHTML = `
        <div style="font-size:1.3rem;text-align:center;margin-bottom:8px;">➕</div>
        <div style="font-weight:700;font-size:1rem;text-align:center;margin-bottom:4px;">Asignar turno</div>
        <div style="font-size:0.82rem;color:#6c757d;text-align:center;margin-bottom:18px;">${nombre} — ${fecha}</div>
        <label style="font-size:0.82rem;font-weight:600;color:#495057;">Turno</label>
        <select id="sel-turno-rapido" style="width:100%;padding:7px 10px;border:1px solid #dee2e6;border-radius:8px;margin:4px 0 12px;font-size:0.88rem;">${optsTurnos}</select>
        <div id="wrap-puesto-rapido">
        <label style="font-size:0.82rem;font-weight:600;color:#495057;">Puesto</label>
        <select id="sel-puesto-rapido" style="width:100%;padding:7px 10px;border:1px solid #dee2e6;border-radius:8px;margin:4px 0 18px;font-size:0.88rem;">${optsPuestos}</select>
        </div>
        <div style="display:flex;gap:8px;">
            <button id="btn-asignar-rapido" style="flex:1;background:#0d6efd;color:white;border:none;border-radius:8px;padding:9px;font-weight:600;cursor:pointer;">Asignar</button>
            <button id="btn-cancelar-rapido-normal" style="background:#6c757d;color:white;border:none;border-radius:8px;padding:9px 16px;cursor:pointer;">Cancelar</button>
        </div>
    `;
    document.body.appendChild(overlay);
    document.body.appendChild(pop);
    const cerrarRapidoNormal = () => { pop.remove(); overlay.remove(); };
    overlay.addEventListener('click', e => { if (e.target === overlay) cerrarRapidoNormal(); });
    pop.querySelector('#btn-cancelar-rapido-normal')?.addEventListener('click', cerrarRapidoNormal);

    // Listener dinámico para filtrar puestos según turno seleccionado
    const PUESTOS_L4_R = new Set(['F5','F15','D2','D1','F11']);
    const optsL4Rapido = (() => {
        let html = '';
        puestos.filter(p => PUESTOS_L4_R.has(p.codigo)).forEach(p => {
            html += `<option value="${p.id}">${p.codigo} — ${p.nombre}</option>`;
        });
        return html;
    })();
    const todosOptsRapido = pop.querySelector('#sel-puesto-rapido').innerHTML;

    pop.querySelector('#sel-turno-rapido').addEventListener('change', function() {
        const num = Number(this.selectedOptions[0]?.dataset?.numero);
        const tipo = this.selectedOptions[0]?.dataset?.tipo;
        const wrapP = pop.querySelector('#wrap-puesto-rapido');
        const selP  = pop.querySelector('#sel-puesto-rapido');
        if (tipo === 'especial' || ['ADMM','ADMT','ADM'].includes(this.value)) {
            wrapP.style.display = 'none';
        } else if (num === 4 || num === 5) {
            wrapP.style.display = '';
            selP.innerHTML = optsL4Rapido;
        } else {
            wrapP.style.display = '';
            selP.innerHTML = todosOptsRapido;
        }
    });
    pop.querySelector('#sel-turno-rapido').dispatchEvent(new Event('change'));

    pop.querySelector('#btn-asignar-rapido').onclick = async () => {
        const selTurno = pop.querySelector('#sel-turno-rapido');
        const turnoId  = selTurno.value;
        const puestoId = pop.querySelector('#sel-puesto-rapido')?.value;
        const tipoTurno = selTurno.selectedOptions[0]?.dataset?.tipo || 'normal';
        pop.remove(); overlay.remove();
        mostrarSpinner('Asignando turno...');
        try {
            let res, r;
            if (tipoTurno === 'especial' || ['ADMM','ADMT','ADM'].includes(turnoId)) {
                // Verificar si ya existe este tipo especial ese día
                const checkRes = await fetch(API_BASE + 'dias_especiales.php?fecha_inicio=' + fecha + '&fecha_fin=' + fecha + '&trabajador_id=' + trabId);
                const checkData = await checkRes.json();
                if (checkData.success && checkData.data.some(de => de.tipo === turnoId)) {
                    ocultarSpinner();
                    mostrarAlerta('❌ Ya tiene ' + turnoId + ' asignado ese día', 'danger');
                    return;
                }
                // ADMM/ADMT/ADM van a dias_especiales
                res = await fetch(API_BASE + 'dias_especiales.php', {
                    method: 'POST',
                    headers: {'Content-Type':'application/json'},
                    body: JSON.stringify({ trabajador_id: trabId, tipo: turnoId, fecha_inicio: fecha, fecha_fin: null, descripcion: '', estado: 'programado' })
                });
            } else {
                // Turnos normales y L4 van a turnos.php
                res = await fetch(API_BASE + 'turnos.php', {
                    method: 'POST',
                    headers: {'Content-Type':'application/json'},
                    body: JSON.stringify({ trabajador_id: trabId, puesto_trabajo_id: puestoId, turno_id: turnoId, fecha, estado: 'programado', created_by: 1 })
                });
            }
            r = await res.json();
            ocultarSpinner();
            if (r.success) {
                mostrarAlerta('✅ Turno asignado a ' + nombre, 'success');
                recargarFilaMensual(trabId);
            } else {
                mostrarAlerta('Error: ' + (r.message || r.error), 'danger');
            }
        } catch(e) { ocultarSpinner(); mostrarAlerta('Error de conexión', 'danger'); }
    };
}

async function agregarDiaLibreMensual(trabId, fecha, nombre) {
    document.getElementById('popover-mensual')?.remove();
    const ok = await confirmarAccion({
        titulo:   'Asignar día libre',
        mensaje:  `¿Asignar día libre a <b>${nombre}</b> el <b>${fecha}</b>?`,
        textoBtn: 'Asignar', tipoBtn: 'primary', icono: 'fa-calendar-check'
    });
    if (!ok) return;
    mostrarSpinner('Asignando día libre...');
    try {
        const res = await fetch(API_BASE + 'dias_especiales.php', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ 
                trabajador_id: trabId, 
                tipo: 'L', 
                fecha_inicio: fecha, 
                fecha_fin: null,
                horas_inicio: null,
                horas_fin: null,
                descripcion: '', 
                estado: 'programado' 
            })
        });
        const r = await res.json();
        ocultarSpinner();
        if (r.success) {
            mostrarAlerta('✅ Día libre asignado a ' + nombre, 'success');
            recargarFilaMensual(trabId);
        } else {
            mostrarAlerta('Error: ' + r.message, 'danger');
        }
    } catch(e) { ocultarSpinner(); mostrarAlerta('Error de conexión', 'danger'); }
}

async function cambiarDiaLibreMensual(trabId, fechaActual, nombre) {
    document.getElementById('popover-mensual')?.remove();

    // Pedir nueva fecha con un prompt usando confirmarAccion personalizado
    const pop2 = document.createElement('div');
    pop2.style.cssText = `position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);
        z-index:10000;background:white;border-radius:14px;padding:28px 32px;
        box-shadow:0 12px 40px rgba(0,0,0,0.25);min-width:320px;text-align:center;`;
    pop2.innerHTML = `
        <div style="font-size:1.5rem;margin-bottom:10px;">📅</div>
        <div style="font-weight:700;font-size:1rem;margin-bottom:6px;">Cambiar día libre</div>
        <div style="font-size:0.85rem;color:#6c757d;margin-bottom:16px;">${nombre} — actualmente: ${fechaActual}</div>
        <input type="date" id="nueva-fecha-libre" value="${fechaActual}"
            style="width:100%;padding:8px 12px;border:1px solid #dee2e6;border-radius:8px;font-size:0.95rem;margin-bottom:16px;">
        <div style="display:flex;gap:8px;justify-content:center;">
            <button id="btn-confirmar-cambio" style="background:#025B2D;color:white;border:none;border-radius:8px;padding:8px 20px;font-weight:600;cursor:pointer;">Confirmar</button>
            <button onclick="this.closest('div[style]').remove()" style="background:#6c757d;color:white;border:none;border-radius:8px;padding:8px 20px;cursor:pointer;">Cancelar</button>
        </div>
    `;
    const overlay2 = document.createElement('div');
    overlay2.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;';
    document.body.appendChild(overlay2);
    document.body.appendChild(pop2);

    pop2.querySelector('#btn-confirmar-cambio').onclick = async () => {
        const nuevaFecha = pop2.querySelector('#nueva-fecha-libre').value;
        if (!nuevaFecha || nuevaFecha === fechaActual) { pop2.remove(); overlay2.remove(); return; }
        pop2.remove(); overlay2.remove();
        mostrarSpinner('Actualizando día libre...');
        try {
            // 1. Eliminar el libre actual
            const delRes = await fetch(API_BASE + 'dias_especiales.php?trabajador_id=' + trabId + '&fecha=' + fechaActual + '&tipo=L', { method: 'DELETE' });
            // 2. Crear el nuevo
            const addRes = await fetch(API_BASE + 'dias_especiales.php', {
                method: 'POST',
                headers: {'Content-Type':'application/json'},
                body: JSON.stringify({ 
                    trabajador_id: trabId, 
                    tipo: 'L', 
                    fecha_inicio: nuevaFecha, 
                    fecha_fin: null,
                    horas_inicio: null,
                    horas_fin: null,
                    descripcion: '', 
                    estado: 'programado' 
                })
            });
            const r = await addRes.json();
            ocultarSpinner();
            if (r.success) {
                mostrarAlerta('✅ Día libre movido al ' + nuevaFecha, 'success');
                recargarFilaMensual(trabId);
            } else {
                mostrarAlerta('Error: ' + r.message, 'danger');
            }
        } catch(e) { ocultarSpinner(); mostrarAlerta('Error de conexión', 'danger'); }
    };
}

async function eliminarDiaLibreMensual(trabId, fecha, nombre) {
    document.getElementById('popover-mensual')?.remove();
    const ok = await confirmarAccion({
        titulo:   'Eliminar día libre',
        mensaje:  `¿Eliminar el día libre de <b>${nombre}</b> el <b>${fecha}</b>?`,
        textoBtn: 'Eliminar', tipoBtn: 'danger', icono: 'fa-trash'
    });
    if (!ok) return;
    mostrarSpinner('Eliminando...');
    try {
        const res = await fetch(API_BASE + 'dias_especiales.php?trabajador_id=' + trabId + '&fecha=' + fecha + '&tipo=L', { method: 'DELETE' });
        const r = await res.json();
        ocultarSpinner();
        if (r.success) {
            mostrarAlerta('✅ Día libre eliminado', 'success');
            recargarFilaMensual(trabId);
        } else {
            mostrarAlerta('Error: ' + r.message, 'danger');
        }
    } catch(e) { ocultarSpinner(); mostrarAlerta('Error de conexión', 'danger'); }
}

async function eliminarTurnoMensual(trabId, fecha, nombre, turnoId) {
    document.getElementById('popover-mensual')?.remove();
    const ok = await confirmarAccion({
        titulo:   'Eliminar turno',
        mensaje:  `¿Eliminar el turno de <b>${nombre}</b> el <b>${fecha}</b>?`,
        textoBtn: 'Eliminar', tipoBtn: 'danger', icono: 'fa-trash'
    });
    if (!ok) return;
    mostrarSpinner('Eliminando turno...');
    try {
        const res = await fetch(API_BASE + 'turnos.php', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ action: 'cancelar', id: turnoId })
        });
        const r = await res.json();
        ocultarSpinner();
        if (r.success) {
            mostrarAlerta('✅ Turno eliminado', 'success');
            recargarFilaMensual(trabId);
        } else {
            mostrarAlerta('Error: ' + r.message, 'danger');
        }
    } catch(e) { ocultarSpinner(); mostrarAlerta('Error de conexión', 'danger'); }
}

// ─── RECARGA PARCIAL VISTA MENSUAL (sin recargar toda la grilla) ─────────────

async function recargarFilaMensual(trabId) {
    // Obtener el mes/año actual de la grilla
    const tituloGrilla = document.querySelector('#grilla-mensual')?.previousElementSibling?.querySelector('strong, b, [style*="font-weight:700"]');
    // Leer mes/año del estado global
    const mes  = window._grillaMes  ?? (new Date().getMonth() + 1);
    const anio = window._grillaAnio ?? new Date().getFullYear();

    const primerDia = `${anio}-${String(mes).padStart(2,'0')}-01`;
    const diasMes   = new Date(anio, mes, 0).getDate();
    const ultimoDia = `${anio}-${String(mes).padStart(2,'0')}-${String(diasMes).padStart(2,'0')}`;

    try {
        // Cargar solo datos del trabajador afectado
        const [rT, rDE, rI] = await Promise.all([
            fetch(`${API_BASE}turnos.php?fecha_inicio=${primerDia}&fecha_fin=${ultimoDia}&trabajador_id=${trabId}`).then(r=>r.json()),
            fetch(`${API_BASE}dias_especiales.php?fecha_inicio=${primerDia}&fecha_fin=${ultimoDia}&trabajador_id=${trabId}`).then(r=>r.json()),
            fetch(`${API_BASE}incapacidades.php?fecha_inicio=${primerDia}&fecha_fin=${ultimoDia}&trabajador_id=${trabId}`).then(r=>r.json())
        ]);

        // Reconstruir índice solo para este trabajador
        const idx = {};
        if (rT.success) rT.data.filter(t => t.estado !== 'cancelado').forEach(t => {
            if (!idx[t.fecha]) idx[t.fecha] = [];
            idx[t.fecha].push(t);
        });
        if (rDE.success && rDE.data) rDE.data.forEach(de => {
            if (!['programado','activo'].includes(de.estado)) return;
            const ini = new Date(de.fecha_inicio + 'T00:00:00');
            const fin = de.fecha_fin ? new Date(de.fecha_fin + 'T00:00:00') : ini;
            for (let d = new Date(ini); d <= fin; d.setDate(d.getDate()+1)) {
                const f = d.toISOString().split('T')[0];
                if (!idx[f]) idx[f] = [];
                if (!idx[f].some(x => x.tipo_especial === de.tipo))
                    idx[f].push({ id: de.id||null, tipo_especial: de.tipo, descripcion: de.descripcion||'', estado: de.estado, trabajador_id: trabId });
            }
        });
        if (rI.success && rI.data) rI.data.forEach(inc => {
            if (inc.estado !== 'activa') return;
            const ini = new Date(inc.fecha_inicio + 'T00:00:00');
            const fin = new Date(inc.fecha_fin    + 'T00:00:00');
            for (let d = new Date(ini); d <= fin; d.setDate(d.getDate()+1)) {
                const f = d.toISOString().split('T')[0];
                if (!idx[f]) idx[f] = [];
                if (!idx[f].some(x => x.tipo_especial === 'INC'))
                    idx[f].push({ tipo_especial: 'INC', descripcion: inc.tipo_incapacidad||'Incapacidad', estado: 'activa', trabajador_id: trabId });
            }
        });

        // Actualizar cada celda de la fila en el DOM
        const fila = document.querySelector(`tr[data-trab-id="${trabId}"]`);
        if (!fila) { cargarVistaMensual(); return; } // fallback si no existe

        // Cerrar popover pequeño antes de repintar
        document.getElementById('popover-mensual')?.remove();

        fila.querySelectorAll('td[data-fecha]').forEach(td => {
            const f     = td.getAttribute('data-fecha');
            const asigs = idx[f] || [];
            // Actualizar data-asigs para que el próximo clic tenga datos frescos
            const asigsSafe = JSON.stringify(asigs.map(a => ({
                id: a.id||null, tipo_especial: a.tipo_especial||null,
                descripcion: a.descripcion||'', estado: a.estado||'',
                numero_turno: a.numero_turno||null, puesto_codigo: a.puesto_codigo||null
            }))).replace(/'/g, '&#39;').replace(/"/g, '&quot;');
            td.setAttribute('data-asigs', asigsSafe);
            td.innerHTML = renderCeldaMensual(asigs, trabId, f, td.getAttribute('data-nombre'));
        });

    } catch(e) {
        cargarVistaMensual(); // fallback
    }
}

function renderCeldaMensual(asigs, trabId, fecha, nombre) {
    if (asigs.length === 0) return '<span style="color:#ced4da;font-size:0.7rem;">—</span>';

    const COLORES = {
        'L':    { bg:'#cce5ff',  color:'#004085' },
        'L8':   { bg:'#cce5ff',  color:'#004085' },
        'LC':   { bg:'#b8daff',  color:'#003580' },
        'VAC':  { bg:'#d4edda',  color:'#155724' },
        'SUS':  { bg:'#fff3cd',  color:'#856404' },
        'INC':  { bg:'#f8d7da',  color:'#721c24' },
        'ADMM': { bg:'#fde8d8',  color:'#7d3800' },
        'ADMT': { bg:'#fde8d8',  color:'#7d3800' },
        'ADM':  { bg:'#fde8d8',  color:'#7d3800' }
    };

    return asigs.map(a => {
        let etiqueta, bg, color;
        if (a.tipo_especial) {
            const cfg   = COLORES[a.tipo_especial] || { bg:'#e9ecef', color:'#495057' };
            const esAuto = a.tipo_especial === 'L' && (a.descripcion||'').startsWith('AUTO:');
            etiqueta = esAuto ? 'L⚡' : a.tipo_especial;
            bg    = esAuto ? '#fd7e14' : cfg.bg;
            color = esAuto ? 'white'   : cfg.color;
        } else {
            const origNum = Number(a.numero_turno) || 0;
            let num = origNum;
            if (origNum === 4 || origNum === 9)  num = 1;
            if (origNum === 5 || origNum === 10) num = 2;
            etiqueta = origNum >= 4 ? `${num}${a.puesto_codigo||''}L4` : `${num}${a.puesto_codigo||''}`;
            bg    = num===1?'#d1ecf1':num===2?'#fff3cd':'#1a1a2e';
            color = num===1?'#0c5460':num===2?'#856404':'#e0e0e0';
        }
        const esAusente = a.estado === 'no_presentado';
        if (esAusente) { bg = '#dc3545'; color = 'white'; }
        return `<span style="display:inline-block;background:${bg};color:${color};padding:2px 5px;border-radius:4px;font-weight:700;font-size:0.72rem;margin:1px;white-space:nowrap;">${esAusente?'TNR':etiqueta}</span>`;
    }).join('');
}

// ─── BARRA DE PROGRESO ASIGNACIÓN AUTOMÁTICA ─────────────────────────────────
function mostrarProgreso(titulo, mensaje, porcentaje) {
    let overlay = document.getElementById('progreso-overlay');
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.id = 'progreso-overlay';
        overlay.style.cssText = `
            position:fixed;top:0;left:0;width:100%;height:100%;
            background:rgba(0,0,0,0.65);z-index:9999;
            display:flex;align-items:center;justify-content:center;
        `;
        overlay.innerHTML = `
            <div style="background:white;border-radius:16px;padding:2.5rem 3rem;
                        min-width:380px;max-width:480px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,0.3);
                        text-align:center;">
                <div style="font-size:2.5rem;margin-bottom:1rem;">⚙️</div>
                <div id="prog-titulo" style="font-size:1.1rem;font-weight:700;color:#1a1a2e;margin-bottom:0.5rem;"></div>
                <div id="prog-mensaje" style="font-size:0.88rem;color:#6c757d;margin-bottom:1.5rem;min-height:1.2em;"></div>
                <div style="background:#e9ecef;border-radius:20px;height:12px;overflow:hidden;margin-bottom:0.8rem;">
                    <div id="prog-barra" style="height:100%;border-radius:20px;
                         background:linear-gradient(90deg,#025B2D,#0d9e50);
                         transition:width 0.4s ease;width:0%;"></div>
                </div>
                <div id="prog-pct" style="font-size:0.82rem;color:#495057;font-weight:600;">0%</div>
            </div>
        `;
        document.body.appendChild(overlay);
    }
    overlay.style.display = 'flex';
    document.getElementById('prog-titulo').textContent  = titulo;
    document.getElementById('prog-mensaje').textContent = mensaje;
    document.getElementById('prog-barra').style.width   = porcentaje + '%';
    document.getElementById('prog-pct').textContent     = Math.round(porcentaje) + '%';
}

function ocultarProgreso() {
    const overlay = document.getElementById('progreso-overlay');
    if (overlay) overlay.style.display = 'none';
}

function mostrarSpinner(mensaje = 'Procesando...') {
    let sp = document.getElementById('global-spinner');
    if (!sp) {
        sp = document.createElement('div');
        sp.id = 'global-spinner';
        sp.innerHTML = `
            <div class="spinner-inner">
                <div class="spinner-ring"></div>
                <p class="spinner-msg" id="spinner-msg">${mensaje}</p>
            </div>`;
        document.body.appendChild(sp);
    } else {
        document.getElementById('spinner-msg').textContent = mensaje;
        sp.style.display = 'flex';
    }
}

function ocultarSpinner() {
    const sp = document.getElementById('global-spinner');
    if (sp) sp.style.display = 'none';
}

// ─── HISTORIAL DE CAMBIOS ────────────────────────────────────────────────────
const HISTORIAL_CAMBIOS = [];

function registrarCambio(accion, detalle) {
    HISTORIAL_CAMBIOS.unshift({
        accion,
        detalle,
        hora: new Date().toLocaleTimeString('es-CO', { hour: '2-digit', minute: '2-digit' }),
        fecha: new Date().toLocaleDateString('es-CO')
    });
    if (HISTORIAL_CAMBIOS.length > 50) HISTORIAL_CAMBIOS.pop();
    actualizarBadgeHistorial();
}

function actualizarBadgeHistorial() {
    const badge = document.getElementById('historial-badge');
    if (badge) {
        badge.textContent = HISTORIAL_CAMBIOS.length;
        badge.style.display = HISTORIAL_CAMBIOS.length > 0 ? 'inline-flex' : 'none';
    }
}

function verHistorial() {
    const modalOverlay = document.getElementById('modal-overlay');
    const modalTitulo  = document.getElementById('modal-titulo');
    const modalBody    = document.getElementById('modal-body');

    modalTitulo.textContent = '📋 Historial de cambios';

    if (HISTORIAL_CAMBIOS.length === 0) {
        modalBody.innerHTML = '<div style="text-align:center;padding:2rem;color:#6c757d;"><i class="fas fa-history" style="font-size:2.5rem;opacity:0.3;"></i><p style="margin-top:1rem;">No hay cambios registrados en esta sesión.</p></div>';
    } else {
        let html = '<div style="max-height:420px;overflow-y:auto;">';
        html += '<p style="color:#6c757d;font-size:0.82rem;margin-bottom:1rem;">Cambios de la sesión actual (últimos 50)</p>';
        HISTORIAL_CAMBIOS.forEach((c, i) => {
            const isFirst = i === 0;
            html += `<div style="display:flex;gap:12px;padding:10px 0;border-bottom:1px solid #f0f0f0;${isFirst?'background:#f8fff9;border-radius:6px;padding:10px;margin-bottom:4px;':''}">
                <div style="flex-shrink:0;width:36px;height:36px;background:${isFirst?'#025B2D':'#e9ecef'};border-radius:50%;display:flex;align-items:center;justify-content:center;">
                    <i class="fas fa-edit" style="font-size:0.8rem;color:${isFirst?'white':'#6c757d'};"></i>
                </div>
                <div style="flex:1;">
                    <strong style="font-size:0.88rem;color:#212529;">${c.accion}</strong>
                    <p style="margin:2px 0 0;font-size:0.82rem;color:#6c757d;">${c.detalle}</p>
                </div>
                <div style="flex-shrink:0;text-align:right;">
                    <span style="font-size:0.78rem;color:#adb5bd;">${c.hora}</span><br>
                    <span style="font-size:0.75rem;color:#ced4da;">${c.fecha}</span>
                </div>
            </div>`;
        });
        html += '</div>';
        modalBody.innerHTML = html;
    }
    modalOverlay.classList.add('active');
}


// ─── MODAL DE CONFIRMACIÓN PERSONALIZADO ────────────────────────────────────
function confirmarAccion({ titulo, mensaje, textoBtn = 'Confirmar', tipoBtn = 'danger', icono = 'fa-exclamation-triangle' }) {
    return new Promise(resolve => {
        const overlay = document.createElement('div');
        overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:99999;display:flex;align-items:center;justify-content:center;animation:fadeInOverlay 0.15s ease;';

        const COLORES = {
            danger:  { bg: '#dc3545', light: '#fff5f5', border: '#f5c6cb', icon: '#dc3545' },
            warning: { bg: '#ffc107', light: '#fffbf0', border: '#ffeeba', icon: '#856404' },
            primary: { bg: '#025B2D', light: '#f0faf4', border: '#b7dfca', icon: '#025B2D' },
        };
        const c = COLORES[tipoBtn] || COLORES.danger;

        overlay.innerHTML = `
            <div style="background:white;border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,0.25);width:100%;max-width:420px;margin:20px;overflow:hidden;animation:slideUpModal 0.2s ease;">
                <div style="background:${c.light};border-bottom:1px solid ${c.border};padding:24px 28px 20px;text-align:center;">
                    <div style="width:56px;height:56px;background:${c.light};border:2px solid ${c.border};border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 14px;">
                        <i class="fas ${icono}" style="font-size:1.5rem;color:${c.icon};"></i>
                    </div>
                    <h3 style="margin:0 0 8px;font-size:1.1rem;color:#212529;font-weight:700;">${titulo}</h3>
                    <p style="margin:0;color:#6c757d;font-size:0.92rem;line-height:1.5;">${mensaje}</p>
                </div>
                <div style="padding:18px 28px;display:flex;gap:10px;justify-content:flex-end;background:#fafafa;">
                    <button id="confirm-cancel-btn" style="padding:9px 20px;border:1px solid #dee2e6;border-radius:8px;background:white;color:#495057;font-size:0.9rem;cursor:pointer;font-weight:500;transition:all 0.15s;">
                        Cancelar
                    </button>
                    <button id="confirm-ok-btn" style="padding:9px 20px;border:none;border-radius:8px;background:${c.bg};color:white;font-size:0.9rem;cursor:pointer;font-weight:600;transition:all 0.15s;">
                        ${textoBtn}
                    </button>
                </div>
            </div>`;

        document.body.appendChild(overlay);

        const ok  = overlay.querySelector('#confirm-ok-btn');
        const can = overlay.querySelector('#confirm-cancel-btn');

        ok.onmouseenter  = () => ok.style.opacity  = '0.88';
        ok.onmouseleave  = () => ok.style.opacity  = '1';
        can.onmouseenter = () => can.style.background = '#f8f9fa';
        can.onmouseleave = () => can.style.background = 'white';

        const cerrar = (val) => { overlay.remove(); resolve(val); };
        ok.onclick  = () => cerrar(true);
        can.onclick = () => cerrar(false);
        overlay.onclick = (e) => { if (e.target === overlay) cerrar(false); };
    });
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

async function editarAsignacion(turnoId, puestoId, fecha, numeroTurno, trabajadorActualId, puestoCodigo='', tieneL4=false, l4TurnoId=null) {
  // turnoId=0 significa celda vacía — nueva asignación
  const esNuevo = !turnoId;

  // Mapa numero_turno → turno_id de configuracion_turnos
  const TURNO_ID_MAP = { 1: 1, 2: 2, 3: 3 };

  const modalOverlay = document.getElementById('modal-overlay');
  const modalTitulo  = document.getElementById('modal-titulo');
  const modalBody    = document.getElementById('modal-body');

  modalTitulo.textContent = esNuevo ? 'Asignar turno' : 'Editar asignación';
  modalBody.innerHTML = '<p>Cargando trabajadores...</p>';
  modalOverlay.classList.add('active');

  try {
    // Cargar trabajadores, turnos del día, días libres y puestos en paralelo
    const [resTodos, resDia, resPuestos, resDiasEsp] = await Promise.all([
      fetch(API_BASE + 'trabajadores.php'),
      fetch(API_BASE + 'turnos.php?fecha=' + fecha),
      fetch(API_BASE + 'turnos.php?action=puestos'),
      fetch(API_BASE + 'dias_especiales.php?fecha=' + fecha)
    ]);
    const dataTodos   = await resTodos.json();
    const dataDia     = await resDia.json();
    const dataPuestos = await resPuestos.json();
    const dataDiasEsp = await resDiasEsp.json();

    if (!dataTodos.success) {
      modalBody.innerHTML = '<p class="alert alert-danger">Error cargando trabajadores</p>';
      return;
    }

    // Construir set de trabajadores con turno ese día (excluyendo el turno que estamos editando)
    const conTurnoHoy = new Set();
    const turnosHoy   = dataDia.success ? dataDia.data.filter(t => t.estado !== 'cancelado' && t.id != turnoId) : [];
    turnosHoy.forEach(t => conTurnoHoy.add(Number(t.trabajador_id)));

    // Construir set de trabajadores con día libre ese día
    const conDiaLibreHoy = new Set();
    const diasLibre = dataDiasEsp.success ? dataDiasEsp.data.filter(d => d.estado !== 'cancelado') : [];
    diasLibre.forEach(d => conDiaLibreHoy.add(Number(d.trabajador_id)));

    const todosActivos = (dataTodos.data || []).filter(t => t.activo);
    const todos = todosActivos.filter(t => String(t.cargo || '').toLowerCase() !== 'supervisor');

    // Separar en tres categorías
    const disponibles = todos.filter(t => !conTurnoHoy.has(t.id) && !conDiaLibreHoy.has(t.id));
    const ocupados    = todos.filter(t => conTurnoHoy.has(t.id));
    const conLibre    = todos.filter(t => conDiaLibreHoy.has(t.id));

    // Construir opciones con separadores
    let opciones = '<option value="">Seleccione trabajador...</option>';

    if (disponibles.length > 0) {
      opciones += '<optgroup label="✅ Disponibles hoy">';
      disponibles.forEach(t => {
        opciones += `<option value="${t.id}" ${t.id == trabajadorActualId ? 'selected' : ''}>${t.nombre} — ${t.cedula}</option>`;
      });
      opciones += '</optgroup>';
    }

    if (ocupados.length > 0) {
      opciones += '<optgroup label="⚠️ Ya tienen turno este día">';
      ocupados.forEach(t => {
        // Buscar qué turno tiene para mostrarlo
        const turnoActual = turnosHoy.find(x => Number(x.trabajador_id) === t.id);
        const detalle = turnoActual
          ? ` (${turnoActual.puesto_codigo || turnoActual.tipo_especial || 'T'+turnoActual.numero_turno})`
          : '';
        opciones += `<option value="${t.id}" ${t.id == trabajadorActualId ? 'selected' : ''}>${t.nombre} — ${t.cedula}${detalle}</option>`;
      });
      opciones += '</optgroup>';
    }

    if (conLibre.length > 0) {
      opciones += '<optgroup label="🏖️ Con día libre este día">';
      conLibre.forEach(t => {
        const diaLibre = diasLibre.find(d => Number(d.trabajador_id) === t.id);
        const tipoLibre = diaLibre ? diaLibre.tipo : 'L';
        opciones += `<option value="${t.id}" ${t.id == trabajadorActualId ? 'selected' : ''}>${t.nombre} — ${t.cedula} (${tipoLibre})</option>`;
      });
      opciones += '</optgroup>';
    }

    // Panel informativo con estadísticas
    const panelEstadisticas = `
      <div style="background:#f8f9fa;border:1px solid #dee2e6;border-radius:8px;padding:12px;margin-bottom:16px;font-size:0.85rem;">
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;">
          <div style="text-align:center;padding:8px;background:white;border-radius:6px;border-left:4px solid #28a745;">
            <div style="font-size:1.8rem;font-weight:700;color:#28a745;">${disponibles.length}</div>
            <div style="font-size:0.75rem;color:#6c757d;margin-top:2px;">✅ DISPONIBLES</div>
            <div style="font-size:0.7rem;color:#999;margin-top:2px;">Sin turno ni día libre</div>
          </div>
          <div style="text-align:center;padding:8px;background:white;border-radius:6px;border-left:4px solid #dc3545;">
            <div style="font-size:1.8rem;font-weight:700;color:#dc3545;">${ocupados.length}</div>
            <div style="font-size:0.75rem;color:#6c757d;margin-top:2px;">⚠️ CON TURNO</div>
            <div style="font-size:0.7rem;color:#999;margin-top:2px;">Ya asignados hoy</div>
          </div>
          <div style="text-align:center;padding:8px;background:white;border-radius:6px;border-left:4px solid #ffc107;">
            <div style="font-size:1.8rem;font-weight:700;color:#ffc107;">${conLibre.length}</div>
            <div style="font-size:0.75rem;color:#6c757d;margin-top:2px;">🏖️ DÍA LIBRE</div>
            <div style="font-size:0.7rem;color:#999;margin-top:2px;">Día libre programado</div>
          </div>
        </div>
      </div>
    `;

    // Sección L4 si el puesto tiene esa opción para este turno
    const numNormActual = [4,9].includes(Number(numeroTurno)) ? 1 : [5,10].includes(Number(numeroTurno)) ? 2 : Number(numeroTurno);
    const seccionL4 = (tieneL4 && l4TurnoId) ? `
      <div style="margin:12px 0;padding:12px;background:#f3e8ff;border-radius:8px;border:1px solid #d8b4fe;">
        <div style="font-size:0.8rem;font-weight:700;color:#6a1b9a;margin-bottom:8px;">⚡ Opción L4 — Turno de 4 horas</div>
        <div style="font-size:0.78rem;color:#6c757d;margin-bottom:8px;">
          En lugar del turno completo, asignar un L4 de media jornada en este puesto.
        </div>
        <select id="sel-trab-l4" style="width:100%;padding:7px;border:1px solid #d8b4fe;border-radius:6px;font-size:0.85rem;margin-bottom:8px;">
          <option value="">— Seleccionar trabajador para L4 —</option>
          ${opciones}
        </select>
        <button type="button" id="btn-guardar-l4" style="width:100%;padding:8px;background:#6a1b9a;color:white;border:none;border-radius:6px;font-weight:600;font-size:0.84rem;cursor:pointer;">
          Asignar L4 en ${puestoCodigo}
        </button>
      </div>
      <div style="text-align:center;font-size:0.78rem;color:#adb5bd;margin:4px 0;">— o asignar turno completo —</div>
    ` : '';

    // Construir opciones de puestos compatibles con este turno para el selector de mover
    const puestosDisp = (dataPuestos.success ? dataPuestos.data : []);
    const NOCTURNOS_COD = new Set(['V1','V2','C','D3','F6','F11']);
    const puestosFiltrados = puestosDisp.filter(p => {
        if (numNormActual === 3) return NOCTURNOS_COD.has(p.codigo);
        return !new Set(['V1','V2','C']).has(p.codigo); // en T1/T2 no mostrar exclusivos nocturnos
    });
    const optsPuestoDestino = puestosFiltrados.map(p =>
        `<option value="${p.id}" ${p.id == puestoId ? 'selected' : ''}>${p.codigo} — ${p.nombre}</option>`
    ).join('');

    const seccionMover = !esNuevo ? `
      <div style="margin:10px 0 4px;">
        <label style="font-size:0.82rem;font-weight:700;color:#495057;display:block;margin-bottom:4px;">
          📍 Puesto destino
          <span style="font-weight:400;color:#6c757d;font-size:0.78rem;"> — cambia si quieres mover a otro puesto</span>
        </label>
        <select id="sel-puesto-destino" style="width:100%;padding:8px;border:1px solid #dee2e6;border-radius:8px;font-size:0.85rem;">
          ${optsPuestoDestino}
        </select>
      </div>` : '';

    modalBody.innerHTML = `
      <form id="form-editar-asignacion">
        <div class="form-grid">
          <div class="form-group">
            <label>Fecha</label>
            <input type="text" disabled value="${fecha}" style="background:#f8f9fa;">
          </div>
          <div class="form-group">
            <label>Turno</label>
            <input type="text" disabled value="T${numNormActual} — ${numNormActual===1?'Mañana':numNormActual===2?'Tarde':'Noche'}" style="background:#f8f9fa;">
          </div>
        </div>
        ${seccionMover}
        ${seccionL4}
        ${panelEstadisticas}
        <div class="form-group">
          <label for="nuevo-trabajador-select">Selecciona un trabajador <span class="required">*</span></label>
          <select id="nuevo-trabajador-select" required style="width:100%;">${opciones}</select>
          <small style="color:#6c757d;margin-top:4px;display:block;">
            Elige entre los disponibles, o revisa quién tiene turno/día libre.
          </small>
        </div>
        <div id="aviso-conflicto" style="display:none;" class="alert alert-warning">
          <i class="fas fa-exclamation-triangle"></i> <strong>Atención:</strong> Este trabajador ya tiene turno el <strong>${fecha}</strong>.
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Guardar</button>
          <button type="button" class="btn btn-outline" onclick="cerrarModal()"><i class="fas fa-times"></i> Cancelar</button>
        </div>
      </form>
    `;

    // Mostrar aviso de conflicto al seleccionar alguien ocupado o con día libre
    document.getElementById('nuevo-trabajador-select').addEventListener('change', function() {
      const selId = Number(this.value);
      const aviso = document.getElementById('aviso-conflicto');
      const tieneConflicto = conTurnoHoy.has(selId) || conDiaLibreHoy.has(selId);
      
      if (tieneConflicto) {
        const tipoConflicto = conTurnoHoy.has(selId) ? 'turno' : 'día libre';
        aviso.innerHTML = `<i class="fas fa-exclamation-triangle"></i> <strong>Atención:</strong> Este trabajador ya tiene ${tipoConflicto} el <strong>${fecha}</strong>.`;
        aviso.style.display = 'block';
      } else {
        aviso.style.display = 'none';
      }
    });

    // Disparar al cargar si el actual ya tiene conflicto
    if (conTurnoHoy.has(Number(trabajadorActualId)) || conDiaLibreHoy.has(Number(trabajadorActualId))) {
      const tipoConflicto = conTurnoHoy.has(Number(trabajadorActualId)) ? 'turno' : 'día libre';
      document.getElementById('aviso-conflicto').innerHTML = `<i class="fas fa-exclamation-triangle"></i> <strong>Atención:</strong> Este trabajador ya tiene ${tipoConflicto} el <strong>${fecha}</strong>.`;
      document.getElementById('aviso-conflicto').style.display = 'block';
    }

    // Handler botón L4
    if (tieneL4 && l4TurnoId) {
      document.getElementById('btn-guardar-l4')?.addEventListener('click', async () => {
        const trabL4Id = document.getElementById('sel-trab-l4').value;
        if (!trabL4Id) { mostrarAlerta('Selecciona un trabajador para L4', 'warning'); return; }
        cerrarModal();
        mostrarSpinner('Asignando L4...');
        try {
          // Cancelar el turno actual si existe
          if (turnoId) {
            await fetch(API_BASE + 'turnos.php', {
              method: 'POST', headers: {'Content-Type':'application/json'},
              body: JSON.stringify({ action: 'cancelar', id: turnoId })
            });
          }
          const res = await fetch(API_BASE + 'turnos.php', {
            method: 'POST', headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ trabajador_id: trabL4Id, puesto_trabajo_id: puestoId, turno_id: l4TurnoId, fecha, estado: 'programado', created_by: 1 })
          });
          const r = await res.json();
          ocultarSpinner();
          if (r.success) {
            mostrarAlerta('✅ L4 asignado en ' + puestoCodigo, 'success');
            cargarVistaDiaria();
          } else {
            mostrarAlerta('❌ ' + (r.message || r.error || 'Error al asignar L4'), 'danger');
          }
        } catch(e) { ocultarSpinner(); mostrarAlerta('Error de conexión', 'danger'); }
      });
    }

    document.getElementById('form-editar-asignacion').addEventListener('submit', async function(e) {
      e.preventDefault();
      const nuevoId = document.getElementById('nuevo-trabajador-select').value;
      if (!nuevoId) { mostrarAlerta('Seleccione un trabajador', 'warning'); return; }

      try {
        const puestoDestino = document.getElementById('sel-puesto-destino')?.value || puestoId;
        const turnoIdBase    = TURNO_ID_MAP[Number(numeroTurno)] || numeroTurno;
        const puestoCambio   = String(puestoDestino) !== String(puestoId);

        let result;
        if (esNuevo) {
          // Nueva asignación directa
          const res = await fetch(`${API_BASE}turnos.php`, {
            method: 'POST', headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ trabajador_id: nuevoId, puesto_trabajo_id: puestoDestino, turno_id: turnoIdBase, fecha, estado: 'programado', created_by: 1 })
          });
          result = await res.json();
        } else if (puestoCambio) {
          // Mover a otro puesto: cancelar el actual + crear en el nuevo
          await fetch(`${API_BASE}turnos.php`, {
            method: 'POST', headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ action: 'cancelar', id: turnoId })
          });
          const res = await fetch(`${API_BASE}turnos.php`, {
            method: 'POST', headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ trabajador_id: nuevoId, puesto_trabajo_id: puestoDestino, turno_id: turnoIdBase, fecha, estado: 'programado', created_by: 1 })
          });
          result = await res.json();
        } else {
          // Solo cambiar trabajador en el mismo puesto
          const res = await fetch(`${API_BASE}turnos.php`, {
            method: 'POST', headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ action: 'actualizar', id: turnoId, trabajador_id: nuevoId, usuario_id: 1 })
          });
          result = await res.json();
        }

        if (result.success) {
          const accion = esNuevo ? 'Turno asignado' : puestoCambio ? 'Turno movido' : 'Trabajador cambiado';
          mostrarAlerta('✅ ' + accion + ' correctamente', 'success');
          registrarCambio(accion, fecha);
          cerrarModal();
          cargarVistaDiaria();
        } else {
          const errores = result.errores && result.errores.length
            ? '<ul>' + result.errores.map(e => '<li>' + e + '</li>').join('') + '</ul>' : '';
          mostrarAlerta((result.message || 'No se pudo actualizar') + errores, 'danger');
        }
      } catch (err) {
        mostrarAlerta('Error al actualizar asignación', 'danger');
      }
    });

  } catch (err) {
    console.error('Error en editarAsignacion:', err);
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
      cargarEstadisticasDashboard();
    } else {
      mostrarAlerta('Error: ' + result.message, 'danger');
    }
  } catch (error) {
    console.error('Error:', error);
    mostrarAlerta('Error al guardar cambios', 'danger');
  }
}

async function nuevaIncapacidad() {
  const modalOverlay = document.getElementById('modal-overlay');
  const modalTitulo = document.getElementById('modal-titulo');
  const modalBody = document.getElementById('modal-body');

  modalTitulo.textContent = 'Nueva Incapacidad';
  modalBody.innerHTML = '<p>Cargando trabajadores...</p>';
  modalOverlay.classList.add('active');

  try {
    const res = await fetch(API_BASE + 'trabajadores.php');
    const data = await res.json();
    const trabajadores = data.success ? (data.data || []).filter(t => t.activo) : [];

    if (trabajadores.length === 0) {
      modalBody.innerHTML = '<p class="info-box">No hay trabajadores activos para registrar una incapacidad.</p>';
      return;
    }

    const opciones = trabajadores.map(t => `<option value="${t.id}">${t.nombre} — ${t.cedula || 'N/D'}</option>`).join('');
    const hoy = new Date().toISOString().split('T')[0];

    modalBody.innerHTML = `
      <form id="form-nueva-incapacidad">
        <div class="form-group">
          <label for="incapacidad-trabajador-select">Trabajador <span class="required">*</span></label>
          <select id="incapacidad-trabajador-select" required style="width:100%;padding:8px 12px;border:1px solid #dee2e6;border-radius:8px;">
            <option value="">Seleccione...</option>
            ${opciones}
          </select>
        </div>
        <div class="form-group">
          <label for="incapacidad-tipo">Tipo de incapacidad <span class="required">*</span></label>
          <select id="incapacidad-tipo" required style="width:100%;padding:8px 12px;border:1px solid #dee2e6;border-radius:8px;">
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
            <label for="incapacidad-fecha-inicio">Fecha inicio <span class="required">*</span></label>
            <input type="date" id="incapacidad-fecha-inicio" required value="${hoy}" style="width:100%;padding:8px 12px;border:1px solid #dee2e6;border-radius:8px;">
          </div>
          <div class="form-group">
            <label for="incapacidad-fecha-fin">Fecha fin <span class="required">*</span></label>
            <input type="date" id="incapacidad-fecha-fin" required value="${hoy}" style="width:100%;padding:8px 12px;border:1px solid #dee2e6;border-radius:8px;">
          </div>
        </div>
        <div class="form-group">
          <label for="incapacidad-descripcion">Descripción</label>
          <textarea id="incapacidad-descripcion" rows="3" style="width:100%;padding:8px 12px;border:1px solid #dee2e6;border-radius:8px;"></textarea>
        </div>
        <div class="form-group">
          <label for="incapacidad-eps">EPS</label>
          <input type="text" id="incapacidad-eps" style="width:100%;padding:8px 12px;border:1px solid #dee2e6;border-radius:8px;">
        </div>
        <div class="form-group" style="display:flex;align-items:center;gap:10px;">
          <label style="display:flex;align-items:center;gap:8px;font-weight:600;">
            <input type="checkbox" id="genera-restriccion-checkbox"> Generar restricción relacionada
          </label>
        </div>
        <div id="panel-restriccion-generada" style="display:none;border:1px solid #dee2e6;border-radius:10px;padding:12px;background:#f8f9fa;">
          <div class="form-group">
            <label for="tipo-restriccion-generada">Tipo de restricción</label>
            <select id="tipo-restriccion-generada" style="width:100%;padding:8px 12px;border:1px solid #dee2e6;border-radius:8px;">
              <option value="">Seleccione...</option>
              <option value="no_fuerza_fisica">No fuerza física</option>
              <option value="no_turno_noche">No turno noche</option>
              <option value="movilidad_limitada">Movilidad limitada</option>
              <option value="problema_visual">Problema visual</option>
              <option value="puesto_especifico">Puesto específico</option>
            </select>
          </div>
          <div class="form-grid">
            <div class="form-group">
              <label for="restriccion-fecha-fin">Fecha fin restricción</label>
              <input type="date" id="restriccion-fecha-fin" style="width:100%;padding:8px 12px;border:1px solid #dee2e6;border-radius:8px;">
            </div>
            <div class="form-group" style="display:flex;align-items:center;gap:10px;">
              <label style="display:flex;align-items:center;gap:8px;font-weight:600;">
                <input type="checkbox" id="restriccion-permanente"> Permanente
              </label>
            </div>
          </div>
        </div>
        <div class="form-actions" style="margin-top:1rem;">
          <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Guardar</button>
          <button type="button" class="btn btn-outline" onclick="cerrarModal()"><i class="fas fa-times"></i> Cancelar</button>
        </div>
      </form>
    `;

    const checkbox = document.getElementById('genera-restriccion-checkbox');
    const panel = document.getElementById('panel-restriccion-generada');
    const permanente = document.getElementById('restriccion-permanente');
    const restrFin = document.getElementById('restriccion-fecha-fin');

    checkbox.addEventListener('change', function() {
      panel.style.display = this.checked ? '' : 'none';
    });
    permanente.addEventListener('change', function() {
      restrFin.disabled = this.checked;
      if (this.checked) restrFin.value = '';
    });

    document.getElementById('form-nueva-incapacidad').addEventListener('submit', async function(e) {
      e.preventDefault();
      const trabajador_id = document.getElementById('incapacidad-trabajador-select').value;
      const tipo = document.getElementById('incapacidad-tipo').value;
      const fecha_inicio = document.getElementById('incapacidad-fecha-inicio').value;
      const fecha_fin = document.getElementById('incapacidad-fecha-fin').value;
      const datos = {
        trabajador_id,
        tipo,
        fecha_inicio,
        fecha_fin,
        descripcion: document.getElementById('incapacidad-descripcion').value.trim() || null,
        eps: document.getElementById('incapacidad-eps').value.trim() || null,
        genera_restriccion: checkbox.checked,
        tipo_restriccion_generada: checkbox.checked ? document.getElementById('tipo-restriccion-generada').value : null,
        restriccion_permanente: permanente.checked,
        fecha_fin_restriccion: checkbox.checked && !permanente.checked ? document.getElementById('restriccion-fecha-fin').value || null : null
      };

      if (!trabajador_id || !tipo || !fecha_inicio || !fecha_fin) {
        mostrarAlerta('Completa todos los campos requeridos', 'warning');
        return;
      }

      if (checkbox.checked && !datos.tipo_restriccion_generada) {
        mostrarAlerta('Selecciona el tipo de restricción generada', 'warning');
        return;
      }

      try {
        const response = await fetch(API_BASE + 'incapacidades.php', {
          method: 'POST',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify(datos)
        });
        const result = await response.json();
        if (result.success) {
          mostrarAlerta('✅ Incapacidad registrada correctamente', 'success');
          cerrarModal();
          cargarTablaIncapacidades();
          cargarEstadisticasDashboard();
        } else {
          mostrarAlerta('Error: ' + (result.message || 'No se pudo guardar'), 'danger');
        }
      } catch (error) {
        console.error('Error:', error);
        mostrarAlerta('Error al guardar incapacidad', 'danger');
      }
    });
  } catch (error) {
    console.error('Error:', error);
    modalBody.innerHTML = '<p class="alert alert-danger">Error al cargar trabajadores</p>';
  }
}

async function nuevoDiaEspecial() {
  const modalOverlay = document.getElementById('modal-overlay');
  const modalTitulo = document.getElementById('modal-titulo');
  const modalBody = document.getElementById('modal-body');

  modalTitulo.textContent = 'Nuevo Día Especial';
  modalBody.innerHTML = '<p>Cargando trabajadores...</p>';
  modalOverlay.classList.add('active');

  try {
    const res = await fetch(API_BASE + 'trabajadores.php');
    const data = await res.json();
    const trabajadores = data.success ? (data.data || []).filter(t => t.activo) : [];

    if (trabajadores.length === 0) {
      modalBody.innerHTML = '<p class="info-box">No hay trabajadores activos para registrar un día especial.</p>';
      return;
    }

    const opciones = trabajadores.map(t => `<option value="${t.id}">${t.nombre} — ${t.cedula || 'N/D'}</option>`).join('');
    const hoy = new Date().toISOString().split('T')[0];

    modalBody.innerHTML = `
      <form id="form-nuevo-dia-especial">
        <div class="form-group">
          <label for="dia-especial-trabajador">Trabajador <span class="required">*</span></label>
          <select id="dia-especial-trabajador" required style="width:100%;padding:8px 12px;border:1px solid #dee2e6;border-radius:8px;">
            <option value="">Seleccione...</option>
            ${opciones}
          </select>
        </div>
        <div class="form-group">
          <label for="dia-especial-tipo">Tipo de día especial <span class="required">*</span></label>
          <select id="dia-especial-tipo" required style="width:100%;padding:8px 12px;border:1px solid #dee2e6;border-radius:8px;">
            <option value="">Seleccione...</option>
            <option value="LC">LC — Libre cumpleaños</option>
            <option value="VAC">VAC — Vacaciones</option>
            <option value="SUS">SUS — Suspensión</option>
          </select>
        </div>
        <div class="form-grid">
          <div class="form-group">
            <label for="dia-especial-fecha-inicio">Fecha inicio <span class="required">*</span></label>
            <input type="date" id="dia-especial-fecha-inicio" required value="${hoy}" style="width:100%;padding:8px 12px;border:1px solid #dee2e6;border-radius:8px;">
          </div>
          <div class="form-group">
            <label for="dia-especial-fecha-fin">Fecha fin</label>
            <input type="date" id="dia-especial-fecha-fin" value="${hoy}" style="width:100%;padding:8px 12px;border:1px solid #dee2e6;border-radius:8px;">
          </div>
        </div>
        <div id="panel-horas-dia-especial" style="display:none; border:1px solid #dee2e6; border-radius:10px; padding:12px; background:#f8f9fa; margin-bottom:1rem;">
          <div class="form-grid">
            <div class="form-group">
              <label for="dia-especial-horas-inicio">Hora inicio</label>
              <input type="time" id="dia-especial-horas-inicio" style="width:100%;padding:8px 12px;border:1px solid #dee2e6;border-radius:8px;">
            </div>
            <div class="form-group">
              <label for="dia-especial-horas-fin">Hora fin</label>
              <input type="time" id="dia-especial-horas-fin" style="width:100%;padding:8px 12px;border:1px solid #dee2e6;border-radius:8px;">
            </div>
          </div>
          <p style="margin:0;font-size:0.85rem;color:#6c757d;">Complete las horas solo para tipos de jornada parcial.</p>
        </div>
        <div class="form-group">
          <label for="dia-especial-descripcion">Descripción</label>
          <textarea id="dia-especial-descripcion" rows="3" style="width:100%;padding:8px 12px;border:1px solid #dee2e6;border-radius:8px;"></textarea>
        </div>
        <div class="form-actions" style="margin-top:1rem;">
          <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Guardar</button>
          <button type="button" class="btn btn-outline" onclick="cerrarModal()"><i class="fas fa-times"></i> Cancelar</button>
        </div>
      </form>
    `;

    const tipoSelect = document.getElementById('dia-especial-tipo');
    const panelHoras = document.getElementById('panel-horas-dia-especial');
    tipoSelect.addEventListener('change', function() {
      panelHoras.style.display = ['SUP','ADMM','ADMT','ADM'].includes(this.value) ? '' : 'none';
    });

    document.getElementById('form-nuevo-dia-especial').addEventListener('submit', async function(e) {
      e.preventDefault();
      const trabajador_id = document.getElementById('dia-especial-trabajador').value;
      const tipo = document.getElementById('dia-especial-tipo').value;
      const fecha_inicio = document.getElementById('dia-especial-fecha-inicio').value;
      const fecha_fin = document.getElementById('dia-especial-fecha-fin').value || null;
      const horas_inicio = document.getElementById('dia-especial-horas-inicio').value || null;
      const horas_fin = document.getElementById('dia-especial-horas-fin').value || null;
      const descripcion = document.getElementById('dia-especial-descripcion').value.trim() || null;

      if (!trabajador_id || !tipo || !fecha_inicio) {
        mostrarAlerta('Completa todos los campos requeridos', 'warning');
        return;
      }

      try {
        const response = await fetch(API_BASE + 'dias_especiales.php', {
          method: 'POST',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify({ trabajador_id, tipo, fecha_inicio, fecha_fin, horas_inicio, horas_fin, descripcion })
        });
        const result = await response.json();
        if (result.success) {
          mostrarAlerta('✅ Día especial registrado correctamente', 'success');
          cerrarModal();
          cargarTablaDiasEspeciales();
          cargarEstadisticasDashboard();
        } else {
          mostrarAlerta('Error: ' + (result.message || 'No se pudo guardar'), 'danger');
        }
      } catch (error) {
        console.error('Error:', error);
        mostrarAlerta('Error al guardar el día especial', 'danger');
      }
    });
  } catch (error) {
    console.error('Error:', error);
    modalBody.innerHTML = '<p class="alert alert-danger">Error al cargar trabajadores</p>';
  }
}

async function eliminarIncapacidad(id) {
  const okIncap = await confirmarAccion({ titulo: 'Eliminar incapacidad', mensaje: '¿Eliminar este registro de incapacidad? Esta acción no se puede deshacer.', textoBtn: 'Eliminar', tipoBtn: 'danger', icono: 'fa-file-medical' });
    if (!okIncap) {
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
      cargarEstadisticasDashboard();
    } else {
      mostrarAlerta('Error: ' + result.message, 'danger');
    }
  } catch (error) {
    console.error('Error:', error);
    mostrarAlerta('Error al eliminar incapacidad', 'danger');
  }
}
// ══════════════════════════════════════════════════════════════
// NOVEDADES DEL DÍA
// ══════════════════════════════════════════════════════════════

let _novedadTextoOriginal = '';

async function cargarNovedad(fecha) {
    const labelFecha  = document.getElementById('novedades-fecha-label');
    const display     = document.getElementById('novedades-texto-display');
    const vacio       = document.getElementById('novedades-vacio');
    const estado      = document.getElementById('novedades-estado');
    const panel       = document.getElementById('panel-novedades');

    if (!panel) return;

    // Solo mostrar panel en vista diaria
    panel.style.display = document.getElementById('calendario-vista-diaria')?.style.display === 'none' ? 'none' : '';

    const fechaObj = new Date(fecha + 'T00:00:00');
    const fechaFmt = fechaObj.toLocaleDateString('es-CO', { weekday:'long', day:'numeric', month:'long' });
    if (labelFecha) labelFecha.textContent = '— ' + fechaFmt.charAt(0).toUpperCase() + fechaFmt.slice(1);

    try {
        const res  = await fetch(API_BASE + 'novedades.php?fecha=' + fecha);
        const data = await res.json();
        const texto = data.success && data.data ? data.data.contenido : '';
        _novedadTextoOriginal = texto;

        if (display) display.textContent = texto;
        if (vacio)   vacio.style.display  = texto ? 'none' : '';
        if (display) display.style.display = texto ? '' : 'none';

        if (estado && data.success && data.data) {
            const upd = new Date(data.data.updated_at);
            estado.textContent = 'Última edición: ' + upd.toLocaleTimeString('es-CO', { hour:'2-digit', minute:'2-digit' });
        } else if (estado) {
            estado.textContent = '';
        }

        // Si estaba en modo edición, actualizar el textarea también
        const ta = document.getElementById('novedades-textarea');
        if (ta) ta.value = texto;

    } catch(e) {
        console.error('Error cargando novedad:', e);
    }
}

function toggleEditarNovedad() {
    const lectura = document.getElementById('novedades-lectura');
    const edicion = document.getElementById('novedades-edicion');
    const ta      = document.getElementById('novedades-textarea');

    lectura.style.display = 'none';
    edicion.style.display = '';
    if (ta) {
        ta.value = _novedadTextoOriginal;
        ta.focus();
        // Cursor al final
        ta.setSelectionRange(ta.value.length, ta.value.length);
    }
}

function cancelarEditarNovedad() {
    document.getElementById('novedades-lectura').style.display = '';
    document.getElementById('novedades-edicion').style.display = 'none';
}

function novedadCambiada() {
    const btn = document.getElementById('btn-guardar-novedad');
    const ta  = document.getElementById('novedades-textarea');
    if (!btn || !ta) return;
    const cambio = ta.value.trim() !== _novedadTextoOriginal.trim();
    btn.style.opacity = cambio ? '1' : '0.6';
}

async function guardarNovedad() {
    const ta    = document.getElementById('novedades-textarea');
    const estado = document.getElementById('novedades-estado');
    const fecha = fechaCalendarioActual.toISOString().split('T')[0];
    if (!ta) return;

    const btn = document.getElementById('btn-guardar-novedad');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Guardando...'; }

    try {
        const res  = await fetch(API_BASE + 'novedades.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ fecha, contenido: ta.value, usuario_id: 1 })
        });
        const data = await res.json();

        if (data.success) {
            _novedadTextoOriginal = ta.value.trim();
            cancelarEditarNovedad();
            await cargarNovedad(fecha);
            mostrarAlerta('✅ Novedad guardada', 'success');
            registrarCambio('Novedad del día guardada', fecha);
        } else {
            mostrarAlerta('❌ Error al guardar novedad', 'danger');
        }
    } catch(e) {
        mostrarAlerta('Error de conexión', 'danger');
    } finally {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Guardar'; }
    }
}


// ── Supervisor: resumen de horas en tiempo real ──────────────
function actualizarResumenHorasSup() {
    const hi  = document.getElementById('sup-hora-inicio')?.value;
    const hf  = document.getElementById('sup-hora-fin')?.value;
    const res = document.getElementById('sup-horas-resumen');
    if (!res) return;
    if (!hi || !hf) { res.style.display = 'none'; return; }

    const [hh, mm] = hi.split(':').map(Number);
    const [hh2, mm2] = hf.split(':').map(Number);
    let minutos = (hh2 * 60 + mm2) - (hh * 60 + mm);
    if (minutos < 0) minutos += 24 * 60; // cruza medianoche
    const hrs  = Math.floor(minutos / 60);
    const mins = minutos % 60;
    res.innerHTML = '<i class="fas fa-clock" style="margin-right:5px;"></i>'
        + 'Horario: <strong>' + hi + ' → ' + hf + '</strong>'
        + ' &nbsp;·&nbsp; Duración: <strong>' + hrs + 'h ' + (mins > 0 ? mins + 'min' : '') + '</strong>';
    res.style.display = '';
}

document.addEventListener('DOMContentLoaded', () => {
    ['sup-hora-inicio','sup-hora-fin'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('change', actualizarResumenHorasSup);
    });
});

// ── Asignar supervisor desde vista diaria ───────────────────
// Cargar supervisores disponibles en el select de la vista diaria
async function cargarSupervisoresDisponiblesDiaria(fecha) {
    const select = document.getElementById('sel-supervisor-diaria');
    if (!select) return;
    
    try {
        const res = await fetch(API_BASE + 'trabajadores.php');
        const data = await res.json();
        
        if (!data.success || !data.data) return;
        
        // Filtrar solo supervisores activos
        const supervisores = data.data
            .filter(t => t.activo && String(t.cargo || '').toLowerCase() === 'supervisor')
            .sort((a, b) => a.nombre.localeCompare(b.nombre, 'es', { sensitivity: 'base' }));
        
        select.innerHTML = '<option value="">Seleccionar...</option>';
        supervisores.forEach(s => {
            const opt = document.createElement('option');
            opt.value = s.id;
            opt.textContent = s.nombre + ' (' + (s.cedula || 'sin cédula') + ')';
            select.appendChild(opt);
        });
    } catch(e) {
        console.error('Error cargando supervisores:', e);
    }
}

// Agregar supervisor desde el formulario de la vista diaria
async function agregarSupervisorDiaria(fecha) {
    const select = document.getElementById('sel-supervisor-diaria');
    const hi = document.getElementById('sup-hora-inicio-diaria');
    const hf = document.getElementById('sup-hora-fin-diaria');
    
    if (!select.value || !hi.value || !hf.value) {
        mostrarAlerta('⚠️ Completa todos los campos: supervisor, hora entrada y salida', 'warning');
        return;
    }
    
    mostrarSpinner('Guardando supervisor...');
    
    try {
        const res = await fetch(API_BASE + 'supervisores_turno.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                action: 'crear',
                trabajador_id: select.value,
                fecha: fecha,
                hora_inicio: hi.value + ':00',
                hora_fin: hf.value + ':00'
            })
        });
        
        const r = await res.json();
        ocultarSpinner();
        
        if (r.success) {
            mostrarAlerta('✅ Supervisor asignado correctamente', 'success');
            // Limpiar formulario
            select.value = '';
            hi.value = '';
            hf.value = '';
            // Recargar vista
            cargarVistaDiaria();
        } else {
            mostrarAlerta('❌ Error: ' + (r.message || 'No se pudo guardar'), 'danger');
        }
    } catch(e) {
        ocultarSpinner();
        mostrarAlerta('Error de conexión', 'danger');
        console.error(e);
    }
}


// ══════════════════════════════════════════════════════════════
// SUPERVISORES — editar y eliminar desde vista diaria
// ══════════════════════════════════════════════════════════════

async function editarSupervisorTurno(id) {
    const modalOverlay = document.getElementById('modal-overlay');
    const modalTitulo  = document.getElementById('modal-titulo');
    const modalBody    = document.getElementById('modal-body');

    try {
        const res  = await fetch(API_BASE + 'supervisores_turno.php?id=' + id);
        const data = await res.json();
        if (!data.success || !data.data) { mostrarAlerta('No se pudo cargar', 'danger'); return; }
        const s = data.data;

        modalTitulo.textContent = 'Editar turno supervisor';
        modalBody.innerHTML = `
            <div class="form-grid">
                <div class="form-group">
                    <label>Supervisor</label>
                    <input type="text" disabled value="${s.trabajador||s.nombre||''}" style="background:#f8f9fa;">
                </div>
                <div class="form-group">
                    <label>Fecha</label>
                    <input type="text" disabled value="${s.fecha}" style="background:#f8f9fa;">
                </div>
            </div>
            <div class="form-grid">
                <div class="form-group">
                    <label>Hora entrada</label>
                    <input type="time" id="edit-sup-hi" value="${s.hora_inicio.substring(0,5)}" step="60" style="font-size:1rem;padding:8px 12px;">
                </div>
                <div class="form-group">
                    <label>Hora salida</label>
                    <input type="time" id="edit-sup-hf" value="${s.hora_fin.substring(0,5)}" step="60" style="font-size:1rem;padding:8px 12px;">
                </div>
            </div>
            <div id="edit-sup-resumen" style="padding:8px 12px;background:#f3e8ff;border-radius:8px;font-size:0.85rem;color:#6a1b9a;margin:8px 0;"></div>
            <div class="form-actions">
                <button class="btn btn-primary" onclick="guardarEdicionSupervisor(${id})"><i class="fas fa-save"></i> Guardar</button>
                <button class="btn btn-outline" onclick="cerrarModal()"><i class="fas fa-times"></i> Cancelar</button>
            </div>`;

        ['edit-sup-hi','edit-sup-hf'].forEach(eid => {
            document.getElementById(eid)?.addEventListener('change', () => {
                const hi = document.getElementById('edit-sup-hi').value;
                const hf = document.getElementById('edit-sup-hf').value;
                if (!hi || !hf) return;
                const [h1,m1] = hi.split(':').map(Number);
                const [h2,m2] = hf.split(':').map(Number);
                let m = (h2*60+m2)-(h1*60+m1); if (m<0) m+=1440;
                document.getElementById('edit-sup-resumen').innerHTML =
                    '<i class="fas fa-clock" style="margin-right:5px;"></i>' + hi + ' → ' + hf +
                    ' &nbsp;·&nbsp; <strong>' + Math.floor(m/60) + 'h' + (m%60?' '+m%60+'min':'') + '</strong>';
            });
        });
        // Trigger inicial
        document.getElementById('edit-sup-hi')?.dispatchEvent(new Event('change'));

        modalOverlay.style.display = 'flex';
    } catch(e) {
        mostrarAlerta('Error al cargar', 'danger');
    }
}

async function guardarEdicionSupervisor(id) {
    const hi = document.getElementById('edit-sup-hi')?.value;
    const hf = document.getElementById('edit-sup-hf')?.value;
    if (!hi || !hf) { mostrarAlerta('Ingresa ambas horas', 'warning'); return; }
    mostrarSpinner('Guardando...');
    try {
        const res  = await fetch(API_BASE + 'supervisores_turno.php', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ action:'actualizar', id, hora_inicio: hi, hora_fin: hf })
        });
        const r = await res.json();
        ocultarSpinner();
        if (r.success) {
            mostrarAlerta('✅ Turno supervisor actualizado', 'success');
            cerrarModal();
            cargarVistaDiaria();
        } else {
            mostrarAlerta('❌ ' + (r.message||'Error'), 'danger');
        }
    } catch(e) { ocultarSpinner(); mostrarAlerta('Error de conexión', 'danger'); }
}

async function eliminarSupervisorTurno(id, nombre, fecha) {
    const ok = await confirmarAccion({
        titulo: 'Eliminar turno supervisor',
        mensaje: '¿Eliminar el turno de <strong>' + nombre + '</strong> del ' + fecha + '?',
        textoBtn: 'Eliminar', tipoBtn: 'danger', icono: 'fa-trash'
    });
    if (!ok) return;
    mostrarSpinner('Eliminando...');
    try {
        const res = await fetch(API_BASE + 'supervisores_turno.php', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ action:'eliminar', id })
        });
        const r = await res.json();
        ocultarSpinner();
        if (r.success) {
            mostrarAlerta('✅ Turno eliminado', 'success');
            cargarVistaDiaria();
        } else {
            mostrarAlerta('❌ ' + (r.message||'Error'), 'danger');
        }
    } catch(e) { ocultarSpinner(); mostrarAlerta('Error de conexión', 'danger'); }
}


// ══════════════════════════════════════════════════════════════
// GESTIÓN DE TRABAJADORES
// ══════════════════════════════════════════════════════════════

async function cargarTablaTrabajadores() {
    const tabla = document.getElementById('tabla-trabajadores');
    if (!tabla) return;

    const parent = tabla.parentElement || document.body;
    if (!document.getElementById('trabajadores-filter-container')) {
        const div = document.createElement('div');
        div.id = 'trabajadores-filter-container';
        div.style.cssText = 'margin-bottom:12px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;';
        div.innerHTML = `
            <div style="position:relative;flex:1;min-width:200px;">
                <i class="fas fa-search" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#adb5bd;font-size:0.85rem;"></i>
                <input type="text" id="buscar-trabajador" placeholder="Buscar por nombre o cédula..."
                    style="width:100%;padding:8px 8px 8px 32px;border:1px solid #ced4da;border-radius:6px;font-size:0.9rem;box-sizing:border-box;">
            </div>
            <select id="filter-trabajadores" class="filter-select" style="padding:8px;border:1px solid #ced4da;border-radius:6px;">
                <option value="all">Todos</option>
                <option value="activos" selected>Activos</option>
                <option value="inactivos">Inactivos</option>
            </select>
            <span id="trabajadores-count" style="font-size:0.85rem;color:#6c757d;white-space:nowrap;"></span>`;
        parent.insertBefore(div, tabla);
        document.getElementById('buscar-trabajador').addEventListener('input', filtrarTablaTrabajadores);
        document.getElementById('filter-trabajadores').addEventListener('change', filtrarTablaTrabajadores);
    }

    try {
        const res  = await fetch(API_BASE + 'trabajadores.php?incluir_inactivos=1');
        const data = await res.json();
        if (data.success && Array.isArray(data.data)) {
            tabla.dataset.trabajadores = JSON.stringify(data.data);
            filtrarTablaTrabajadores();
        } else {
            tabla.innerHTML = '<p class="info-box">No hay trabajadores registrados.</p>';
        }
    } catch(e) {
        tabla.innerHTML = '<p class="alert alert-danger">Error al cargar trabajadores</p>';
    }
}

function filtrarTablaTrabajadores() {
    const tabla = document.getElementById('tabla-trabajadores');
    if (!tabla || !tabla.dataset.trabajadores) return;

    const todos    = JSON.parse(tabla.dataset.trabajadores);
    const filtro   = document.getElementById('filter-trabajadores')?.value || 'all';
    const busqueda = (document.getElementById('buscar-trabajador')?.value || '').toLowerCase().trim();

    let lista = todos.slice();
    if (filtro === 'activos')   lista = lista.filter(t => t.activo == 1 || t.activo === true);
    if (filtro === 'inactivos') lista = lista.filter(t => t.activo == 0 || t.activo === false);
    if (busqueda) lista = lista.filter(t =>
        (t.nombre||'').toLowerCase().includes(busqueda) || (t.cedula||'').includes(busqueda)
    );

    const counter = document.getElementById('trabajadores-count');
    if (counter) counter.textContent = (busqueda || filtro !== 'all')
        ? lista.length + ' de ' + todos.length + ' trabajadores'
        : lista.length + ' trabajadores';

    if (lista.length === 0) {
        tabla.innerHTML = busqueda
            ? '<p class="info-box">No se encontraron resultados para "<strong>' + busqueda + '</strong>".</p>'
            : '<p class="info-box">No hay trabajadores registrados.</p>';
        return;
    }

    const resalt = (txt, q) => {
        if (!q || !txt) return txt || '';
        return txt.replace(new RegExp('(' + q.replace(/[.*+?^${}()|[\]\\]/g,'\\$&') + ')','gi'),
            '<mark style="background:#fff3cd;padding:0 2px;border-radius:2px;">$1</mark>');
    };

    let html = '<table><thead><tr style="background:linear-gradient(135deg,var(--terminal) 0%,#027433 100%);color:white;">';
    html += '<th>Nombre</th><th>Cédula</th><th>Cargo</th><th>Teléfono</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>';
    lista.forEach(t => {
        const activo = t.activo == 1 || t.activo === true;
        const cargo  = t.cargo === 'Supervisor'
            ? '<span style="background:#f3e8ff;color:#6b21a8;padding:2px 8px;border-radius:4px;font-size:0.78rem;font-weight:700;">Supervisor</span>'
            : '<span style="background:#e8f5e9;color:#1b5e20;padding:2px 8px;border-radius:4px;font-size:0.78rem;">Aux. Operativo</span>';
        const nom = t.nombre.replace(/'/g,"\\'");
        html += '<tr>';
        html += '<td>' + resalt(t.nombre, busqueda) + '</td>';
        html += '<td>' + resalt(String(t.cedula), busqueda) + '</td>';
        html += '<td>' + (t.cargo ? cargo : '-') + '</td>';
        html += '<td>' + (t.telefono || '-') + '</td>';
        html += '<td><span class="puesto-status ' + (activo?'status-ok':'status-empty') + '">' + (activo?'Activo':'Inactivo') + '</span></td>';
        html += '<td>';
        html += '<button class="btn btn-sm btn-secondary" onclick="editarTrabajador(' + t.id + ')" title="Editar"><i class="fas fa-edit"></i></button> ';
        if (activo) {
            html += '<button class="btn btn-sm btn-outline" onclick="desactivarTrabajador(' + t.id + ',\'' + nom + '\')" title="Desactivar"><i class="fas fa-ban"></i></button> ';
        } else {
            html += '<button class="btn btn-sm btn-primary" onclick="activarTrabajador(' + t.id + ')" title="Activar"><i class="fas fa-check"></i></button> ';
        }
        html += '<button class="btn btn-sm btn-danger" onclick="eliminarTrabajador(' + t.id + ',\'' + nom + '\')" title="Eliminar"><i class="fas fa-trash"></i></button>';
        html += '</td></tr>';
    });
    html += '</tbody></table>';
    tabla.innerHTML = html;
}

function nuevoTrabajador() {
    const modalOverlay = document.getElementById('modal-overlay');
    const modalTitulo  = document.getElementById('modal-titulo');
    const modalBody    = document.getElementById('modal-body');
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
                <label for="cargo-trabajador">Cargo <span class="required">*</span></label>
                <select id="cargo-trabajador" required style="width:100%;padding:8px 12px;border:1px solid #dee2e6;border-radius:8px;font-size:0.9rem;">
                    <option value="Auxiliar Operativo" selected>Auxiliar Operativo</option>
                    <option value="Supervisor">Supervisor</option>
                </select>
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
            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Guardar</button>
                <button type="button" class="btn btn-outline" onclick="cerrarModal()"><i class="fas fa-times"></i> Cancelar</button>
            </div>
        </form>`;
    modalOverlay.style.display = 'flex';
    document.getElementById('form-nuevo-trabajador').addEventListener('submit', async function(e) {
        e.preventDefault();
        const nombre = document.getElementById('nombre-trabajador').value.trim();
        const cedula = document.getElementById('cedula-trabajador').value.trim();
        if (!/^[0-9]+$/.test(cedula)) { mostrarAlerta('La cédula solo debe contener números','danger'); return; }
        const datos = {
            nombre, cedula,
            cargo:         document.getElementById('cargo-trabajador').value,
            telefono:      document.getElementById('telefono-trabajador').value.trim() || null,
            email:         document.getElementById('email-trabajador').value.trim() || null,
            fecha_ingreso: document.getElementById('fecha-ingreso-trabajador').value
        };
        try {
            const res  = await fetch(API_BASE + 'trabajadores.php', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(datos) });
            const data = await res.json();
            if (data.success) {
                mostrarAlerta('✅ Trabajador creado correctamente','success');
                cerrarModal();
                cargarTablaTrabajadores();
            } else {
                mostrarAlerta('Error: ' + (data.message||'No se pudo guardar'),'danger');
            }
        } catch(err) { mostrarAlerta('Error de conexión','danger'); }
    });
}

async function editarTrabajador(id) {
    const modalOverlay = document.getElementById('modal-overlay');
    const modalTitulo  = document.getElementById('modal-titulo');
    const modalBody    = document.getElementById('modal-body');
    try {
        const res  = await fetch(API_BASE + 'trabajadores.php?id=' + id);
        const data = await res.json();
        if (!data.success || !data.data) { mostrarAlerta('No se pudo cargar el trabajador','danger'); return; }
        const t = data.data;
        modalTitulo.textContent = 'Editar Trabajador';
        modalBody.innerHTML = `
            <form id="form-editar-trabajador">
                <input type="hidden" id="edit-trab-id" value="${t.id}">
                <div class="form-group">
                    <label>Nombre Completo <span class="required">*</span></label>
                    <input type="text" id="edit-nombre-trabajador" required value="${t.nombre}">
                </div>
                <div class="form-group">
                    <label>Cédula <span class="required">*</span></label>
                    <input type="text" id="edit-cedula-trabajador" required value="${t.cedula}">
                </div>
                <div class="form-group">
                    <label>Cargo</label>
                    <select id="edit-cargo-trabajador" style="width:100%;padding:8px 12px;border:1px solid #dee2e6;border-radius:8px;font-size:0.9rem;">
                        <option value="Auxiliar Operativo" ${(!t.cargo || t.cargo==='Auxiliar Operativo')?'selected':''}>Auxiliar Operativo</option>
                        <option value="Supervisor" ${t.cargo==='Supervisor'?'selected':''}>Supervisor</option>
                    </select>
                </div>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Teléfono</label>
                        <input type="tel" id="edit-telefono-trabajador" value="${t.telefono||''}">
                    </div>
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" id="edit-email-trabajador" value="${t.email||''}">
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Guardar Cambios</button>
                    <button type="button" class="btn btn-outline" onclick="cerrarModal()"><i class="fas fa-times"></i> Cancelar</button>
                </div>
            </form>`;
        modalOverlay.style.display = 'flex';
        document.getElementById('form-editar-trabajador').addEventListener('submit', async function(e) {
            e.preventDefault();
            const datos = {
                nombre:   document.getElementById('edit-nombre-trabajador').value,
                cedula:   document.getElementById('edit-cedula-trabajador').value,
                cargo:    document.getElementById('edit-cargo-trabajador').value,
                telefono: document.getElementById('edit-telefono-trabajador').value || null,
                email:    document.getElementById('edit-email-trabajador').value || null
            };
            try {
                const res  = await fetch(API_BASE + 'trabajadores.php?id=' + id, { method:'PUT', headers:{'Content-Type':'application/json'}, body:JSON.stringify(datos) });
                const data = await res.json();
                if (data.success) { mostrarAlerta('✅ Trabajador actualizado','success'); cerrarModal(); cargarTablaTrabajadores(); }
                else mostrarAlerta('Error: ' + (data.message||'No se pudo actualizar'),'danger');
            } catch(err) { mostrarAlerta('Error de conexión','danger'); }
        });
    } catch(e) { mostrarAlerta('Error al cargar trabajador','danger'); }
}

async function eliminarTrabajador(id, nombre) {
    const ok = await confirmarAccion({ titulo:'Eliminar trabajador', mensaje:'¿Eliminar a <strong>'+nombre+'</strong>? Esta acción no se puede deshacer.', textoBtn:'Eliminar', tipoBtn:'danger', icono:'fa-user-times' });
    if (!ok) return;
    try {
        const res  = await fetch(API_BASE + 'trabajadores.php?id=' + id, { method:'DELETE' });
        const data = await res.json();
        if (data.success) { mostrarAlerta('✅ Trabajador eliminado','success'); cargarTablaTrabajadores(); }
        else mostrarAlerta('Error: ' + (data.message||'No se pudo eliminar'),'danger');
    } catch(e) { mostrarAlerta('Error de conexión','danger'); }
}

async function desactivarTrabajador(id, nombre) {
    const ok = await confirmarAccion({ titulo:'Desactivar trabajador', mensaje:'¿Desactivar a <strong>'+nombre+'</strong>? No aparecerá en asignaciones futuras.', textoBtn:'Desactivar', tipoBtn:'warning', icono:'fa-ban' });
    if (!ok) return;
    try {
        const res  = await fetch(API_BASE + 'trabajadores.php?id=' + id, { method:'PUT', headers:{'Content-Type':'application/json'}, body:JSON.stringify({activo:false}) });
        const data = await res.json();
        if (data.success) { mostrarAlerta('✅ Trabajador desactivado','success'); cargarTablaTrabajadores(); }
        else mostrarAlerta('Error: ' + (data.message||'No se pudo desactivar'),'danger');
    } catch(e) { mostrarAlerta('Error de conexión','danger'); }
}

async function activarTrabajador(id) {
    try {
        const res  = await fetch(API_BASE + 'trabajadores.php?id=' + id, { method:'PUT', headers:{'Content-Type':'application/json'}, body:JSON.stringify({activo:true}) });
        const data = await res.json();
        if (data.success) { mostrarAlerta('✅ Trabajador activado','success'); cargarTablaTrabajadores(); }
        else mostrarAlerta('Error: ' + (data.message||'No se pudo activar'),'danger');
    } catch(e) { mostrarAlerta('Error de conexión','danger'); }
}

// ══════════════════════════════════════════════════════════════
// GESTIÓN DE RESTRICCIONES
// ══════════════════════════════════════════════════════════════

async function cargarTablaRestricciones() {
    const tabla = document.getElementById('tabla-restricciones');
    if (!tabla) return;
    try {
        const [resTrab, resRestr] = await Promise.all([
            fetch(API_BASE + 'trabajadores.php').then(r => r.json()),
            fetch(API_BASE + 'trabajadores.php?action=restricciones').then(r => r.json())
        ]);
        const trabajadores = resTrab.success ? resTrab.data.filter(t => t.activo) : [];
        const restricciones = resRestr.success ? (resRestr.data || []) : [];

        if (restricciones.length === 0) {
            tabla.innerHTML = '<p class="info-box">No hay restricciones registradas.</p>';
            return;
        }
        let html = '<table><thead><tr style="background:linear-gradient(135deg,var(--terminal) 0%,#027433 100%);color:white;">';
        html += '<th>Trabajador</th><th>Tipo</th><th>Descripción</th><th>Vigencia</th><th>Acciones</th></tr></thead><tbody>';
        restricciones.forEach(r => {
            html += '<tr>';
            html += '<td>' + (r.trabajador_nombre || r.nombre || '-') + '</td>';
            html += '<td><span style="background:#f8d7da;color:#721c24;padding:2px 8px;border-radius:4px;font-size:0.82rem;font-weight:600;">' + (r.tipo_restriccion || r.tipo || '-') + '</span></td>';
            html += '<td>' + (r.descripcion || '-') + '</td>';
            html += '<td>' + (r.fecha_inicio || '-') + (r.fecha_fin ? ' al ' + r.fecha_fin : ' (indefinida)') + '</td>';
            html += '<td><button class="btn btn-sm btn-danger" onclick="eliminarRestriccion(' + r.id + ')"><i class="fas fa-trash"></i></button></td>';
            html += '</tr>';
        });
        html += '</tbody></table>';
        tabla.innerHTML = html;
    } catch(e) {
        tabla.innerHTML = '<p class="alert alert-danger">Error al cargar restricciones</p>';
    }
}

function cambiarTabRestricciones(tab) {
    const btnLista = document.getElementById('tab-restr-lista');
    const btnMatriz = document.getElementById('tab-restr-matriz');
    const panelLista = document.getElementById('panel-restr-lista');
    const panelMatriz = document.getElementById('panel-restr-matriz');
    if (!btnLista || !btnMatriz || !panelLista || !panelMatriz) return;

    const esLista = tab === 'lista';
    panelLista.style.display = esLista ? '' : 'none';
    panelMatriz.style.display = esLista ? 'none' : '';

    btnLista.style.borderBottomColor = esLista ? 'var(--terminal)' : 'transparent';
    btnLista.style.color = esLista ? 'var(--terminal)' : '#6c757d';
    btnLista.style.fontWeight = esLista ? '700' : '600';

    btnMatriz.style.borderBottomColor = esLista ? 'transparent' : 'var(--terminal)';
    btnMatriz.style.color = esLista ? '#6c757d' : 'var(--terminal)';
    btnMatriz.style.fontWeight = esLista ? '600' : '700';

    if (esLista) {
        cargarTablaRestricciones();
    } else {
        cargarMatrizPuestos();
    }
}

async function cargarMatrizPuestos() {
    const cont = document.getElementById('matriz-puestos-container');
    if (!cont) return;
    cont.innerHTML = '<div class="dash-loading"><i class="fas fa-spinner fa-spin"></i> Cargando matriz...</div>';

    try {
        const [resTrab, resRestr] = await Promise.all([
            fetch(API_BASE + 'trabajadores.php').then(r => r.json()),
            fetch(API_BASE + 'trabajadores.php?action=restricciones').then(r => r.json())
        ]);
        const trabajadores = resTrab.success ? (resTrab.data || []).filter(t => t.activo) : [];
        const restricciones = resRestr.success ? (resRestr.data || []) : [];

        if (trabajadores.length === 0) {
            cont.innerHTML = '<p class="info-box">No hay trabajadores activos para mostrar la matriz.</p>';
            return;
        }

        const tipos = ['no_fuerza_fisica', 'no_turno_noche', 'movilidad_limitada', 'problema_visual', 'puesto_especifico'];
        const etiquetas = {
            no_fuerza_fisica: 'No fuerza física',
            no_turno_noche: 'No turno noche',
            movilidad_limitada: 'Movilidad limitada',
            problema_visual: 'Problema visual',
            puesto_especifico: 'Puesto específico'
        };

        const restrPorTrab = {};
        restricciones.forEach(r => {
            if (!restrPorTrab[r.trabajador_id]) restrPorTrab[r.trabajador_id] = [];
            restrPorTrab[r.trabajador_id].push(r);
        });

        let html = '<div style="overflow:auto;">';
        html += '<table><thead><tr style="background:linear-gradient(135deg,var(--terminal) 0%,#027433 100%);color:white;">';
        html += '<th>Trabajador</th>';
        tipos.forEach(tipo => html += `<th>${etiquetas[tipo]}</th>`);
        html += '<th>Acciones</th></tr></thead><tbody>';

        trabajadores.forEach(t => {
            const restrs = restrPorTrab[t.id] || [];
            html += '<tr>';
            html += '<td>' + t.nombre + ' <span style="color:#6c757d;font-size:0.85rem;">(' + (t.cedula||'-') + ')</span></td>';
            tipos.forEach(tipo => {
                const restr = restrs.find(r => (r.tipo_restriccion || r.tipo) === tipo);
                if (restr) {
                    html += '<td style="text-align:center;"><span style="display:inline-flex;align-items:center;gap:4px;padding:4px 8px;border-radius:999px;background:#f8d7da;color:#721c24;font-size:0.82rem;">Bloqueado</span></td>';
                } else {
                    html += '<td style="text-align:center;"><span style="display:inline-flex;align-items:center;gap:4px;padding:4px 8px;border-radius:999px;background:#d4edda;color:#155724;font-size:0.82rem;">Permitido</span></td>';
                }
            });
            html += `<td style="text-align:center;"><button class="btn btn-sm btn-secondary" onclick="verRestriccionesTrabajador(${t.id})"><i class="fas fa-eye"></i></button></td>`;
            html += '</tr>';
        });

        html += '</tbody></table></div>';
        cont.innerHTML = html;
    } catch (error) {
        cont.innerHTML = '<p class="alert alert-danger">Error al cargar matriz de restricciones</p>';
    }
}

async function verRestriccionesTrabajador(trabajadorId) {
    const modalOverlay = document.getElementById('modal-overlay');
    const modalTitulo = document.getElementById('modal-titulo');
    const modalBody = document.getElementById('modal-body');

    modalTitulo.textContent = 'Restricciones del trabajador';
    modalBody.innerHTML = '<p>Cargando datos...</p>';
    modalOverlay.classList.add('active');

    try {
        const res = await fetch(API_BASE + 'trabajadores.php?id=' + trabajadorId);
        const data = await res.json();
        if (!data.success || !data.data) {
            modalBody.innerHTML = '<p class="alert alert-danger">No se pudo cargar la información del trabajador.</p>';
            return;
        }

        const t = data.data;
        const restrs = Array.isArray(t.restricciones) ? t.restricciones : [];
        const rows = restrs.length > 0
            ? restrs.map(r => `<div class="restriccion-item"><strong>${r.tipo_restriccion || r.tipo}</strong><span>${r.descripcion || '-'}</span><small>${r.fecha_inicio || '-'}${r.fecha_fin ? ' al ' + r.fecha_fin : ''}</small></div>`).join('')
            : '<p class="info-box">No tiene restricciones activas.</p>';

        modalBody.innerHTML = `
            <div style="margin-bottom:1rem;">
                <p style="margin:0 0 8px;font-weight:700;">${t.nombre} — ${t.cedula || 'sin cédula'}</p>
                <p style="margin:0;color:#6c757d;">Área: ${t.area || 'N/D'} · Cargo: ${t.cargo || 'N/D'}</p>
            </div>
            <div style="margin-bottom:1rem;">${rows}</div>
            <div style="display:flex;justify-content:flex-end;gap:10px;flex-wrap:wrap;">
                <button class="btn btn-primary" onclick="nuevaRestriccionModal(${t.id})"><i class="fas fa-plus"></i> Agregar restricción</button>
                <button class="btn btn-outline" onclick="cerrarModal()"><i class="fas fa-times"></i> Cerrar</button>
            </div>
        `;
    } catch (error) {
        modalBody.innerHTML = '<p class="alert alert-danger">Error al cargar restricciones del trabajador.</p>';
    }
}

function nuevaRestriccionModal(trabajadorId) {
    const modalOverlay = document.getElementById('modal-overlay');
    const modalTitulo = document.getElementById('modal-titulo');
    const modalBody = document.getElementById('modal-body');
    modalTitulo.textContent = 'Agregar restricción';

    modalBody.innerHTML = `
        <form id="form-nueva-restriccion">
            <input type="hidden" id="restriccion-trabajador-id" value="${trabajadorId}">
            <div class="form-group">
                <label for="restriccion-tipo-select">Tipo de restricción <span class="required">*</span></label>
                <select id="restriccion-tipo-select" required style="width:100%;padding:8px 12px;border:1px solid #dee2e6;border-radius:8px;">
                    <option value="">Seleccione...</option>
                    <option value="no_fuerza_fisica">No fuerza física</option>
                    <option value="no_turno_noche">No turno noche</option>
                    <option value="movilidad_limitada">Movilidad limitada</option>
                    <option value="problema_visual">Problema visual</option>
                    <option value="puesto_especifico">Puesto específico</option>
                </select>
            </div>
            <div class="form-group">
                <label for="restriccion-descripcion">Descripción</label>
                <textarea id="restriccion-descripcion" rows="3" style="width:100%;padding:8px 12px;border:1px solid #dee2e6;border-radius:8px;"></textarea>
            </div>
            <div class="form-grid">
                <div class="form-group">
                    <label for="restriccion-fecha-inicio">Fecha inicio <span class="required">*</span></label>
                    <input type="date" id="restriccion-fecha-inicio" required style="width:100%;padding:8px 12px;border:1px solid #dee2e6;border-radius:8px;">
                </div>
                <div class="form-group">
                    <label for="restriccion-fecha-fin">Fecha fin</label>
                    <input type="date" id="restriccion-fecha-fin" style="width:100%;padding:8px 12px;border:1px solid #dee2e6;border-radius:8px;">
                </div>
            </div>
            <div class="form-actions" style="margin-top:1rem;">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Guardar</button>
                <button type="button" class="btn btn-outline" onclick="cerrarModal()"><i class="fas fa-times"></i> Cancelar</button>
            </div>
        </form>
    `;
    modalOverlay.style.display = 'flex';
    document.getElementById('form-nueva-restriccion').addEventListener('submit', async function(e) {
        e.preventDefault();
        const trabajador_id = document.getElementById('restriccion-trabajador-id').value;
        const tipo = document.getElementById('restriccion-tipo-select').value;
        const descripcion = document.getElementById('restriccion-descripcion').value.trim();
        const fecha_inicio = document.getElementById('restriccion-fecha-inicio').value;
        const fecha_fin = document.getElementById('restriccion-fecha-fin').value || null;
        if (!trabajador_id || !tipo || !fecha_inicio) {
            mostrarAlerta('Completa los campos obligatorios','warning');
            return;
        }
        await guardarNuevaRestriccion({ trabajador_id, tipo_restriccion: tipo, descripcion, fecha_inicio, fecha_fin });
        cerrarModal();
        cargarTablaRestricciones();
        cargarMatrizPuestos();
    });
}

async function eliminarRestriccion(id) {
    const ok = await confirmarAccion({ titulo:'Eliminar restricción', mensaje:'¿Eliminar esta restricción?', textoBtn:'Eliminar', tipoBtn:'danger', icono:'fa-trash' });
    if (!ok) return;
    try {
        const res  = await fetch(API_BASE + 'trabajadores.php?action=restriccion&id=' + id, { method:'DELETE' });
        const data = await res.json();
        if (data.success) { mostrarAlerta('✅ Restricción eliminada','success'); cargarTablaRestricciones(); }
        else mostrarAlerta('Error: ' + (data.message || 'No se pudo eliminar'), 'danger');
    } catch(e) { mostrarAlerta('Error de conexión','danger'); }
}

async function agregarRestriccionDesdeSeccion() {
    const trabId = document.getElementById('restriccion-trabajador-select')?.value;
    const tipo   = document.getElementById('restriccion-tipo-select')?.value;
    const desc   = document.getElementById('restriccion-descripcion')?.value?.trim() || '';
    const fi     = document.getElementById('restriccion-fecha-inicio')?.value;
    const ff     = document.getElementById('restriccion-fecha-fin')?.value || null;
    if (!trabId || !tipo || !fi) { mostrarAlerta('Completa los campos obligatorios','warning'); return; }
    await guardarNuevaRestriccion({ trabajador_id: trabId, tipo_restriccion: tipo, descripcion: desc, fecha_inicio: fi, fecha_fin: ff });
}

async function guardarNuevaRestriccion(datos) {
    try {
        const res  = await fetch(API_BASE + 'trabajadores.php?action=restriccion', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(datos) });
        const data = await res.json();
        if (data.success) { mostrarAlerta('✅ Restricción guardada','success'); cargarTablaRestricciones(); }
        else mostrarAlerta('Error: ' + (data.message || 'No se pudo guardar'),'danger');
    } catch(e) { mostrarAlerta('Error de conexión','danger'); }
}

// ══════════════════════════════════════════════════════════════
// GESTIÓN DE INCAPACIDADES
// ══════════════════════════════════════════════════════════════

async function cargarTablaIncapacidades() {
    const tabla = document.getElementById('tabla-incapacidades');
    if (!tabla) return;
    try {
        const res  = await fetch(API_BASE + 'incapacidades.php');
        const data = await res.json();
        if (!data.success || !data.data || data.data.length === 0) {
            tabla.innerHTML = '<p class="info-box">No hay incapacidades registradas.</p>';
            return;
        }
        let html = '<table><thead><tr style="background:linear-gradient(135deg,var(--terminal) 0%,#027433 100%);color:white;">';
        html += '<th>Trabajador</th><th>Tipo</th><th>Inicio</th><th>Fin</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>';
        data.data.forEach(inc => {
            const estadoColor = inc.estado === 'activa' ? '#d4edda' : '#f8d7da';
            const estadoTxt   = inc.estado === 'activa' ? '#155724' : '#721c24';
            html += '<tr>';
            html += '<td>' + (inc.trabajador || inc.trabajador_nombre || '-') + '</td>';
            html += '<td>' + (inc.tipo || inc.tipo_incapacidad || '-') + '</td>';
            html += '<td>' + (inc.fecha_inicio || '-') + '</td>';
            html += '<td>' + (inc.fecha_fin || 'Indefinida') + '</td>';
            html += '<td><span style="background:' + estadoColor + ';color:' + estadoTxt + ';padding:2px 8px;border-radius:4px;font-size:0.82rem;font-weight:600;">' + (inc.estado || '-') + '</span></td>';
            html += '<td><button class="btn btn-sm btn-secondary" onclick="editarIncapacidad(' + inc.id + ')"><i class="fas fa-edit"></i></button> ';
            html += '<button class="btn btn-sm btn-danger" onclick="eliminarIncapacidad(' + inc.id + ')"><i class="fas fa-trash"></i></button></td>';
            html += '</tr>';
        });
        html += '</tbody></table>';
        tabla.innerHTML = html;
    } catch(e) {
        tabla.innerHTML = '<p class="alert alert-danger">Error al cargar incapacidades</p>';
    }
}

async function eliminarIncapacidad(id) {
    const ok = await confirmarAccion({ titulo:'Eliminar incapacidad', mensaje:'¿Eliminar esta incapacidad?', textoBtn:'Eliminar', tipoBtn:'danger', icono:'fa-trash' });
    if (!ok) return;
    try {
        const res  = await fetch(API_BASE + 'incapacidades.php?id=' + id, { method:'DELETE' });
        const data = await res.json();
        if (data.success) { mostrarAlerta('✅ Incapacidad eliminada','success'); cargarTablaIncapacidades(); }
        else mostrarAlerta('Error: ' + (data.message || 'No se pudo eliminar'),'danger');
    } catch(e) { mostrarAlerta('Error de conexión','danger'); }
}

// ══════════════════════════════════════════════════════════════
// GESTIÓN DE DÍAS ESPECIALES
// ══════════════════════════════════════════════════════════════

async function cargarTablaDiasEspeciales() {
    const tabla = document.getElementById('tabla-dias-especiales');
    if (!tabla) return;
    try {
        const res  = await fetch(API_BASE + 'dias_especiales.php?excluir_tipos=L,L8,LC,ADM,ADMM,ADMT');
        const data = await res.json();
        const registros = (data.success && Array.isArray(data.data))
            ? data.data.filter(d => !['ADM','ADMM','ADMT','L','L8','LC'].includes(String(d.tipo || '').toUpperCase()))
            : [];
        if (registros.length === 0) {
            tabla.innerHTML = '<p class="info-box">No hay días especiales registrados.</p>';
            return;
        }
        const colores = { VAC:'#d4edda', SUS:'#fff3cd', INC:'#f8d7da' };
        const textos  = { VAC:'#155724', SUS:'#856404', INC:'#721c24' };
        let html = '<table><thead><tr style="background:linear-gradient(135deg,var(--terminal) 0%,#027433 100%);color:white;">';
        html += '<th>Trabajador</th><th>Tipo</th><th>Fecha inicio</th><th>Fecha fin</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>';
        registros.forEach(d => {
            const bg  = colores[d.tipo] || '#e9ecef';
            const col = textos[d.tipo]  || '#495057';
            html += '<tr>';
            html += '<td>' + (d.trabajador || '-') + '</td>';
            html += '<td><span style="background:' + bg + ';color:' + col + ';padding:2px 8px;border-radius:4px;font-size:0.82rem;font-weight:700;">' + (d.tipo||'-') + '</span></td>';
            html += '<td>' + (d.fecha_inicio||'-') + '</td>';
            html += '<td>' + (d.fecha_fin||'—') + '</td>';
            html += '<td>' + (d.estado||'-') + '</td>';
            html += '<td>';
            html += '<button class="btn btn-sm btn-danger" onclick="eliminarDiaEspecial(' + d.id + ',\'' + (d.tipo||'') + '\')"><i class="fas fa-trash"></i></button>';
            html += '</td></tr>';
        });
        html += '</tbody></table>';
        tabla.innerHTML = html;
    } catch(e) {
        tabla.innerHTML = '<p class="alert alert-danger">Error al cargar días especiales</p>';
    }
}

async function eliminarDiaEspecial(id, tipo) {
    const ok = await confirmarAccion({ titulo:'Eliminar día especial', mensaje:'¿Eliminar este registro de <strong>' + tipo + '</strong>?', textoBtn:'Eliminar', tipoBtn:'danger', icono:'fa-trash' });
    if (!ok) return;
    try {
        const res  = await fetch(API_BASE + 'dias_especiales.php', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({ action:'eliminar', id }) });
        const data = await res.json();
        if (data.success) { mostrarAlerta('✅ Eliminado correctamente','success'); cargarTablaDiasEspeciales(); }
        else mostrarAlerta('Error: ' + (data.message||'No se pudo eliminar'),'danger');
    } catch(e) { mostrarAlerta('Error de conexión','danger'); }
}

function editarDiaEspecial(id) {
    mostrarAlerta('Para editar un día especial usa el formulario de nuevo día especial o la vista mensual del calendario.','info');
}