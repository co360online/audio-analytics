(function(){
  if (typeof CO360AUDIOADMIN === 'undefined') return;

  document.addEventListener('DOMContentLoaded', function(){

    // ===== Gráfica general (por audio) =====
    var items = CO360AUDIOADMIN.items || [];
    var ctx   = document.getElementById('co360-audio-chart');

    if (ctx && items.length) {
      var labels  = items.map(function(i){ return i.title; });
      var plays   = items.map(function(i){ return i.plays; });
      var seconds = items.map(function(i){ return i.seconds; });

      new Chart(ctx, {
        type: 'bar',
        data: {
          labels: labels,
          datasets: [
            {
              label: 'Reproducciones',
              data: plays,
              yAxisID: 'y',
              // backgroundColor: '#E35053'
            },
            {
              label: 'Tiempo total (segundos)',
              data: seconds,
              yAxisID: 'y1',
              // backgroundColor: '#EA7A72'
            }
          ]
        },
        options: {
          responsive: true,
          interaction: {
            mode: 'index',
            intersect: false
          },
          scales: {
            y: {
              beginAtZero: true,
              position: 'left',
              title: { display: true, text: 'Reproducciones' }
            },
            y1: {
              beginAtZero: true,
              position: 'right',
              grid: { drawOnChartArea: false },
              title: { display: true, text: 'Tiempo total (segundos)' }
            }
          }
        }
      });
    }

    // ===== Gráfica de detalle (evolución diaria) =====
    var dctx = document.getElementById('co360-audio-detail-chart');

    if (dctx) {
      var detail = CO360AUDIOADMIN.detail || null;
      var dLabels  = [];
      var dPlays   = [];
      var dSeconds = [];

      if (detail && detail.items && detail.items.length) {
        dLabels  = detail.items.map(function(i){ return i.date; });
        dPlays   = detail.items.map(function(i){ return i.plays; });
        dSeconds = detail.items.map(function(i){ return i.seconds; });
      }

      new Chart(dctx, {
        type: 'line',
        data: {
          labels: dLabels,
          datasets: [
            {
              label: 'Reproducciones diarias',
              data: dPlays,
              yAxisID: 'y',
              tension: 0.2,
              // borderColor: '#E35053',
              // backgroundColor: 'rgba(227,80,83,0.15)',
              borderWidth: 2,
              pointRadius: 3,
              // pointBackgroundColor: '#E35053'
            },
            {
              label: 'Tiempo total diario (segundos)',
              data: dSeconds,
              yAxisID: 'y1',
              tension: 0.2,
              // borderColor: '#EA7A72',
              // backgroundColor: 'rgba(234,122,114,0.15)',
              borderWidth: 2,
              pointRadius: 3,
              // pointBackgroundColor: '#EA7A72'
            }
          ]
        },
        options: {
          responsive: true,
          interaction: {
            mode: 'index',
            intersect: false
          },
          scales: {
            y: {
              beginAtZero: true,
              position: 'left',
              title: { display: true, text: 'Reproducciones' }
            },
            y1: {
              beginAtZero: true,
              position: 'right',
              grid: { drawOnChartArea: false },
              title: { display: true, text: 'Tiempo (segundos)' }
            }
          }
        }
      });
    }

  });
})();
