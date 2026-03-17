<?php
include  'modalEvidencia.html';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dashboard - Sistema de Gestión de Visitas</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="icon" type="image/png" href="img/terminal.jpeg">
  <link rel="stylesheet" href="css/dashboard.css">
</head>
<body>
  <!-- Header -->
  <div class="header bg-light py-2 mb-4 shadow-sm">
    <div class="container d-flex justify-content-between align-items-center">
      <h2><i class="fas fa-clipboard-list me-2"></i>Sistema de Gestión de Visitas</h2>
      <div class="d-flex align-items-center">
        <span><i class="fas fa-user me-1"></i>Administrador</span>
        <button class="btn btn-light btn-sm ms-3" id="themeToggle">
          <i class="fas fa-moon"></i>
        </button>
      </div>
    </div>
  </div>

  <div class="container main-content">
    <h2 class="page-title mb-4">Dashboard de Funcionalidades</h2>

    <!-- Quick Actions -->
    <div class="quick-actions mb-4">
      <a href="turnos.html" class="quick-btn btn btn-secondary me-2"><i class=" fas fa-plus-circle"></i>Turnos</a>
      <a href="registro.html" class="quick-btn btn btn-primary me-2"><i class="fas fa-plus-circle"></i> Nueva Visita</a>
      <a href="historial.html" class="quick-btn btn btn-secondary me-2"><i class="fas fa-history"></i> Inspección de visitas</a>
      <a href="visitas.html" class="quick-btn btn btn-secondary me-2"><i class="fas fa-history"></i>Visitas</a>
     <!-- Botón -->
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalEvidencia">
  Subir Evidencia
</button>


<!-- Contenedor donde se cargará el modal externo -->

      <!-- Dropdown Reportes -->
      <div class="dropdown d-inline-block">
      
      </div>
    </div>



    <!-- Resumen Cards -->
    <div class="row g-3 mb-4" id="resumenCards"></div>

    <!-- Gráficas -->
    <div class="row g-3 mb-4">
     <div class="col-md-6">
  <div class="card card-custom p-3 shadow-sm">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h5>Visitas por Estado</h5>
    </div>
    <div class="chart-container">
      <canvas id="statusChart"></canvas>
    </div>
  </div>
</div>

<div class="col-md-6">
  <div class="card card-custom p-3 shadow-sm">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h5>Distribución por Responsable</h5>
    </div>
    <div class="chart-container">
      <canvas id="responsibleChart"></canvas>
    </div>
  </div>
</div>


    <!-- Próximos a vencer -->
    <div class="card card-custom p-3 mb-4 shadow-sm">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h5>Próximos a vencer</h5>
      </div>
      <div class="table-responsive">
        <table class="table table-hover" id="proximosTable">
          <thead>
            <tr>
              <th>ID</th>
              <th>Descripción</th>
              <th>Fecha Límite</th>
              <th>Responsable</th>
              <th>Estado</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
    </div>

    <!-- Visitas recientes -->
    <div class="card card-custom p-3 mb-4 shadow-sm">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h5>Visitas recientes</h5>
      </div>
      <div class="table-responsive">
        <table class="table table-hover" id="visitasTable">
          <thead>
            <tr>
              <th>Fecha</th>
              <th>Nombre</th>
              <th>Aspecto</th>
              <th>Estado</th>
              <th>Responsable</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
    </div>

  </div>

  <!-- Scripts -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/js/all.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>

  <!-- Tus scripts locales -->

  <script src="js/export.js"></script>
  <script src="js/evidencia.js"></script>
  <script src="js/dashboard.js"></script>
</body>
</html>
