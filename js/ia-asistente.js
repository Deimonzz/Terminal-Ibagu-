// ─── ASISTENTE IA DE GESTIÓN DE TURNOS ───────────────────────────────────────

const IA_HISTORIAL = [];
let iaAbierto = false;

function toggleChat() {
    iaAbierto = !iaAbierto;
    const panel = document.getElementById('ia-chat-panel');
    panel.classList.toggle('open', iaAbierto);
    if (iaAbierto) {
        document.getElementById('ia-badge').style.display = 'none';
        setTimeout(() => document.getElementById('ia-input').focus(), 300);
    }
}

function iaKeyDown(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        enviarMensajeIA();
    }
}

function autoResize(el) {
    el.style.height = 'auto';
    el.style.height = Math.min(el.scrollHeight, 100) + 'px';
}

function enviarSugerencia(texto) {
    document.getElementById('ia-sugerencias').style.display = 'none';
    document.getElementById('ia-input').value = texto;
    enviarMensajeIA();
}

function agregarMensaje(texto, esUsuario) {
    const container = document.getElementById('ia-messages');
    const div = document.createElement('div');
    div.className = 'ia-msg ' + (esUsuario ? 'ia-msg-user' : 'ia-msg-bot');
    const bubble = document.createElement('div');
    bubble.className = 'ia-msg-bubble';
    if (esUsuario) {
        bubble.textContent = texto;
    } else {
        bubble.innerHTML = formatearMarkdown(texto);
    }
    div.appendChild(bubble);
    container.appendChild(div);
    container.scrollTop = container.scrollHeight;
    return bubble;
}

