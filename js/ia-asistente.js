// ─── ASISTENTE IA DE GESTIÓN DE TURNOS ───────────────────────────────────────

const IA_HISTORIAL = [];
let iaAbierto = false;

function toggleChat() {
    iaAbierto = !iaAbierto;
    const panel = document.getElementById('ia-chat-panel');
    if (!panel) return;
    if (iaAbierto) {
        panel.classList.add('open');
    } else {
        panel.classList.remove('open');
    }
    if (iaAbierto) {
        const badge = document.getElementById('ia-badge');
        if (badge) badge.style.display = 'none';
        setTimeout(() => {
            const input = document.getElementById('ia-input');
            if (input) input.focus();
        }, 300);
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
        // Agregar botones de acción si la respuesta los sugiere
        agregarBotonesAccion(bubble, texto);
    }
    div.appendChild(bubble);
    container.appendChild(div);
    container.scrollTop = container.scrollHeight;
    return bubble;
}

function agregarBotonesAccion(bubble, texto) {
    const acciones = [];

    if (texto.includes('asignar') || texto.includes('Asignar')) {
        acciones.push({ texto: '📝 Ver interfaz de asignación', accion: () => window.location.href = '#asignar-turnos' });
    }
    if (texto.includes('equidad') || texto.includes('Equidad') || texto.includes('⚖️')) {
        acciones.push({ texto: '⚖️ Análisis detallado de equidad', accion: () => enviarMensajeIA_direct('Realiza un análisis completo de equidad en turnos') });
    }
    if (texto.includes('predicción') || texto.includes('Predicción') || texto.includes('🔮')) {
        acciones.push({ texto: '🔮 Ver predicciones semana', accion: () => enviarMensajeIA_direct('Predice problemas de cobertura para los próximos 7 días') });
    }

    // Detectar comandos EXCEL embebidos en la respuesta
    const excelRegex = /---EXCEL---\s*([\s\S]*?)---FIN EXCEL---/g;
    let excelMatch;
    while ((excelMatch = excelRegex.exec(texto)) !== null) {
        try {
            const cfg = JSON.parse(excelMatch[1].trim());
            acciones.push({
                texto: cfg.boton || '📥 Descargar Excel',
                accion: () => generarExcelIA(cfg)
            });
        } catch(e) { console.warn('Excel cmd parse error:', e); }
    }

    if (acciones.length === 0 && (texto.includes('reporte') || texto.includes('Reporte') || texto.includes('📊'))) {
        acciones.push({ texto: '📊 Generar reporte Excel', accion: () => enviarMensajeIA_direct('Genera el reporte en Excel con todos los datos del mes actual') });
    }

    if (acciones.length > 0) {
        const botonesDiv = document.createElement('div');
        botonesDiv.className = 'ia-action-buttons';
        acciones.forEach(accion => {
            const btn = document.createElement('button');
            btn.className = 'ia-action-btn';
            btn.textContent = accion.texto;
            btn.onclick = accion.accion;
            botonesDiv.appendChild(btn);
        });
        bubble.appendChild(botonesDiv);
    }
}

function enviarMensajeIA_direct(texto) {
    document.getElementById('ia-input').value = texto;
    enviarMensajeIA();
}

