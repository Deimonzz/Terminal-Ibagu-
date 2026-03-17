// Configuración para jsPDF
const { jsPDF } = window.jspdf;

document.addEventListener('DOMContentLoaded', function() {
  // Exportar PDF general
  document.getElementById('exportPdf').addEventListener('click', exportDashboardPdf);
  document.getElementById('exportExcel').addEventListener('click', exportDashboardExcel);
  
  // Exportar gráficas individuales
  document.querySelectorAll('.btn-pdf[data-chart]').forEach(btn => {
    btn.addEventListener('click', function() {
      exportChartPdf(this.dataset.chart);
    });
  });
  
  // Exportar tablas individuales
  document.querySelectorAll('.btn-pdf[data-table]').forEach(btn => {
    btn.addEventListener('click', function() {
      exportTablePdf(this.dataset.table);
    });
  });
  
  document.querySelectorAll('.btn-excel[data-table]').forEach(btn => {
    btn.addEventListener('click', function() {
      exportTableExcel(this.dataset.table);
    });
  });
});

function exportDashboardPdf() {
  const doc = new jsPDF();
  const date = new Date().toLocaleDateString('es-ES');
  
  // Título
  doc.setFontSize(20);
  doc.text('Reporte de Dashboard - Sistema de Gestión de Visitas', 20, 20);
  
  // Fecha
  doc.setFontSize(12);
  doc.text(`Generado el: ${date}`, 20, 30);
  
  // Resumen
  doc.setFontSize(16);
  doc.text('Resumen General', 20, 50);
  
  let yPosition = 70;
  
  // Aquí agregarías los datos del resumen
  const resumenCards = document.querySelectorAll('.stat-card');
  resumenCards.forEach((card, index) => {
    if (index % 2 === 0 && index > 0) {
      yPosition += 30;
    }
    
    const title = card.querySelector('h6').textContent;
    const value = card.querySelector('h3').textContent;
    
    doc.setFontSize(12);
    doc.text(`${title}: ${value}`, 20 + (index % 2) * 100, yPosition);
  });
  
  yPosition += 40;
  
  // Gráficas (aquí necesitarías convertir las gráficas a imágenes)
  // Por simplicidad, solo agregamos los títulos
  doc.setFontSize(16);
  doc.text('Gráficas de Análisis', 20, yPosition);
  
  doc.save(`dashboard-reporte-${date}.pdf`);
}

function exportDashboardExcel() {
  const wb = XLSX.utils.book_new();
  const date = new Date().toLocaleDateString('es-ES');
  
  // Hoja de resumen
  const resumenData = [
    ['Reporte de Dashboard - Sistema de Gestión de Visitas'],
    [`Generado el: ${date}`],
    [],
    ['Métrica', 'Valor']
  ];
  
  const resumenCards = document.querySelectorAll('.stat-card');
  resumenCards.forEach(card => {
    const title = card.querySelector('h6').textContent;
    const value = card.querySelector('h3').textContent;
    resumenData.push([title, value]);
  });
  
  XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(resumenData), 'Resumen');
  
  // Hoja de próximos a vencer
  const proximosData = [['ID', 'Descripción', 'Fecha Límite', 'Responsable', 'Estado']];
  document.querySelectorAll('#proximosTable tbody tr').forEach(row => {
    const cells = row.querySelectorAll('td');
    proximosData.push([
      cells[0].textContent,
      cells[1].textContent,
      cells[2].textContent,
      cells[3].textContent,
      cells[4].textContent
    ]);
  });
  
  XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(proximosData), 'Próximos a Vencer');
  
  // Hoja de visitas recientes
  const visitasData = [['Fecha', 'Nombre', 'Aspecto', 'Estado', 'Responsable']];
  document.querySelectorAll('#visitasTable tbody tr').forEach(row => {
    const cells = row.querySelectorAll('td');
    visitasData.push([
      cells[0].textContent,
      cells[1].textContent,
      cells[2].textContent,
      cells[3].textContent,
      cells[4].textContent
    ]);
  });
  
  XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(visitasData), 'Visitas Recientes');
  
  XLSX.writeFile(wb, `dashboard-completo-${date}.xlsx`);
}

function exportChartPdf(chartId) {
  const canvas = document.getElementById(chartId);
  const chartImage = canvas.toDataURL('image/png');
  const doc = new jsPDF();
  const date = new Date().toLocaleDateString('es-ES');
  
  doc.setFontSize(16);
  doc.text(`Gráfica: ${getChartTitle(chartId)}`, 20, 20);
  doc.text(`Generado el: ${date}`, 20, 30);
  
  // Agregar la imagen de la gráfica
  doc.addImage(chartImage, 'PNG', 20, 40, 170, 150);
  
  doc.save(`grafica-${chartId}-${date}.pdf`);
}

function exportTablePdf(tableId) {
  const table = document.getElementById(tableId);
  const title = table.closest('.card-custom').querySelector('h5').textContent;
  const date = new Date().toLocaleDateString('es-ES');
  
  const doc = new jsPDF();
  
  doc.setFontSize(16);
  doc.text(title, 20, 20);
  doc.setFontSize(12);
  doc.text(`Generado el: ${date}`, 20, 30);
  
  let yPosition = 50;
  
  // Encabezados
  const headers = [];
  table.querySelectorAll('thead th').forEach(th => {
    if (th.textContent !== 'Acciones') {
      headers.push(th.textContent);
    }
  });
  
  doc.setFontSize(10);
  headers.forEach((header, index) => {
    doc.text(header, 20 + (index * 40), yPosition);
  });
  
  yPosition += 10;
  
  // Datos
  table.querySelectorAll('tbody tr').forEach(row => {
    const cells = row.querySelectorAll('td');
    let xPosition = 20;
    
    cells.forEach((cell, index) => {
      if (index < cells.length - 1) { // Excluir columna de acciones
        doc.text(cell.textContent, xPosition, yPosition);
        xPosition += 40;
      }
    });
    
    yPosition += 10;
    
    // Nueva página si es necesario
    if (yPosition > 270) {
      doc.addPage();
      yPosition = 20;
    }
  });
  
  doc.save(`tabla-${tableId}-${date}.pdf`);
}

function exportTableExcel(tableId) {
  const table = document.getElementById(tableId);
  const title = table.closest('.card-custom').querySelector('h5').textContent;
  const date = new Date().toLocaleDateString('es-ES');
  
  const data = [];
  
  // Encabezados (excluyendo la columna de acciones)
  const headers = [];
  table.querySelectorAll('thead th').forEach(th => {
    if (th.textContent !== 'Acciones') {
      headers.push(th.textContent);
    }
  });
  data.push(headers);
  
  // Datos
  table.querySelectorAll('tbody tr').forEach(row => {
    const rowData = [];
    const cells = row.querySelectorAll('td');
    
    cells.forEach((cell, index) => {
      if (index < cells.length - 1) { // Excluir columna de acciones
        rowData.push(cell.textContent);
      }
    });
    
    data.push(rowData);
  });
  
  const wb = XLSX.utils.book_new();
  const ws = XLSX.utils.aoa_to_sheet(data);
  XLSX.utils.book_append_sheet(wb, ws, title.substring(0, 31)); // Máximo 31 caracteres
  
  XLSX.writeFile(wb, `tabla-${tableId}-${date}.xlsx`);
}

function getChartTitle(chartId) {
  const titles = {
    'statusChart': 'Visitas por Estado',
    'responsibleChart': 'Distribución por Responsable'
  };
  return titles[chartId] || 'Gráfica';
}