function formatearMarkdown(texto) {
    return texto
        .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
        .replace(/\*(.+?)\*/g, '<em>$1</em>')
        .replace(/`(.+?)`/g, '<code>$1</code>')
        .replace(/^### (.+)$/gm, '<strong style="display:block;margin:8px 0 4px;font-size:0.92rem;">$1</strong>')
        .replace(/^## (.+)$/gm, '<strong style="display:block;margin:10px 0 4px;font-size:0.95rem;border-bottom:1px solid #dee2e6;padding-bottom:3px;">$1</strong>')
        .replace(/^- (.+)$/gm, '<li>$1</li>')
        .replace(/(<li>.*<\/li>\n?)+/g, '<ul>$&</ul>')
        .replace(/\n\n/g, '<br><br>')
        .replace(/\n/g, '<br>');
}

function mostrarTyping() {
    const t = document.getElementById('ia-typing');
    t.style.display = 'block';
    document.getElementById('ia-messages').scrollTop = 99999;
}
function ocultarTyping() {
    document.getElementById('ia-typing').style.display = 'none';
}

// ─── RECOPILAR CONTEXTO REAL DEL SISTEMA ────────────────────────────────────

async function obtenerContextoSistema() {
    const hoy = new Date().toISOString().split('T')[0];
    const inicioSemana = (() => {
        const d = new Date();
        const day = d.getDay();
        const diff = d.getDate() - day + (day === 0 ? -6 : 1);
        return new Date(d.setDate(diff)).toISOString().split('T')[0];
    })();
    const finSemana = (() => {
        const d = new Date(inicioSemana);
        d.setDate(d.getDate() + 6);
        return d.toISOString().split('T')[0];
    })();

    try {
        // Calcular rango del mes actual
        const primerDiaMes = hoy.substring(0, 7) + '-01';
        const ultimoDiaMes = (() => {
            const d = new Date(hoy);
            return new Date(d.getFullYear(), d.getMonth() + 1, 0).toISOString().split('T')[0];
        })();

        const [rTrab, rTurnosHoy, rTurnosSemana, rIncap, rDiasEsp, rTurnosMes, rDiasEspMes, rPuestos, rIncapMes] = await Promise.all([
            fetch(API_BASE + 'trabajadores.php').then(r => r.json()),
            fetch(API_BASE + 'turnos.php?fecha=' + hoy).then(r => r.json()),
            fetch(API_BASE + 'turnos.php?fecha_inicio=' + inicioSemana + '&fecha_fin=' + finSemana).then(r => r.json()),
            fetch(API_BASE + 'incapacidades.php?activas=1').then(r => r.json()),
            fetch(API_BASE + 'incapacidades.php?fecha_inicio=' + primerDiaMes + '&fecha_fin=' + ultimoDiaMes).then(r => r.json()).catch(() => ({ success: false })),
            fetch(API_BASE + 'dias_especiales.php').then(r => r.json()).catch(() => ({ success: false })),
            fetch(API_BASE + 'turnos.php?fecha_inicio=' + primerDiaMes + '&fecha_fin=' + ultimoDiaMes).then(r => r.json()).catch(() => ({ success: false })),
            fetch(API_BASE + 'dias_especiales.php?fecha_inicio=' + primerDiaMes + '&fecha_fin=' + ultimoDiaMes).then(r => r.json()).catch(() => ({ success: false })),
            fetch(API_BASE + 'turnos.php?action=puestos').then(r => r.json()).catch(() => ({ success: false }))
        ]);

        const trabajadores   = (rTrab.success ? rTrab.data : []).filter(t => t.activo);
        const turnosHoy      = (rTurnosHoy.success ? rTurnosHoy.data : []).filter(t => t.estado !== 'cancelado');
        const turnosSemana   = (rTurnosSemana.success ? rTurnosSemana.data : []).filter(t => t.estado !== 'cancelado');
        const incapacidades  = rIncap.success ? (rIncap.data || []) : [];
        const restricciones  = [];
        const diasEspeciales = rDiasEsp.success ? (rDiasEsp.data || []) : [];
        const turnosMes        = (rTurnosMes.success ? rTurnosMes.data : []).filter(t => t.estado !== 'cancelado');
        const diasEspMes       = rDiasEspMes.success ? (rDiasEspMes.data || []) : [];
        const puestosLista     = rPuestos.success ? (rPuestos.data || []) : [];
        const incapacidadesMes = rIncapMes.success ? (rIncapMes.data || []) : [];

        // Trabajadores con incapacidad activa hoy
        const trabConIncap = new Set(incapacidades.map(i => Number(i.trabajador_id)));

        // Trabajadores con turno hoy
        const trabConTurnoHoy = new Set(turnosHoy.map(t => Number(t.trabajador_id)));

        // Trabajadores disponibles hoy (sin turno, sin incapacidad)
        const disponiblesHoy = trabajadores.filter(t =>
            !trabConTurnoHoy.has(t.id) && !trabConIncap.has(t.id)
        );

        // Puestos cubiertos hoy (por turno)
        const PUESTOS_SISTEMA = {
            'DELTA':      ['D1','D2','D3','D4'],
            'FOX':        ['F4','F5','F6','F11','F14','F15'],
            'VIGIA':      ['V1','V2'],
            'TASA DE USO':['C'],
            'EQUIPAJES':  ['G']
        };
        const TURNOS_SISTEMA = [1, 2, 3];

        const puestosCubiertos = new Set(
            turnosHoy.map(t => t.numero_turno + (t.puesto_codigo || ''))
        );

        // Solo estos puestos operan en Turno 3 nocturno
        const SOLO_NOCHE_IA = new Set(['V1','V2','C','D3','F6','F11']);

        const puestosSinCubrir = [];
        Object.entries(PUESTOS_SISTEMA).forEach(([area, puestos]) => {
            puestos.forEach(p => {
                TURNOS_SISTEMA.forEach(num => {
                    // Turno 3 solo aplica a puestos nocturnos específicos
                    if (num === 3 && !SOLO_NOCHE_IA.has(p)) return;
                    if (!puestosCubiertos.has(num + p)) {
                        puestosSinCubrir.push({ area, puesto: p, turno: num });
                    }
                });
            });
        });

        // Trabajadores sin día libre esta semana
        const libresEstaSemana = new Set(
            turnosSemana
                .filter(t => t.tipo_especial === 'L')
                .map(t => Number(t.trabajador_id))
        );
        const sinLibreSemana = trabajadores.filter(t => !libresEstaSemana.has(t.id));

        // Restricciones agrupadas por trabajador
        const restrPorTrab = {};
        restricciones.forEach(r => {
            if (!restrPorTrab[r.trabajador_id]) restrPorTrab[r.trabajador_id] = [];
            restrPorTrab[r.trabajador_id].push(r.puesto_codigo || r.puesto_nombre);
        });

        // Construir contexto legible para el modelo
        const ctx = `
=== SISTEMA DE GESTIÓN DE TURNOS - Terminal Ibagué ===
Fecha actual: ${hoy} (${new Date().toLocaleDateString('es-CO', { weekday: 'long' })})
Semana actual: ${inicioSemana} al ${finSemana}

--- TRABAJADORES ACTIVOS (${trabajadores.length} total) ---
${trabajadores.map(t => {
    const tieneIncap = trabConIncap.has(t.id);
    const tieneTurnoHoy = trabConTurnoHoy.has(t.id);
    const turnoHoy = turnosHoy.find(x => Number(x.trabajador_id) === t.id);
    const restr = restrPorTrab[t.id] ? ' | Restricciones: ' + restrPorTrab[t.id].join(', ') : '';
    const estado = tieneIncap ? ' [INCAPACITADO]' : tieneTurnoHoy
        ? ` [T${turnoHoy.numero_turno || turnoHoy.tipo_especial} ${turnoHoy.puesto_codigo || ''}]`
        : ' [DISPONIBLE HOY]';
    return `- ${t.nombre} (C.C. ${t.cedula})${estado}${restr}`;
}).join('\n')}

--- TURNOS ASIGNADOS HOY (${turnosHoy.length}) ---
${turnosHoy.length === 0 ? 'Ninguno asignado aún.' :
turnosHoy.map(t => {
    if (t.tipo_especial) return `- ${t.trabajador}: ${t.tipo_especial}`;
    const tnr = t.estado === 'no_presentado' ? ' [TNR - NO SE PRESENTÓ]' : '';
    return `- T${t.numero_turno} ${t.puesto_codigo} (${t.area}): ${t.trabajador}${tnr}`;
}).join('\n')}

--- PUESTOS SIN CUBRIR HOY (${puestosSinCubrir.length}) ---
${puestosSinCubrir.length === 0 ? 'Todos los puestos están cubiertos.' :
puestosSinCubrir.map(p => `- Turno ${p.turno} | ${p.puesto} | ${p.area}`).join('\n')}

--- TRABAJADORES DISPONIBLES HOY (sin turno ni incapacidad) ---
${disponiblesHoy.length === 0 ? 'Ninguno disponible.' :
disponiblesHoy.map(t => `- ${t.nombre}`).join('\n')}

--- INCAPACIDADES ACTIVAS (${incapacidades.length}) ---
${incapacidades.length === 0 ? 'Ninguna.' :
incapacidades.map(i => `- ${i.trabajador_nombre || i.trabajador}: hasta ${i.fecha_fin} (${i.tipo || 'general'})`).join('\n')}

--- DÍAS ESPECIALES ESTA SEMANA ---
${diasEspeciales.filter(d => d.fecha >= inicioSemana && d.fecha <= finSemana).length === 0
    ? 'Ninguno.'
    : diasEspeciales
        .filter(d => d.fecha >= inicioSemana && d.fecha <= finSemana)
        .map(d => `- ${d.trabajador_nombre || ''}: ${d.tipo} el ${d.fecha}`)
        .join('\n')}

--- TRABAJADORES SIN DÍA LIBRE ESTA SEMANA (${sinLibreSemana.length}) ---
${sinLibreSemana.length === 0 ? 'Todos tienen día libre asignado.' :
sinLibreSemana.map(t => `- ${t.nombre}`).join('\n')}

--- RESTRICCIONES TURNO NOCTURNO ---
Turno 3 (noche 22:00-06:00) SOLO opera en: V1, V2, C (Conduces), D3, F6, F11.
Los demás puestos (D1, D2, D4, F4, F5, F14, F15, G) NO tienen Turno 3.
L4 (4 horas): F5 (14-18h), F15 (14-18h), D2 (16-20h), F11 (06-10h).
TNR (Turno No Realizado): trabajador no se presentó a su turno. Se registra para reportes.

--- ESTRUCTURA DE TURNOS ---
Turno 1 - Mañana: 06:00 - 14:00
Turno 2 - Tarde:  14:00 - 22:00
Turno 3 - Noche:  22:00 - 06:00
L4: turno de 4 horas (horario varía por puesto)
Turnos especiales: L (día libre), ADMM (admin mañana), ADMT (admin tarde), ADM (admin día completo)

--- PUESTOS POR ÁREA ---
DELTA: D1, D2, D3, D4
FOX: F4, F5, F6, F11, F14, F15
VIGÍA: V1, V2
TASA DE USO: C
EQUIPAJES: G

--- RESUMEN MES COMPLETO (${primerDiaMes} al ${ultimoDiaMes}) ---
Total turnos asignados en el mes: ${turnosMes.length}
Días libres (L) asignados en el mes: ${diasEspMes.filter(d => ['L','L8','LC'].includes(d.tipo)).length}

--- VACACIONES EN EL MES ---
${(() => {
    const vacs = diasEspMes.filter(d => d.tipo === 'VAC');
    if (vacs.length === 0) return 'Ninguna vacación registrada este mes.';
    return vacs.map(d => `- ${d.trabajador||d.trabajador_nombre||'?'}: del ${d.fecha_inicio} al ${d.fecha_fin||d.fecha_inicio}`).join('\n');
})()}

--- INCAPACIDADES EN EL MES ---
${(() => {
    if (incapacidadesMes.length === 0) return 'Ninguna incapacidad este mes.';
    return incapacidadesMes.map(i =>
        '- ' + (i.trabajador||i.trabajador_nombre||'?') +
        ': del ' + i.fecha_inicio + ' al ' + i.fecha_fin +
        ' (' + (i.tipo_incapacidad||i.tipo||'general') + ')' +
        (i.dias_duracion ? ' — ' + i.dias_duracion + ' días' : '')
    ).join('\n');
})()}

--- OTROS DÍAS ESPECIALES EN EL MES ---
${(() => {
    const otros = diasEspMes.filter(d => !['L','L8','LC','VAC'].includes(d.tipo));
    if (otros.length === 0) return 'Ninguno.';
    return otros.map(d =>
        '- ' + (d.trabajador||d.trabajador_nombre||'?') +
        ': ' + d.tipo + ' el ' + d.fecha_inicio +
        (d.descripcion ? ' (' + d.descripcion + ')' : '')
    ).join('\n');
})()}

Puestos sin cubrir por día (primeros 10 días con problemas):
${(() => {
    const SOLO_NOCHE = new Set(['V1','V2','C','D3','F6','F11']);
    const puestosTodos = puestosLista.length > 0
        ? puestosLista.map(p => p.codigo)
        : ['D1','D2','D3','D4','F4','F5','F6','F11','F14','F15','V1','V2','C','G'];

    // Índice de turnos por fecha+puesto+turno
    const cubiertos = {};
    turnosMes.forEach(t => {
        const key = t.fecha + '|' + (t.puesto_codigo||'') + '|' + t.numero_turno;
        cubiertos[key] = true;
    });

    const diasMes = new Date(new Date(hoy).getFullYear(), new Date(hoy).getMonth() + 1, 0).getDate();
    const año = new Date(hoy).getFullYear();
    const mes = new Date(hoy).getMonth() + 1;

    const diasConProblemas = [];
    for (let d = 1; d <= diasMes; d++) {
        const fecha = año + '-' + String(mes).padStart(2,'0') + '-' + String(d).padStart(2,'0');
        const faltantes = [];
        puestosTodos.forEach(p => {
            [1,2,3].forEach(turno => {
                if (turno === 3 && !SOLO_NOCHE.has(p)) return;
                if (!cubiertos[fecha + '|' + p + '|' + turno]) {
                    faltantes.push('T' + turno + p);
                }
            });
        });
        if (faltantes.length > 0) diasConProblemas.push({ fecha, faltantes });
    }

    if (diasConProblemas.length === 0) return 'Todos los días del mes tienen cobertura completa.';
    return diasConProblemas.slice(0, 10).map(d =>
        d.fecha + ': faltan ' + d.faltantes.join(', ')
    ).join('\n') + (diasConProblemas.length > 10 ? '\n... y ' + (diasConProblemas.length - 10) + ' días más con problemas' : '');
})()}

Trabajadores con más turnos en el mes (top 5):
${(() => {
    const conteo = {};
    turnosMes.forEach(t => { conteo[t.trabajador] = (conteo[t.trabajador]||0) + 1; });
    return Object.entries(conteo).sort((a,b)=>b[1]-a[1]).slice(0,5)
        .map(([n,c]) => '- ' + n + ': ' + c + ' turnos').join('\n') || 'Sin datos.';
})()}

Trabajadores con menos turnos en el mes (posibles sin asignar, top 5):
${(() => {
    const conteo = {};
    trabajadores.forEach(t => { conteo[t.nombre] = 0; });
    turnosMes.forEach(t => { if (conteo[t.trabajador] !== undefined) conteo[t.trabajador]++; });
    return Object.entries(conteo).sort((a,b)=>a[1]-b[1]).slice(0,5)
        .map(([n,c]) => '- ' + n + ': ' + c + ' turnos').join('\n') || 'Sin datos.';
})()}
`.trim();

        return ctx;

    } catch (e) {
        console.error('Error obteniendo contexto:', e);
        return 'Error al obtener datos del sistema. Responde con lo que puedas.';
    }
}

// ─── TRUNCADO INTELIGENTE DE CONTEXTO ────────────────────────────────────────
// Groq llama-3.3-70b tiene límite de ~6000 tokens en el system prompt
// Priorizamos: instrucciones + hoy + semana + incapacidades/vacaciones + resumen mes

function truncarContexto(ctx) {
    const MAX_CHARS = 12000; // ~4000 tokens aprox — margen seguro para Groq
    if (ctx.length <= MAX_CHARS) return ctx;

    // Dividir el contexto en secciones por encabezados ---
    const secciones = ctx.split('\n---').map((s, i) => i === 0 ? s : '---' + s);

    // Prioridad de secciones (las primeras se mantienen, las últimas se recortan)
    const PRIORIDAD = [
        'ESTRUCTURA DE TURNOS',
        'PUESTOS POR ÁREA',
        'RESTRICCIONES TURNO',
        'TRABAJADORES ACTIVOS',
        'TURNOS ASIGNADOS HOY',
        'PUESTOS SIN CUBRIR HOY',
        'INCAPACIDADES ACTIVAS',
        'VACACIONES EN EL MES',
        'INCAPACIDADES EN EL MES',
        'TRABAJADORES DISPONIBLES',
        'DÍAS ESPECIALES ESTA SEMANA',
        'OTROS DÍAS ESPECIALES',
        'PUESTOS SIN CUBRIR POR DÍA',  // más pesado — va al final
        'TRABAJADORES CON MÁS TURNOS',
        'TRABAJADORES CON MENOS',
        'TRABAJADORES SIN DÍA LIBRE',
        'RESUMEN MES COMPLETO',
    ];

    // Ordenar secciones según prioridad
    const ordenadas = [...secciones].sort((a, b) => {
        const ia = PRIORIDAD.findIndex(p => a.includes(p));
        const ib = PRIORIDAD.findIndex(p => b.includes(p));
        return (ia === -1 ? 99 : ia) - (ib === -1 ? 99 : ib);
    });

    // Ir agregando secciones hasta llenar el límite
    let resultado = '';
    for (const sec of ordenadas) {
        if ((resultado + sec).length > MAX_CHARS) {
            // Intentar incluir al menos las primeras líneas de la sección
            const lineas = sec.split('\n');
            const cabecera = lineas.slice(0, 3).join('\n');
            if ((resultado + cabecera).length <= MAX_CHARS) {
                resultado += cabecera + '\n[... truncado por límite de tokens]\n';
            }
            break;
        }
        resultado += sec;
    }

    return resultado;
}

// ─── ENVIAR MENSAJE AL ASISTENTE ────────────────────────────────────────────

async function enviarMensajeIA() {
    const input  = document.getElementById('ia-input');
    const btn    = document.getElementById('ia-send-btn');
    const texto  = input.value.trim();
    if (!texto) return;

    // Limpiar input
    input.value = '';
    input.style.height = 'auto';
    input.disabled = true;
    btn.disabled = true;

    // Ocultar chips
    document.getElementById('ia-sugerencias').style.display = 'none';

    // Mostrar mensaje usuario
    agregarMensaje(texto, true);
    mostrarTyping();

    // Agregar al historial
    IA_HISTORIAL.push({ role: 'user', content: texto });

    try {
        // Obtener contexto actualizado del sistema
        const contexto = await obtenerContextoSistema();

        const systemPrompt = `Eres un asistente experto en gestión de turnos laborales para la Terminal de Transportes de Ibagué. Tienes acceso en tiempo real a todos los datos del sistema.

Tu objetivo es ayudar al jefe de turno a:
1. Identificar puestos sin cubrir y recomendar quién asignar
2. Detectar problemas: trabajadores sin día libre, incapacidades, restricciones violadas
3. Sugerir asignaciones específicas respetando todas las reglas
4. Responder preguntas sobre el estado del sistema
5. Proporcionar resúmenes claros y accionables

REGLAS DEL SISTEMA que debes respetar siempre:
- El Turno 3 (nocturno 22:00-06:00) SOLO opera en estos puestos: V1, V2, C (Conduces), D3, F6, F11. Los demás puestos NO tienen Turno 3.
- L4 solo aplica en F5, F15, D2, F11 con horarios específicos
- Cada trabajador debe tener 1 día libre (L) por semana obligatoriamente
- Los trabajadores con incapacidad activa no pueden ser asignados
- Los cumpleaños NO están registrados en el sistema (la tabla trabajadores no tiene fecha_nacimiento). Si te preguntan sobre cumpleaños, indica que ese dato no está disponible en la BD actual.
- TNR = Turno No Realizado: el trabajador no se presentó. Cuenta como turno asignado pero no realizado.
- Los trabajadores pueden tener restricciones de puesto específico (ej: no puede ir a D1). Respétalas al sugerir asignaciones.
- Respetar restricciones individuales de cada trabajador (no_turno_noche, no_fuerza_fisica, movilidad_limitada, problema_visual, puesto_especifico)

DATOS ACTUALES DEL SISTEMA:
${contexto}

Responde de forma concisa, práctica y en español. Usa listas para facilitar la lectura. Cuando sugieras asignaciones, sé específico: indica el trabajador, el puesto y el turno. Si hay algo urgente, menciónalo primero.`;

        // Construir mensajes para la API (últimos 10 de historial para no exceder tokens)
        const mensajesAPI = IA_HISTORIAL.slice(-10).map(m => ({
            role: m.role,
            content: m.content
        }));

        const response = await fetch(API_BASE + 'ia_proxy.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                system: truncarContexto(systemPrompt),
                messages: mensajesAPI
            })
        });

        const data = await response.json();
        ocultarTyping();

        if (data.content && data.content[0]) {
            const respuesta = data.content[0].text;
            agregarMensaje(respuesta, false);
            IA_HISTORIAL.push({ role: 'assistant', content: respuesta });

            // Verificar si hay alertas urgentes mencionadas
            if (respuesta.toLowerCase().includes('urgente') || respuesta.toLowerCase().includes('sin cubrir')) {
                if (!iaAbierto) mostrarBadgeIA();
            }
        } else {
            agregarMensaje('Lo siento, no pude procesar la respuesta. Intenta de nuevo.', false);
        }

    } catch (e) {
        ocultarTyping();
        console.error('Error IA:', e);
        agregarMensaje('Error de conexión con el asistente. Verifica tu conexión e intenta de nuevo.', false);
    } finally {
        input.disabled = false;
        btn.disabled = false;
        input.focus();
    }
}