function formatearMarkdown(texto) {
    // Limpiar bloques técnicos antes de mostrar
    texto = texto.replace(/---EXCEL---[\s\S]*?---FIN EXCEL---/g, '');
    texto = texto.replace(/---COMANDO---[\s\S]*?---FIN COMANDO---/g, '');
    texto = texto.trim();
    return texto
        // Headers con mejor styling
        .replace(/^### (.+)$/gm, '<div class="ia-header-3"><i class="fas fa-info-circle"></i> $1</div>')
        .replace(/^## (.+)$/gm, '<div class="ia-header-2"><i class="fas fa-chart-line"></i> $1</div>')
        .replace(/^# (.+)$/gm, '<div class="ia-header-1"><i class="fas fa-star"></i> $1</div>')

        // Texto enfatizado
        .replace(/\*\*\*(.+?)\*\*\*/g, '<strong class="ia-urgent">$1</strong>')
        .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
        .replace(/\*(.+?)\*/g, '<em>$1</em>')
        .replace(/`(.+?)`/g, '<code class="ia-code">$1</code>')

        // Listas mejoradas
        .replace(/^- (.+)$/gm, '<li class="ia-list-item"><i class="fas fa-check-circle"></i> $1</li>')
        .replace(/(<li>.*<\/li>\n?)+/g, '<ul class="ia-list">$&</ul>')

        // Alertas y warnings
        .replace(/⚠️/g, '<i class="fas fa-exclamation-triangle ia-warning"></i>')
        .replace(/🚨/g, '<i class="fas fa-exclamation-circle ia-alert"></i>')
        .replace(/✅/g, '<i class="fas fa-check-circle ia-success"></i>')
        .replace(/❌/g, '<i class="fas fa-times-circle ia-error"></i>')
        .replace(/📋/g, '<i class="fas fa-clipboard-list"></i>')
        .replace(/🔵/g, '<i class="fas fa-circle ia-info"></i>')
        .replace(/📊/g, '<i class="fas fa-chart-bar"></i>')
        .replace(/🏥/g, '<i class="fas fa-hospital"></i>')
        .replace(/🌙/g, '<i class="fas fa-moon"></i>')
        .replace(/👋/g, '<i class="fas fa-hand-paper"></i>')
        .replace(/💡/g, '<i class="fas fa-lightbulb"></i>')
        .replace(/🎯/g, '<i class="fas fa-bullseye"></i>')
        .replace(/📅/g, '<i class="fas fa-calendar"></i>')
        .replace(/👥/g, '<i class="fas fa-users"></i>')
        .replace(/⏰/g, '<i class="fas fa-clock"></i>')
        .replace(/🔄/g, '<i class="fas fa-sync"></i>')

        // Tablas simples (formato markdown básico)
        .replace(/\|(.+)\|/g, function(match) {
            const cells = match.split('|').slice(1, -1).map(cell => cell.trim());
            return '<tr>' + cells.map(cell => `<td>${cell}</td>`).join('') + '</tr>';
        })
        .replace(/(<tr>.*<\/tr>\n?)+/g, '<table class="ia-table">$&</table>')

        // Enlaces clickeables
        .replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" class="ia-link">$1</a>')

        // Saltos de línea
        .replace(/\n\n/g, '</p><p>')
        .replace(/\n/g, '<br>')

        // Wrap in paragraph if not already wrapped
        .replace(/^(.+)$/, '<p>$1</p>')
        .replace(/(<p>.*?<\/p>)\s*(<p>.*?<\/p>)/g, '$1$2');
}

function extraerComandoIA(texto) {
    const inicio = texto.indexOf('---COMANDO---');
    if (inicio === -1) return null;
    const fin = texto.indexOf('---FIN COMANDO---', inicio);
    const jsonText = texto.substring(inicio + '---COMANDO---'.length, fin === -1 ? texto.length : fin).trim();
    try {
        return JSON.parse(jsonText);
    } catch (e) {
        console.warn('No se pudo parsear comando IA:', e);
        return null;
    }
}

// ─── GENERADOR DE EXCEL INTELIGENTE ─────────────────────────────────────────
async function generarExcelIA(cfg) {
    const hoy    = new Date();
    const mes    = cfg.mes    || (hoy.getMonth() + 1);
    const anio   = cfg.anio   || hoy.getFullYear();
    const MESES  = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
    const mesNom = MESES[mes - 1];
    const primerDia = `${anio}-${String(mes).padStart(2,'0')}-01`;
    const ultimoDia = new Date(anio, mes, 0).toISOString().split('T')[0];
    const TIPOS_VALIDOS = ['equidad','cobertura','incapacidades','nocturno','trabajador','general','tnr','dias_libres'];
    const tipoNorm = (cfg.tipo||'').toLowerCase().trim()
        .replace('días','dias').replace('libres','libres')
        .replace('libre','libres').replace('vacaciones','dias_libres')
        .replace('ausentismo','tnr').replace('no_presentado','tnr')
        .replace('no presentados','tnr');
    const tipo = TIPOS_VALIDOS.includes(tipoNorm) ? tipoNorm : 'general';

    agregarMensaje(`⏳ Generando Excel "${cfg.titulo || tipo}" (${mesNom} ${anio})...`, false);

    try {
        const safeFetchXls = (url) => fetch(url)
            .then(r => r.ok ? r.json() : {success:false})
            .catch(() => ({success:false}));

        const [rTurnos, rTrab, rDiasEsp, rInc] = await Promise.all([
            safeFetchXls(API_BASE + 'turnos.php?fecha_inicio=' + primerDia + '&fecha_fin=' + ultimoDia),
            safeFetchXls(API_BASE + 'trabajadores.php'),
            safeFetchXls(API_BASE + 'dias_especiales.php?fecha_inicio=' + primerDia + '&fecha_fin=' + ultimoDia),
            safeFetchXls(API_BASE + 'incapacidades.php?fecha_inicio=' + primerDia + '&fecha_fin=' + ultimoDia)
        ]);

        const todosTurnos   = rTurnos.success  ? (rTurnos.data  || []) : [];
        const turnos        = todosTurnos.filter(t => t.estado !== 'cancelado' && t.estado !== 'no_presentado');
        const trabajadores  = (rTrab.success   ? rTrab.data    : []).filter(t => t.activo);
        const diasEsp       = rDiasEsp.success ? (rDiasEsp.data || []) : [];
        const incapacidades = rInc.success     ? (rInc.data    || []) : [];

        const wb = XLSX.utils.book_new();
        const HDR    = { font:{bold:true,sz:12,color:{rgb:'FFFFFF'}}, fill:{fgColor:{rgb:'025B2D'}}, alignment:{horizontal:'center',vertical:'center'} };
        const SUBHDR = { font:{bold:true,sz:10,color:{rgb:'FFFFFF'}}, fill:{fgColor:{rgb:'495057'}}, alignment:{horizontal:'center',vertical:'center'}, border:{top:{style:'thin',color:{rgb:'DEE2E6'}},bottom:{style:'thin',color:{rgb:'DEE2E6'}},left:{style:'thin',color:{rgb:'DEE2E6'}},right:{style:'thin',color:{rgb:'DEE2E6'}}} };
        const CELDA  = (ri) => ({ font:{sz:9,name:'Calibri'}, fill:{fgColor:{rgb:ri%2===0?'F8F9FA':'FFFFFF'}}, alignment:{horizontal:'left',vertical:'center'}, border:{top:{style:'thin',color:{rgb:'DEE2E6'}},bottom:{style:'thin',color:{rgb:'DEE2E6'}},left:{style:'thin',color:{rgb:'DEE2E6'}},right:{style:'thin',color:{rgb:'DEE2E6'}}} });
        const NUM    = (ri) => ({ ...CELDA(ri), alignment:{horizontal:'center',vertical:'center'} });
        const WARN   = { font:{bold:true,sz:9,color:{rgb:'721C24'}}, fill:{fgColor:{rgb:'F8D7DA'}}, alignment:{horizontal:'center',vertical:'center'}, border:{top:{style:'thin',color:{rgb:'F5C6CB'}},bottom:{style:'thin',color:{rgb:'F5C6CB'}},left:{style:'thin',color:{rgb:'F5C6CB'}},right:{style:'thin',color:{rgb:'F5C6CB'}}} };
        const OK     = { font:{bold:true,sz:9,color:{rgb:'155724'}}, fill:{fgColor:{rgb:'D4EDDA'}}, alignment:{horizontal:'center',vertical:'center'}, border:{top:{style:'thin',color:{rgb:'C3E6CB'}},bottom:{style:'thin',color:{rgb:'C3E6CB'}},left:{style:'thin',color:{rgb:'C3E6CB'}},right:{style:'thin',color:{rgb:'C3E6CB'}}} };
        const MED    = { font:{bold:true,sz:9,color:{rgb:'856404'}}, fill:{fgColor:{rgb:'FFF3CD'}}, alignment:{horizontal:'center',vertical:'center'}, border:{top:{style:'thin',color:{rgb:'FFE083'}},bottom:{style:'thin',color:{rgb:'FFE083'}},left:{style:'thin',color:{rgb:'FFE083'}},right:{style:'thin',color:{rgb:'FFE083'}}} };
        const LIBRE  = { font:{bold:true,sz:9,color:{rgb:'004085'}}, fill:{fgColor:{rgb:'CCE5FF'}}, alignment:{horizontal:'center',vertical:'center'}, border:{top:{style:'thin',color:{rgb:'B8DAFF'}},bottom:{style:'thin',color:{rgb:'B8DAFF'}},left:{style:'thin',color:{rgb:'B8DAFF'}},right:{style:'thin',color:{rgb:'B8DAFF'}}} };
        const VAC_ST = { font:{bold:true,sz:9,color:{rgb:'155724'}}, fill:{fgColor:{rgb:'D4EDDA'}}, alignment:{horizontal:'center',vertical:'center'}, border:{top:{style:'thin',color:{rgb:'C3E6CB'}},bottom:{style:'thin',color:{rgb:'C3E6CB'}},left:{style:'thin',color:{rgb:'C3E6CB'}},right:{style:'thin',color:{rgb:'C3E6CB'}}} };
        const TNR_ST = { font:{bold:true,sz:9,color:{rgb:'721C24'}}, fill:{fgColor:{rgb:'F8D7DA'}}, alignment:{horizontal:'center',vertical:'center'}, border:{top:{style:'thin',color:{rgb:'F5C6CB'}},bottom:{style:'thin',color:{rgb:'F5C6CB'}},left:{style:'thin',color:{rgb:'F5C6CB'}},right:{style:'thin',color:{rgb:'F5C6CB'}}} };

        const colLetrasArr = (n) => Array.from({length:n},(_,i)=>i===0?'A':i<=25?String.fromCharCode(65+i):'A'+String.fromCharCode(65+i-26));
        const setSheet = (ws,data,cols,rowH,merges) => { ws['!cols']=cols; ws['!rows']=rowH||data.map(()=>({hpt:15})); if(merges) ws['!merges']=merges; };
        const applyStyles = (ws,rows,cls,styleFn) => rows.forEach((row,ri)=>cls.forEach((col,ci)=>{ const addr=col+(ri+1); if(!ws[addr])ws[addr]={v:row[ci]??'',t:'s'}; const s=styleFn(ri,ci,row); if(s)ws[addr].s=s; }));

        const titulo = cfg.titulo || `Reporte ${mesNom} ${anio}`;
        const DIAS_SEMANA = ['Dom','Lun','Mar','Mié','Jue','Vie','Sáb'];
        const periodo = `Período: ${primerDia} al ${ultimoDia}`;

        // ── EQUIDAD ──────────────────────────────────────────────────────────
        if (tipo === 'equidad' || tipo === 'general') {
            const cT={},cN={},cL={};
            trabajadores.forEach(t=>{cT[t.id]=0;cN[t.id]=0;cL[t.id]=0;});
            turnos.forEach(t=>{if(cT[t.trabajador_id]!==undefined){cT[t.trabajador_id]++;if(Number(t.numero_turno)===3)cN[t.trabajador_id]++;}});
            diasEsp.filter(d=>['L','L8','LC'].includes(d.tipo)).forEach(d=>{if(cL[d.trabajador_id]!==undefined)cL[d.trabajador_id]++;});
            const prom=turnos.length/Math.max(trabajadores.length,1);
            const rows=[[titulo,'','','','',''],[periodo,'','','','',''],['','','','','',''],['Trabajador','Turnos','T. Noche','Días libres','vs Promedio','Estado']];
            const merges=[{s:{r:0,c:0},e:{r:0,c:5}},{s:{r:1,c:0},e:{r:1,c:5}}];
            [...trabajadores].sort((a,b)=>(cT[b.id]||0)-(cT[a.id]||0)).forEach(t=>{const ct=cT[t.id]||0,dif=ct-Math.round(prom);rows.push([t.nombre,ct,cN[t.id]||0,cL[t.id]||0,(dif>=0?'+':'')+dif,ct===0?'Sin asignar':dif>3?'Exceso':dif<-3?'Déficit':'Equilibrado']);});
            rows.push(['','','','','','']);rows.push([`Promedio: ${prom.toFixed(1)} turnos/trab.`,'','','','','']);
            const ws=XLSX.utils.aoa_to_sheet(rows);
            applyStyles(ws,rows,colLetrasArr(6),(ri,ci,row)=>{if(ri===0||ri===1)return HDR;if(ri===3)return SUBHDR;if(ri>3&&row[0]){const e=row[5];if(ci===5)return e==='Sin asignar'?WARN:e==='Exceso'?MED:e==='Déficit'?WARN:OK;if(ci===4){const v=Number(row[4]);return v>3?MED:v<-3?WARN:NUM(ri);}return ci===0?{...CELDA(ri),font:{bold:true,sz:9}}:NUM(ri);}});
            setSheet(ws,rows,[{wch:30},{wch:10},{wch:12},{wch:12},{wch:14},{wch:12}],rows.map((_,i)=>({hpt:i<2?18:i===3?16:14})),merges);
            XLSX.utils.book_append_sheet(wb,ws,'Equidad');
        }

        // ── DÍAS LIBRES ───────────────────────────────────────────────────────
        if (tipo === 'dias_libres' || tipo === 'general') {
            const TIPOS_LIBRE = ['L','L8','LC','VAC'];
            const libres = diasEsp.filter(d => TIPOS_LIBRE.includes(d.tipo));
            const rows = [[titulo + ' — Días Libres y Vacaciones','','','','',''],
                          [periodo,'','','','',''],['','','','','',''],
                          ['Trabajador','Tipo','Fecha inicio','Fecha fin','Descripción','Estado']];
            const merges=[{s:{r:0,c:0},e:{r:0,c:5}},{s:{r:1,c:0},e:{r:1,c:5}}];

            if (libres.length === 0) {
                rows.push(['Sin días libres registrados en el período','','','','','']);
            } else {
                libres.sort((a,b)=>(a.trabajador||'').localeCompare(b.trabajador||'')||a.fecha_inicio.localeCompare(b.fecha_inicio))
                .forEach(d=>rows.push([
                    d.trabajador||d.trabajador_nombre||'?',
                    d.tipo,
                    d.fecha_inicio,
                    d.fecha_fin||d.fecha_inicio,
                    d.descripcion||'',
                    d.estado||'programado'
                ]));
                // Resumen por trabajador
                rows.push(['','','','','','']);
                rows.push(['── RESUMEN POR TRABAJADOR ──','','','','','']);
                rows.push(['Trabajador','Total L','Total L8','Total LC','Total VAC','Total']);
                const porTrab={};
                trabajadores.forEach(t=>{porTrab[t.nombre]={L:0,L8:0,LC:0,VAC:0};});
                libres.forEach(d=>{const n=d.trabajador||d.trabajador_nombre||'?';if(!porTrab[n])porTrab[n]={L:0,L8:0,LC:0,VAC:0};if(porTrab[n][d.tipo]!==undefined)porTrab[n][d.tipo]++;});
                Object.entries(porTrab).filter(([,v])=>Object.values(v).some(x=>x>0))
                    .sort((a,b)=>a[0].localeCompare(b[0]))
                    .forEach(([nombre,c])=>rows.push([nombre,c.L,c.L8,c.LC,c.VAC,c.L+c.L8+c.LC+c.VAC]));
            }

            const ws=XLSX.utils.aoa_to_sheet(rows);
            applyStyles(ws,rows,colLetrasArr(6),(ri,ci,row)=>{
                if(ri===0||ri===1)return HDR;if(ri===3)return SUBHDR;
                if(ri>3&&row[0]){
                    const isRes=String(row[0]).startsWith('──');const isSubH=row[0]==='Trabajador'&&row[1]==='Total L';
                    if(isRes)return{...SUBHDR,fill:{fgColor:{rgb:'343A40'}}};if(isSubH)return SUBHDR;
                    if(ci===1)return row[1]==='VAC'?VAC_ST:LIBRE;
                    if(typeof row[5]==='number'&&row[5]>0&&ci===5)return{...NUM(ri),font:{bold:true,sz:9}};
                    return CELDA(ri);
                }
            });
            setSheet(ws,rows,[{wch:30},{wch:8},{wch:14},{wch:14},{wch:30},{wch:12}],rows.map((_,i)=>({hpt:i<2?18:i===3?16:14})),merges);
            XLSX.utils.book_append_sheet(wb,ws,'Días Libres');
        }

        // ── COBERTURA ─────────────────────────────────────────────────────────
        if (tipo === 'cobertura' || tipo === 'general') {
            const SOLO_NOCHE=new Set(['V1','V2','C','C2','D3','F6','F11']);
            const PUESTOS_ALL=['D1','D2','D3','D4','F2','F5','F6','F11','F14','F15','V1','V2','C','C2','G'];
            const totalDias=new Date(anio,mes,0).getDate();
            const rows=[[titulo+' — Cobertura diaria','','','',''],[periodo,'','','',''],['','','','',''],['Fecha','Día','Cubiertos','Faltantes','%']];
            const merges=[{s:{r:0,c:0},e:{r:0,c:4}},{s:{r:1,c:0},e:{r:1,c:4}}];
            let totC=0,totE=0;
            for(let d=1;d<=totalDias;d++){
                const fecha=`${anio}-${String(mes).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
                const dt=new Date(fecha+'T00:00:00');
                const cub=new Set(turnos.filter(t=>t.fecha===fecha).map(t=>{const n=Number(t.numero_turno);const b=[4,9].includes(n)?1:[5,10].includes(n)?2:n;return b+'|'+(t.puesto_codigo||'');}));
                let esp=0,falt=[];
                PUESTOS_ALL.forEach(p=>[1,2,3].forEach(tn=>{if(tn===3&&!SOLO_NOCHE.has(p))return;esp++;if(!cub.has(tn+'|'+p))falt.push('T'+tn+p);}));
                totC+=(esp-falt.length);totE+=esp;
                const pct=Math.round(((esp-falt.length)/esp)*100);
                rows.push([fecha,DIAS_SEMANA[dt.getDay()],esp-falt.length,falt.length>0?falt.slice(0,8).join(', ')+(falt.length>8?' +'+(falt.length-8):''):'—',pct+'%']);
            }
            rows.push(['','','','','']);rows.push(['TOTAL MES','',totC,totE-totC,Math.round((totC/totE)*100)+'%']);
            const ws2=XLSX.utils.aoa_to_sheet(rows);
            applyStyles(ws2,rows,colLetrasArr(5),(ri,ci,row)=>{if(ri===0||ri===1)return HDR;if(ri===3)return SUBHDR;if(ri>3&&row[0]){if(ci===4){const p=parseInt(row[4]);return p<50?WARN:p<80?MED:OK;}return ci<=1?NUM(ri):CELDA(ri);}});
            setSheet(ws2,rows,[{wch:12},{wch:6},{wch:12},{wch:55},{wch:8}],rows.map((_,i)=>({hpt:i<2?18:i===3?16:14})),merges);
            XLSX.utils.book_append_sheet(wb,ws2,'Cobertura');
        }

        // ── INCAPACIDADES ─────────────────────────────────────────────────────
        if (tipo === 'incapacidades' || tipo === 'general') {
            const rows=[[titulo+' — Incapacidades','','','','',''],[periodo,'','','','',''],['','','','','',''],['Trabajador','Tipo','Desde','Hasta','Días','Estado']];
            const merges=[{s:{r:0,c:0},e:{r:0,c:5}},{s:{r:1,c:0},e:{r:1,c:5}}];
            if(incapacidades.length===0)rows.push(['Sin incapacidades en el período','','','','','']);
            else incapacidades.forEach(i=>rows.push([i.trabajador||i.trabajador_nombre||'?',i.tipo||'General',i.fecha_inicio,i.fecha_fin,i.dias_incapacidad||'?',i.estado||'activa']));
            const ws3=XLSX.utils.aoa_to_sheet(rows);
            applyStyles(ws3,rows,colLetrasArr(6),(ri,ci,row)=>{if(ri===0||ri===1)return HDR;if(ri===3)return SUBHDR;if(ri>3){if(ci===5)return row[5]==='activa'?WARN:OK;return CELDA(ri);}});
            setSheet(ws3,rows,[{wch:30},{wch:16},{wch:12},{wch:12},{wch:8},{wch:12}],rows.map((_,i)=>({hpt:i<2?18:i===3?16:14})),merges);
            XLSX.utils.book_append_sheet(wb,ws3,'Incapacidades');
        }

        // ── NOCTURNO ──────────────────────────────────────────────────────────
        if (tipo === 'nocturno') {
            const cN={};trabajadores.forEach(t=>{cN[t.id]=0;});
            turnos.filter(t=>Number(t.numero_turno)===3).forEach(t=>{if(cN[t.trabajador_id]!==undefined)cN[t.trabajador_id]++;});
            const rows=[[titulo+' — Turnos Nocturnos','','',''],[periodo,'','',''],['','','',''],['Trabajador','Turnos noche','Estado','Observación']];
            const merges=[{s:{r:0,c:0},e:{r:0,c:3}},{s:{r:1,c:0},e:{r:1,c:3}}];
            [...trabajadores].sort((a,b)=>(cN[b.id]||0)-(cN[a.id]||0)).forEach(t=>{const cn=cN[t.id]||0;rows.push([t.nombre,cn,cn>7?'Exceso':cn===0?'Sin noche':'Normal',cn>7?'Supera límite de 7/mes':cn===0?'No asignado a noche':'']);});
            const ws4=XLSX.utils.aoa_to_sheet(rows);
            applyStyles(ws4,rows,colLetrasArr(4),(ri,ci,row)=>{if(ri===0||ri===1)return HDR;if(ri===3)return SUBHDR;if(ri>3){if(ci===2)return row[2]==='Exceso'?WARN:row[2]==='Sin noche'?MED:OK;return ci===1?NUM(ri):CELDA(ri);}});
            setSheet(ws4,rows,[{wch:30},{wch:14},{wch:12},{wch:36}],rows.map((_,i)=>({hpt:i<2?18:i===3?16:14})),merges);
            XLSX.utils.book_append_sheet(wb,ws4,'Nocturnos');
        }

        // ── TNR ───────────────────────────────────────────────────────────────
        if (tipo === 'tnr') {
            const tnrTurnos=todosTurnos.filter(t=>t.estado==='no_presentado');
            const rows=[[titulo+' — Turnos No Realizados (TNR)','','','','',''],[periodo,'','','','',''],['','','','','',''],['Trabajador','Fecha','Día','Puesto','Turno','Área']];
            const merges=[{s:{r:0,c:0},e:{r:0,c:5}},{s:{r:1,c:0},e:{r:1,c:5}}];
            if(tnrTurnos.length===0)rows.push(['Sin turnos no realizados en el período','','','','','']);
            else{
                tnrTurnos.sort((a,b)=>(a.trabajador||'').localeCompare(b.trabajador||'')||a.fecha.localeCompare(b.fecha));
                tnrTurnos.forEach(t=>{const origN=Number(t.numero_turno);const numN=[4,9].includes(origN)?1:[5,10].includes(origN)?2:origN;const esL4=[4,5,9,10].includes(origN);const dt=new Date(t.fecha+'T00:00:00');rows.push([t.trabajador||'?',t.fecha,DIAS_SEMANA[dt.getDay()],t.puesto_codigo||'?','T'+numN+(esL4?' L4':''),t.area||'?']);});
                rows.push(['','','','','','']);rows.push(['── RESUMEN ──','','','','','']);rows.push(['Trabajador','Total TNR','','','','']);
                const porTrab={};tnrTurnos.forEach(t=>{porTrab[t.trabajador||'?']=(porTrab[t.trabajador||'?']||0)+1;});
                Object.entries(porTrab).sort((a,b)=>b[1]-a[1]).forEach(([n,c])=>rows.push([n,c,'','','','']));
                rows.push(['','','','','','']);rows.push(['TOTAL TNR',tnrTurnos.length,'','','','']);
            }
            const ws5=XLSX.utils.aoa_to_sheet(rows);
            applyStyles(ws5,rows,colLetrasArr(6),(ri,ci,row)=>{if(ri===0||ri===1)return HDR;if(ri===3)return SUBHDR;if(ri>3){const isRes=String(row[0]).startsWith('──')||row[0]==='TOTAL TNR';const isSH=row[0]==='Trabajador'&&row[1]==='Total TNR';if(isRes)return{...SUBHDR,fill:{fgColor:{rgb:'343A40'}}};if(isSH)return SUBHDR;if(ci===1&&typeof row[1]==='number')return TNR_ST;return CELDA(ri);}});
            setSheet(ws5,rows,[{wch:30},{wch:12},{wch:6},{wch:10},{wch:10},{wch:16}],rows.map((_,i)=>({hpt:i<2?18:i===3?16:14})),merges);
            XLSX.utils.book_append_sheet(wb,ws5,'TNR');
        }

        // ── TRABAJADOR individual ─────────────────────────────────────────────
        if (tipo === 'trabajador' && cfg.trabajador_id) {
            const tid=Number(cfg.trabajador_id);const trab=trabajadores.find(t=>t.id===tid);
            const misTurnos=todosTurnos.filter(t=>Number(t.trabajador_id)===tid);
            const misLibres=diasEsp.filter(d=>Number(d.trabajador_id)===tid);
            const misInc=incapacidades.filter(i=>Number(i.trabajador_id)===tid);
            const nombre=trab?trab.nombre:'Trabajador #'+tid;
            const rows=[[`${nombre} — ${mesNom} ${anio}`,'','','',''],[periodo,'','','',''],['','','','',''],['Fecha','Tipo','Puesto','Turno','Estado']];
            const merges=[{s:{r:0,c:0},e:{r:0,c:4}},{s:{r:1,c:0},e:{r:1,c:4}}];
            misTurnos.sort((a,b)=>a.fecha.localeCompare(b.fecha)).forEach(t=>{const n=Number(t.numero_turno);const b2=[4,9].includes(n)?1:[5,10].includes(n)?2:n;rows.push([t.fecha,'Turno T'+b2+([4,5,9,10].includes(n)?' L4':''),t.puesto_codigo||'?',t.turno_nombre||'?',t.estado]);});
            misLibres.forEach(d=>rows.push([d.fecha_inicio,d.tipo,'—','—',d.estado]));
            misInc.forEach(i=>rows.push([i.fecha_inicio+'→'+i.fecha_fin,'INCAPACIDAD','—','—',i.estado]));
            if(rows.length===4)rows.push(['Sin registros en este período','','','','']);
            const ws6=XLSX.utils.aoa_to_sheet(rows);
            applyStyles(ws6,rows,colLetrasArr(5),(ri,ci,row)=>{if(ri===0||ri===1)return HDR;if(ri===3)return SUBHDR;if(ri>3)return CELDA(ri);});
            setSheet(ws6,rows,[{wch:24},{wch:16},{wch:10},{wch:20},{wch:14}],rows.map((_,i)=>({hpt:i<2?18:i===3?16:14})),merges);
            XLSX.utils.book_append_sheet(wb,ws6,nombre.substring(0,28));
        }

        if (wb.SheetNames.length === 0) {
            agregarMensaje('⚠️ No hay datos para generar el reporte.', false);
            return;
        }

        const fileName = `Reporte_IA_${tipo}_${mesNom}_${anio}.xlsx`;
        XLSX.writeFile(wb, fileName);
        agregarMensaje(`✅ Excel **"${cfg.titulo || tipo}"** descargado. (${wb.SheetNames.join(', ')})`, false);

    } catch(err) {
        console.error('generarExcelIA error:', err);
        agregarMensaje('❌ Error generando Excel: ' + err.message, false);
    }
}

async function ejecutarComandoIA(comando) {
    if (!comando || !comando.action) return false;

    if (comando.action === 'assign') {
        const params = comando.params || comando;
        const datos = {
            trabajador_id: params.trabajador_id ? Number(params.trabajador_id) : null,
            puesto_trabajo_id: params.puesto_trabajo_id ? Number(params.puesto_trabajo_id) : null,
            turno_id: params.turno_id ? Number(params.turno_id) : null,
            fecha: params.fecha || null,
            estado: params.estado || 'programado',
            created_by: params.created_by || 1
        };

        // Validar datos obligatorios (turno y fecha siempre obligatorios, puesto es opcional)
        if (!datos.trabajador_id || !datos.turno_id || !datos.fecha) {
            const faltantes = [];
            if (!datos.trabajador_id) faltantes.push('trabajador_id');
            if (!datos.turno_id) faltantes.push('turno_id');
            if (!datos.fecha) faltantes.push('fecha');
            agregarMensaje('❌ No se pudo ejecutar la asignación. Faltan datos: ' + faltantes.join(', '), false);
            return false;
        }

        agregarMensaje('🔄 Validando asignación...', false);

        try {
            // Primero validar
            const validacion = await fetch(`${API_BASE}turnos.php?action=validar`, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(datos)
            }).then(r => {
                if (!r.ok) throw new Error(`HTTP ${r.status}`);
                return r.json();
            });

            if (!validacion.success) {
                agregarMensaje('❌ Error en validación: ' + (validacion.message || 'Error desconocido'), false);
                return false;
            }

            if (!validacion.data?.valido) {
                const errores = validacion.data?.errores || ['Error desconocido en validación'];
                agregarMensaje('⚠️ La asignación no pasó la validación:\n' + errores.map(e => `- ${e}`).join('\n'), false);
                return false;
            }

            agregarMensaje('✅ Validación exitosa. Ejecutando asignación...', false);

            // Luego asignar
            const response = await fetch(`${API_BASE}turnos.php`, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(datos)
            });
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            
            const data = await response.json();

            if (data.success) {
                agregarMensaje('✅ ¡Asignación ejecutada correctamente! ID: ' + (data.id || 'N/A'), false);
                if (typeof cargarEstadisticasDashboard === 'function') {
                    setTimeout(() => cargarEstadisticasDashboard(), 500);
                }
                if (typeof limpiarFormulario === 'function') {
                    setTimeout(() => limpiarFormulario(), 500);
                }
                return true;
            }

            const errores = data.errores ? data.errores.map(e => `- ${e}`).join('\n') : data.message || 'Error al asignar turno';
            agregarMensaje('❌ No se pudo asignar el turno:\n' + errores, false);
            return false;
        } catch (error) {
            console.error('Error ejecutando comando IA:', error);
            agregarMensaje('❌ Error al ejecutar la asignación: ' + error.message, false);
            return false;
        }
    }

    return false;
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
        const primerDiaMesAnterior = (() => {
            const d = new Date(hoy);
            return new Date(d.getFullYear(), d.getMonth() - 1, 1).toISOString().split('T')[0];
        })();
        const ultimoDiaMesAnterior = (() => {
            const d = new Date(hoy);
            return new Date(d.getFullYear(), d.getMonth(), 0).toISOString().split('T')[0];
        })();

        const safeFetch = (url) => fetch(url)
            .then(r => r.ok ? r.json() : { success: false })
            .catch(() => ({ success: false }));

        const [rTrab, rTurnosHoy, rTurnosSemana, rIncap, rDiasEspSemana, rTurnosMes, rDiasEspMes, rPuestos, rIncapMes, rTurnosMesAnterior, rDiasEspMesAnterior, rIncapMesAnterior, rSupervisores] = await Promise.all([
            safeFetch(API_BASE + 'trabajadores.php'),
            safeFetch(API_BASE + 'turnos.php?fecha=' + hoy),
            safeFetch(API_BASE + 'turnos.php?fecha_inicio=' + inicioSemana + '&fecha_fin=' + finSemana),
            safeFetch(API_BASE + 'incapacidades.php?activas=1'),
            safeFetch(API_BASE + 'dias_especiales.php?fecha_inicio=' + inicioSemana + '&fecha_fin=' + finSemana),
            safeFetch(API_BASE + 'turnos.php?fecha_inicio=' + primerDiaMes + '&fecha_fin=' + ultimoDiaMes),
            safeFetch(API_BASE + 'dias_especiales.php?fecha_inicio=' + primerDiaMes + '&fecha_fin=' + ultimoDiaMes),
            safeFetch(API_BASE + 'turnos.php?action=puestos'),
            safeFetch(API_BASE + 'incapacidades.php?fecha_inicio=' + primerDiaMes + '&fecha_fin=' + ultimoDiaMes),
            safeFetch(API_BASE + 'turnos.php?fecha_inicio=' + primerDiaMesAnterior + '&fecha_fin=' + ultimoDiaMesAnterior),
            safeFetch(API_BASE + 'dias_especiales.php?fecha_inicio=' + primerDiaMesAnterior + '&fecha_fin=' + ultimoDiaMesAnterior),
            safeFetch(API_BASE + 'incapacidades.php?fecha_inicio=' + primerDiaMesAnterior + '&fecha_fin=' + ultimoDiaMesAnterior),
            safeFetch(API_BASE + 'supervisores_turno.php?fecha_inicio=' + inicioSemana + '&fecha_fin=' + finSemana)
        ]);

        const trabajadores     = (rTrab.success ? rTrab.data : []).filter(t => t.activo);
        const turnosHoy        = (rTurnosHoy.success ? rTurnosHoy.data : []).filter(t => t.estado !== 'cancelado');
        const turnosSemana     = (rTurnosSemana.success ? rTurnosSemana.data : []).filter(t => t.estado !== 'cancelado');
        const incapacidades    = rIncap.success ? (rIncap.data || []) : [];
        const restricciones    = [];
        const diasEspSemana    = rDiasEspSemana.success ? (rDiasEspSemana.data || []) : [];
        const turnosMes        = (rTurnosMes.success ? rTurnosMes.data : []).filter(t => t.estado !== 'cancelado');
        const diasEspMes       = rDiasEspMes.success ? (rDiasEspMes.data || []) : [];
        const puestosLista     = rPuestos.success ? (rPuestos.data || []) : [];
        const incapacidadesMes = rIncapMes.success ? (rIncapMes.data || []) : [];
        const turnosMesAnterior = (rTurnosMesAnterior.success ? rTurnosMesAnterior.data : []).filter(t => t.estado !== 'cancelado');
        const diasEspMesAnterior = rDiasEspMesAnterior.success ? (rDiasEspMesAnterior.data || []) : [];
        const incapacidadesMesAnterior = rIncapMesAnterior.success ? (rIncapMesAnterior.data || []) : [];
        const supervisores     = rSupervisores.success ? (rSupervisores.data || []) : [];

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
            'FOX':        ['F2','F5','F6','F11','F14','F15'],
            'VIGIA':      ['V1','V2'],
            'TASA DE USO':['C','C2'],
            'EQUIPAJES':  ['G']
        };
        const TURNOS_SISTEMA = [1, 2, 3];

        const puestosCubiertos = new Set(
            turnosHoy.map(t => {
                let n = Number(t.numero_turno);
                if ([4,9].includes(n))  n = 1;
                if ([5,10].includes(n)) n = 2;
                return n + (t.puesto_codigo || '');
            })
        );

        // Solo estos puestos operan en Turno 3 nocturno
        const SOLO_NOCHE_IA = new Set(['V1','V2','C','C2','D3','F6','F11']);

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

        // Trabajadores con día libre esta semana (desde dias_especiales)
        const libresEstaSemana = new Set(
            diasEspSemana
                .filter(d => ['L','L8','LC'].includes(d.tipo))
                .map(d => Number(d.trabajador_id))
        );
        const conLibreSemana  = trabajadores.filter(t =>  libresEstaSemana.has(t.id));
        const sinLibreSemana  = trabajadores.filter(t => !libresEstaSemana.has(t.id));

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
        ? (() => {
            const n0 = Number(turnoHoy.numero_turno);
            const esL4w = [4,5,9,10].includes(n0);
            const nw = [4,9].includes(n0)?1:[5,10].includes(n0)?2:n0;
            return ` [T${nw} ${turnoHoy.puesto_codigo||''}${esL4w?' L4':''}]`;
          })()
        : ' [DISPONIBLE HOY]';
    return `- ${t.nombre} (C.C. ${t.cedula})${estado}${restr}`;
}).join('\n')}

--- TURNOS ASIGNADOS HOY (${turnosHoy.length}) ---
${turnosHoy.length === 0 ? 'Ninguno asignado aún.' :
turnosHoy.map(t => {
    if (t.tipo_especial) return `- ${t.trabajador}: ${t.tipo_especial}`;
    const tnr = t.estado === 'no_presentado' ? ' [TNR - NO SE PRESENTÓ]' : '';
    const origN = Number(t.numero_turno);
    const esL4  = [4,5,9,10].includes(origN);
    const numN  = [4,9].includes(origN) ? 1 : [5,10].includes(origN) ? 2 : origN;
    const etiq  = esL4 ? `T${numN} ${t.puesto_codigo} L4` : `T${numN} ${t.puesto_codigo}`;
    return `- ${etiq} (${t.area}): ${t.trabajador}${tnr}${esL4 ? ' [turno 4h]' : ''}`;
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

--- DÍAS LIBRES ESTA SEMANA (${conLibreSemana.length} trabajadores) ---
${conLibreSemana.length === 0
    ? 'Ninguno registrado aún.'
    : conLibreSemana.map(t => {
        const de = diasEspSemana.find(d => Number(d.trabajador_id) === t.id && ['L','L8','LC'].includes(d.tipo));
        return `- ${t.nombre}: libre el ${de ? de.fecha_inicio : '?'}`;
      }).join('\n')}

--- OTROS ESPECIALES ESTA SEMANA (ADM, ADMM, ADMT) ---
${diasEspSemana.filter(d => ['ADM','ADMM','ADMT'].includes(d.tipo)).length === 0
    ? 'Ninguno.'
    : diasEspSemana
        .filter(d => ['ADM','ADMM','ADMT'].includes(d.tipo))
        .map(d => `- ${d.trabajador || d.trabajador_nombre || '?'}: ${d.tipo} el ${d.fecha_inicio}`)
        .join('\n')}

--- TRABAJADORES SIN DÍA LIBRE ESTA SEMANA (${sinLibreSemana.length}) ---
${sinLibreSemana.length === 0 ? 'Todos tienen día libre asignado esta semana.' :
sinLibreSemana.map(t => `- ${t.nombre}`).join('\n')}

--- RESTRICCIONES TURNO NOCTURNO ---
Turno 3 (noche 22:00-06:00) SOLO opera en: V1, V2, C (Conduces), D3, F6, F11.
Los demás puestos (D1, D2, D4, F2, F5, F14, F15, G) NO tienen Turno 3.
L4 (4 horas): F5 (14-18h), F15 (14-18h), D2 (16-20h), D1 (16-20h), F11 (06-10h). Un L4 SUSTITUYE el turno normal de ese puesto ese día — el puesto sí está cubierto.
TNR (Turno No Realizado): trabajador no se presentó a su turno. Se registra para reportes.

--- ESTRUCTURA DE TURNOS ---
Turno 1 - Mañana: 06:00 - 14:00
Turno 2 - Tarde:  14:00 - 22:00
Turno 3 - Noche:  22:00 - 06:00
L4: turno de 4 horas (horario varía por puesto)
Turnos especiales: L (día libre), ADMM (admin mañana), ADMT (admin tarde), ADM (admin día completo)

--- SUPERVISORES ASIGNADOS (${supervisores.length}) ---
${supervisores.length === 0 ? 'Ninguno registrado.' :
supervisores.map(s => `- ${s.nombre || s.trabajador || '?'}${s.puesto ? ' (' + s.puesto + ')' : ''}`).join('\n')}

--- PUESTOS POR ÁREA ---
DELTA: D1, D2, D3, D4
FOX: F2, F5, F6, F11, F14, F15
VIGÍA: V1, V2
TASA DE USO: C
EQUIPAJES: G

--- RESUMEN MES COMPLETO (${primerDiaMes} al ${ultimoDiaMes}) ---
Total turnos asignados en el mes: ${turnosMes.length}
Días libres (L) asignados en el mes: ${diasEspMes.filter(d => ['L','L8','LC'].includes(d.tipo)).length}

--- RESUMEN MES ANTERIOR (${primerDiaMesAnterior} al ${ultimoDiaMesAnterior}) ---
Total turnos asignados el mes pasado: ${turnosMesAnterior.length}
Días libres (L) asignados el mes pasado: ${diasEspMesAnterior.filter(d => ['L','L8','LC'].includes(d.tipo)).length}
Incapacidades registradas el mes pasado: ${incapacidadesMesAnterior.length}

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
    const SOLO_NOCHE = new Set(['V1','V2','C','C2','D3','F6','F11']);
    const puestosTodos = puestosLista.length > 0
        ? puestosLista.map(p => p.codigo)
        : ['D1','D2','D3','D4','F2','F5','F6','F11','F14','F15','V1','V2','C','G'];

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
    // Limite MUY agresivo para evitar errores de tokens
    // Groq: máx ~4000 tokens de entrada. Con systemPrompt, guardar solo ~2000-2500 tokens para contexto
    // 1 token ≈ 4 caracteres en promedio
    const MAX_CHARS = 8000; // ~2000 tokens aprox (margen seguro)
    
    if (ctx.length <= MAX_CHARS) return ctx;

    // ESTRATEGIA 1: Eliminar secciones menos críticas desde el final
    let truncado = ctx;
    
    // Primero: Eliminar supervisores si están
    truncado = truncado.replace(/--- SUPERVISORES ASIGNADOS.*?(?=---|\Z)/s, '');
    if (truncado.length <= MAX_CHARS) return truncado;
    
    // Segundo: Eliminar otros especiales
    truncado = truncado.replace(/--- OTROS ESPECIALES ESTA SEMANA.*?(?=---|\Z)/s, '');
    if (truncado.length <= MAX_CHARS) return truncado;
    
    // Tercero: Resumir lista de trabajadores disponibles
    truncado = truncado.replace(/--- TRABAJADORES DISPONIBLES HOY.*?\n[\s\S]*?(?=\n---)/,
        '--- TRABAJADORES DISPONIBLES HOY ---\n(Lista disponible en UI del sistema)');
    if (truncado.length <= MAX_CHARS) return truncado;
    
    // Cuarto: Limitar a TOP 10 trabajadores sin día libre
    truncado = truncado.replace(/--- TRABAJADORES SIN DÍA LIBRE.*?\n([\s\S]*?)(?=\n---|$)/,
        (match, content) => {
            const lineas = content.split('\n').filter(l => l.trim()).slice(0, 10);
            return '--- TRABAJADORES SIN DÍA LIBRE ---\n' + lineas.join('\n');
        });
    if (truncado.length <= MAX_CHARS) return truncado;
    
    // Quinto: Eliminar resumen de meses anteriores
    truncado = truncado.replace(/--- RESUMEN MES ANTERIOR.*?(?=\Z)/s, '');
    if (truncado.length <= MAX_CHARS) return truncado;
    
    // Sexto: Limitar lista de turnos asignados a TOP 15
    truncado = truncado.replace(/--- TURNOS ASIGNADOS HOY.*?\n([\s\S]*?)(?=\n---)/,
        (match, content) => {
            const lineas = content.split('\n').filter(l => l.trim()).slice(0, 15);
            return '--- TURNOS ASIGNADOS HOY ---\n' + lineas.join('\n') + 
                   '\n(más datos disponibles en API)';
        });
    if (truncado.length <= MAX_CHARS) return truncado;
    
    // Séptimo: Resumir lista de trabajadores activos a TOP 20
    truncado = truncado.replace(/--- TRABAJADORES ACTIVOS.*?\n([\s\S]*?)(?=\n---)/,
        (match, content) => {
            const lineas = content.split('\n').filter(l => l.trim()).slice(0, 20);
            return '--- TRABAJADORES ACTIVOS (resumen) ---\n' + lineas.join('\n') + 
                   '\n(lista completa en API)';
        });
    if (truncado.length <= MAX_CHARS) return truncado;
    
    // Octavo: Eliminar encabezado largo de estructura
    truncado = truncado.replace(/--- ESTRUCTURA DE TURNOS ---[\s\S]*?(?=---|\Z)/, 
        '--- ESTRUCTURA: T1(06-14h), T2(14-22h), T3(22-06h nocturno), L4(4h)');
    if (truncado.length <= MAX_CHARS) return truncado;
    
    // Si aún es muy grande, truncar sin piedad
    if (truncado.length > MAX_CHARS) {
        truncado = truncado.substring(0, MAX_CHARS) + '\n... (contexto truncado por límite de tokens)';
    }
    
    return truncado;
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

        const systemPrompt = `Eres un asistente de gestión de turnos para la Terminal de Transportes Ibagué.

FUNCIONES:
✓ Analizar cobertura de turnos
✓ Sugerir asignaciones óptimas  
✓ Generar reportes descargables en Excel/PDF
✓ Detectar problemas de equidad
✓ Pronosticar déficits de cobertura

COMANDOS EJECUTABLES:
1. ASIGNAR turno: Responde con ---COMANDO--- {"action":"assign","params":{"trabajador_id":12,"turno_id":1,"fecha":"2026-05-25","puesto_trabajo_id":5}} ---FIN COMANDO---
2. GENERAR EXCEL: Cuando el usuario pida cualquier reporte, Excel, descarga de datos, días libres, TNR, incapacidades, cobertura, equidad o ausentismo, responde SIEMPRE con el bloque:
---EXCEL---
{"tipo":"dias_libres","mes":6,"anio":2026,"titulo":"Días Libres Junio 2026","boton":"📥 Descargar Excel Días Libres"}
---FIN EXCEL---
Tipos EXACTOS disponibles (usa SOLO estos, sin variaciones):
- "equidad"      → turnos por trabajador, noches, libres, vs promedio
- "cobertura"    → puestos sin cubrir día a día con % cobertura
- "incapacidades"→ listado de incapacidades del período
- "nocturno"     → análisis de turnos de noche por trabajador
- "trabajador"   → historial individual (requiere "trabajador_id": ID_NUMERO)
- "general"      → equidad + cobertura + incapacidades juntas
- "tnr"          → turnos no realizados / no presentados
- "dias_libres"  → días libres (L, L8, LC, VAC) por trabajador
Detecta el mes/año del mensaje. Si dice "mayo" usa mes:5, "mes pasado" calcula el anterior, "este mes" usa el actual.
SIEMPRE incluye ---EXCEL--- cuando pidan datos, reportes o descargas.

REGLAS DE TURNOS:
• Turno 1: 06-14h | Turno 2: 14-22h | Turno 3: 22-06h (SOLO V1,V2,C,D3,F6,F11)
• L4: 4 horas (F5/F15/D2/D1/F11) - REEMPLAZA turno normal
• Cada trabajador DEBE tener 1 día libre/semana (L)
• Incapacidades = NO asignar
• TNR = trabajador no se presentó

AREAS: DELTA(D1-4), FOX(F2,5,6,11,14,15), VIGÍA(V1,2), TASA(C,C2), EQUIPAJES(G)

RESPONDER CON:
- **Resumen** del problema/estado
- **Análisis** con números/% 
- **Acción**: botón ejecutable o link descarga
- **Alternativas** cuando sea relevante

Sé conciso, específico, usa emojis. Siempre ofrece descarga de reportes.

DATOS ACTUALES:
${contexto}`;

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

            const comando = extraerComandoIA(respuesta);
            if (comando) {
                await ejecutarComandoIA(comando);
            }

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

function exportarConversacionIA() {
    const fecha = new Date().toLocaleString('es-CO');
    let contenido = `CONVERSACIÓN CON ASISTENTE IA - ${fecha}\n`;
    contenido += '='.repeat(50) + '\n\n';

    IA_HISTORIAL.forEach((msg, i) => {
        const rol = msg.role === 'user' ? 'Usuario' : 'Asistente IA';
        contenido += `[${rol}] ${msg.content}\n\n`;
    });

    // Crear blob y descargar
    const blob = new Blob([contenido], { type: 'text/plain;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `conversacion-ia-${new Date().toISOString().split('T')[0]}.txt`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);

    // Feedback visual
    const btn = document.querySelector('.ia-export-btn');
    if (btn) {
        const originalIcon = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-check"></i>';
        btn.style.background = '#198754';
        setTimeout(() => {
            btn.innerHTML = originalIcon;
            btn.style.background = '';
        }, 1500);
    }
}

// Exponer funciones globales y asegurar eventos del botón flotante
function inicializarAsistenteIA() {
    window.toggleChat = toggleChat;
    window.enviarMensajeIA = enviarMensajeIA;
    window.enviarSugerencia = enviarSugerencia;
    window.iaKeyDown = iaKeyDown;
    window.autoResize = autoResize;
    window.exportarConversacionIA = exportarConversacionIA;

    const bubble = document.getElementById('ia-chat-bubble');
    if (bubble) {
        bubble.addEventListener('click', toggleChat);
        bubble.style.pointerEvents = 'auto';
    }
}

document.addEventListener('DOMContentLoaded', inicializarAsistenteIA);
console.log('IA asistente cargado');

// ─── ALERTA PROACTIVA AL CARGAR ─────────────────────────────────────────────

async function verificarAlertasIA() {
    try {
        const hoy = new Date().toISOString().split('T')[0];
        const mañana = new Date(Date.now() + 86400000).toISOString().split('T')[0];

        const [rTurnosHoy, rTrab, rTurnosMañana] = await Promise.all([
            fetch(API_BASE + 'turnos.php?fecha=' + hoy).then(r => r.json()),
            fetch(API_BASE + 'trabajadores.php').then(r => r.json()),
            fetch(API_BASE + 'turnos.php?fecha=' + mañana).then(r => r.json()).catch(() => ({ success: false }))
        ]);

        const turnosHoy  = (rTurnosHoy.success ? rTurnosHoy.data : []).filter(t => t.estado !== 'cancelado');
        const totalTrab  = (rTrab.success ? rTrab.data : []).filter(t => t.activo).length;
        const turnosMañana = (rTurnosMañana.success ? rTurnosMañana.data : []).filter(t => t.estado !== 'cancelado');

        // Calcular métricas de cobertura
        const TOTAL_ESPERADO = 17 * 3; // 17 puestos × 3 turnos
        const coberturaHoy = (turnosHoy.length / TOTAL_ESPERADO) * 100;
        const coberturaMañana = turnosMañana.length > 0 ? (turnosMañana.length / TOTAL_ESPERADO) * 100 : null;

        // Alertas inteligentes
        let alertas = [];

        // Cobertura crítica hoy
        if (coberturaHoy < 30) {
            alertas.push(`🚨 **CRÍTICO:** Solo ${turnosHoy.length} turnos asignados hoy (${coberturaHoy.toFixed(1)}% de cobertura)`);
        } else if (coberturaHoy < 50) {
            alertas.push(`⚠️ **Atención:** Cobertura baja hoy (${coberturaHoy.toFixed(1)}%) - ${TOTAL_ESPERADO - turnosHoy.length} puestos faltantes`);
        }

        // Problema mañana
        if (coberturaMañana !== null && coberturaMañana < 40) {
            alertas.push(`🔮 **Predicción:** Mañana tendrá cobertura crítica (${coberturaMañana.toFixed(1)}%)`);
        }

        // Verificar días libres esta semana
        const inicioSemana = (() => {
            const d = new Date();
            const day = d.getDay();
            const diff = d.getDate() - day + (day === 0 ? -6 : 1);
            return new Date(d.setDate(diff)).toISOString().split('T')[0];
        })();

        const rDiasEsp = await fetch(API_BASE + 'dias_especiales.php?fecha_inicio=' + inicioSemana + '&fecha_fin=' + mañana).then(r => r.json()).catch(() => ({ success: false }));
        const diasEspSemana = rDiasEsp.success ? rDiasEsp.data : [];

        const libresEstaSemana = new Set(
            diasEspSemana
                .filter(d => ['L','L8','LC'].includes(d.tipo))
                .map(d => Number(d.trabajador_id))
        );

        const sinLibreSemana = totalTrab - libresEstaSemana.size;
        if (sinLibreSemana > 0) {
            alertas.push(`⚖️ **Equidad:** ${sinLibreSemana} trabajadores sin día libre esta semana`);
        }

        // Mostrar alertas si hay alguna crítica
        if (alertas.length > 0) {
            mostrarBadgeIA();

            if (iaAbierto) {
                const mensajeAlerta = `**🚨 ALERTAS DEL SISTEMA**\n\n${alertas.join('\n\n')}\n\n¿Te ayudo a resolver estos problemas? Puedo sugerir asignaciones específicas y generar un plan de acción.`;
                agregarMensaje(mensajeAlerta, false);
            }
        } else if (coberturaHoy < 70) {
            // Badge sutil para cobertura moderada
            mostrarBadgeIA();
        }
    } catch(e) {
        console.error('Error en alertas IA:', e);
    }
}

// Ejecutar verificación 3s después de cargar la página
window.addEventListener('load', () => {
    setTimeout(verificarAlertasIA, 3000);
});