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