function mostrarBadgeIA() {
    document.getElementById('ia-badge').style.display = 'flex';
}

// ─── ALERTA PROACTIVA AL CARGAR ─────────────────────────────────────────────

async function verificarAlertasIA() {
    try {
        const hoy = new Date().toISOString().split('T')[0];
        const [rTurnosHoy, rTrab] = await Promise.all([
            fetch(API_BASE + 'turnos.php?fecha=' + hoy).then(r => r.json()),
            fetch(API_BASE + 'trabajadores.php').then(r => r.json())
        ]);

        const turnosHoy  = (rTurnosHoy.success ? rTurnosHoy.data : []).filter(t => t.estado !== 'cancelado');
        const totalTrab  = (rTrab.success ? rTrab.data : []).filter(t => t.activo).length;

        // Si hay menos del 30% del día cubierto → mostrar badge
        const TOTAL_ESPERADO = 17 * 3;
        if (turnosHoy.length < TOTAL_ESPERADO * 0.3) {
            mostrarBadgeIA();

            // Si el chat ya está abierto, avisar automáticamente
            if (iaAbierto) {
                agregarMensaje(
                    `⚠️ **Alerta automática:** Solo hay **${turnosHoy.length}** turnos asignados hoy de ${TOTAL_ESPERADO} esperados. ¿Quieres que revise qué puestos faltan y sugiera asignaciones?`,
                    false
                );
            }
        }
    } catch(e) {
        // Silencioso
    }
}

// Ejecutar verificación 3s después de cargar la página
window.addEventListener('load', () => {
    setTimeout(verificarAlertasIA, 3000);
});