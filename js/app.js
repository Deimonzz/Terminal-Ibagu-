// Lista de aspectos evaluados
let aspectos = [];

function agregarAspecto() {
  // Obtener valores del formulario
  const aspecto = document.getElementById("aspecto").value.trim();
  const descripcion = document.getElementById("descripcion").value.trim();
  const observacion = document.getElementById("observacion").value;
  const recurrente = document.getElementById("recurrente").checked;
  const plazo = document.getElementById("plazo").value;
  const actividad = document.getElementById("actividad").value.trim();
  const responsable = document.getElementById("responsable").value.trim();
  const evidencia = document.getElementById("evidencia").files[0];
  const obs_adicionales = document.getElementById("obs_adicionales").value.trim();

  if (!aspecto || !descripcion || !actividad || !responsable) {
    alert("Por favor completa todos los campos obligatorios.");
    return;
  }

  // Guardar aspecto en el array
  aspectos.push({
    aspecto,
    descripcion,
    observacion,
    recurrente,
    plazo,
    actividad,
    responsable,
    evidencia,
    obs_adicionales
  });

  // Mostrar en tabla resumen
  renderTabla();

  // Limpiar formulario
  limpiarFormularioAspecto();
}

function renderTabla() {
  const tbody = document.querySelector("#tablaAspectos tbody");
  tbody.innerHTML = "";

  aspectos.forEach((a, i) => {
    const row = document.createElement("tr");

    row.innerHTML = `
      <td>${a.aspecto}</td>
      <td>${a.observacion}</td>
      <td>${a.actividad}</td>
      <td>${a.responsable}</td>
      <td><button onclick="eliminarAspecto(${i})">Eliminar</button></td>
    `;

    tbody.appendChild(row);
  });
}

function eliminarAspecto(index) {
  if (confirm("¿Eliminar este aspecto?")) {
    aspectos.splice(index, 1);
    renderTabla();
  }
}

function limpiarFormularioAspecto() {
  document.getElementById("aspecto").value = "";
  document.getElementById("descripcion").value = "";
  document.getElementById("observacion").value = "CUMPLE";
  document.getElementById("recurrente").checked = false;
  document.getElementById("plazo").value = "";
  document.getElementById("actividad").value = "";
  document.getElementById("responsable").value = "";
  document.getElementById("evidencia").value = "";
  document.getElementById("obs_adicionales").value = "";
}

function guardarVisita() {
  const nombre_visita = document.getElementById("nombre_visita").value.trim();
  const fecha_inicio = document.getElementById("fecha_inicio").value;
  const fecha_fin = document.getElementById("fecha_fin").value;

  if (!nombre_visita || !fecha_inicio || !fecha_fin) {
    alert("Completa los datos generales de la visita.");
    return;
  }

  if (aspectos.length === 0) {
    alert("Agrega al menos un aspecto evaluado.");
    return;
  }

  const formData = new FormData();
  formData.append("nombre_visita", nombre_visita);
  formData.append("fecha_inicio", fecha_inicio);
  formData.append("fecha_fin", fecha_fin);

  // Guardar evidencias por separado
  aspectos.forEach((a, i) => {
    formData.append(`aspectos[${i}][aspecto]`, a.aspecto);
    formData.append(`aspectos[${i}][descripcion]`, a.descripcion);
    formData.append(`aspectos[${i}][observacion]`, a.observacion);
    formData.append(`aspectos[${i}][recurrente]`, a.recurrente);
    formData.append(`aspectos[${i}][plazo]`, a.plazo);
    formData.append(`aspectos[${i}][actividad]`, a.actividad);
    formData.append(`aspectos[${i}][responsable]`, a.responsable);
    formData.append(`aspectos[${i}][obs_adicionales]`, a.obs_adicionales);
    if (a.evidencia) {
      formData.append(`aspectos[${i}][evidencia]`, a.evidencia);
    }
  });
  
console.log(aspectos);

  fetch("guardar_visita.php", {
    method: "POST",
    body: formData
  })
  .then(res => res.text())
  .then(res => {
    alert("Visita guardada con éxito.");
    location.reload(); // Reiniciar todo
  })
  .catch(err => {
    console.error(err);
    alert("Error al guardar.");
  });
}
