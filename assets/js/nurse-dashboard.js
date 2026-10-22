(function () {
  'use strict';

  var root=document.getElementById('nurseDashboard');
  if(!root)return;
  var data=JSON.parse(document.getElementById('nurseDashboardData').textContent);
  var RANGES=data?data.ranges:{};
  var selectedRange='week';
  var title=root.querySelector('[data-nurse-empty-title]');
  var description=root.querySelector('[data-nurse-empty-desc]');
  var status=document.getElementById('nurseDashboardStatus');


  var GREEN = '#0B6B3A';
  var GREEN_SOFT = 'rgba(11,107,58,0.08)';
  var GREEN_BAR = 'rgba(11,107,58,0.85)';
  var MUTED = '#8A94A3';

  var canvas = document.getElementById('nurseVisitsChart');
  var empty  = document.getElementById('nurseVisitsChartEmpty');
  var wrap   = document.getElementById('nurseVisitsChartWrap');
  var chart  = null;

  function syncEmptyState(range) {
    var total = (RANGES[range] && RANGES[range].values) ? RANGES[range].values.reduce(function (a, b) { return a + b; }, 0) : 0;
    var hasData = total > 0;
    if (empty) empty.hidden = hasData;
    if (wrap) wrap.hidden = !hasData;
    return hasData;
  }

  function buildChart(range) {
    if(!data){if(wrap)wrap.hidden=true;if(empty)empty.hidden=false;title.textContent='Unable to load clinic activity';description.textContent='Clinic data is temporarily unavailable. Automatic refresh will retry.';return;}
    if(!canvas)return;
    var key = RANGES[range] ? range : 'week';
    var cfg = RANGES[key];

    if (chart) { chart.destroy(); chart = null; }

    var hasData = syncEmptyState(key);
    title.textContent='No visits recorded';description.textContent='Consultation activity will appear here once patients are seen in this period.';
    if (!hasData) return;
    if(typeof Chart==='undefined'){wrap.hidden=true;empty.hidden=false;title.textContent='Consultation activity';description.textContent=cfg.values.reduce(function(a,b){return a+b;},0)+' visits recorded in this period. The chart is temporarily unavailable.';return;}

    chart = new Chart(canvas.getContext('2d'), {
      type: cfg.line ? 'line' : 'bar',
      data: {
        labels: cfg.labels,
        datasets: [{
          label: 'Patient visits',
          data: cfg.values,
          borderColor: GREEN,
          backgroundColor: cfg.line ? GREEN_SOFT : GREEN_BAR,
          borderWidth: 2,
          borderRadius: cfg.line ? 0 : 4,
          fill: cfg.line,
          tension: 0.35,
          pointRadius: 0,
          pointHoverRadius: 4,
          pointHoverBackgroundColor: GREEN,
          pointHoverBorderColor: '#FFFFFF',
          pointHoverBorderWidth: 2,
          barPercentage: 0.6,
          categoryPercentage: 0.8
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: { duration: 380, easing: 'easeOutCubic' },
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: '#1F2937',
            padding: 10,
            cornerRadius: 8,
            displayColors: false,
            titleFont: { family: 'Inter, system-ui, sans-serif', weight: '600', size: 12 },
            bodyFont: { family: 'Inter, system-ui, sans-serif', size: 12 },
            callbacks: {
              label: function (ctx) { return ctx.parsed.y + (ctx.parsed.y === 1 ? ' visit' : ' visits'); }
            }
          }
        },
        scales: {
          x: {
            grid: { display: false },
            border: { display: false },
            ticks: { color: MUTED, font: { family: 'Inter, system-ui, sans-serif', size: 11 }, maxRotation: 0, autoSkipPadding: 12 }
          },
          y: {
            beginAtZero: true,
            grid: { color: 'rgba(31,41,55,0.06)' },
            border: { display: false },
            ticks: { precision: 0, color: MUTED, font: { family: 'Inter, system-ui, sans-serif', size: 11 }, maxTicksLimit: 5 }
          }
        }
      }
    });
  }

  function selectRange(range) {
    selectedRange=range;
    var buttons = root.querySelectorAll('[data-nurse-range]');
    Array.prototype.forEach.call(buttons, function (btn) {
      var on = btn.getAttribute('data-nurse-range') === range;
      btn.classList.toggle('adm-btn--primary', on);
      btn.classList.toggle('adm-btn--secondary', !on);
      btn.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    buildChart(range);
  }

  root.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('[data-nurse-range]') : null;
    if (btn && root.contains(btn)) selectRange(btn.getAttribute('data-nurse-range'));
  });

  function apply(snapshot){
    data=snapshot;RANGES=snapshot.ranges;
    root.querySelectorAll('[data-metric]').forEach(function(el){var key=el.getAttribute('data-metric');if(Object.prototype.hasOwnProperty.call(snapshot,key))el.textContent=Number(snapshot[key]).toLocaleString();});
    document.getElementById('nurseActivitySummary').textContent=snapshot.visits_today+' consultations recorded today. '+snapshot.queue_waiting+' waiting and '+snapshot.queue_serving+' currently being served.';
    buildChart(selectedRange);
  }
  var inFlight=false;
  async function refresh(){
    if(inFlight || document.hidden)return;
    inFlight=true;
    var controller=new AbortController();var timeout=setTimeout(function(){controller.abort();},10000);
    try{
      var response=await fetch(root.dataset.endpoint,{credentials:'same-origin',cache:'no-store',signal:controller.signal});var body=await response.json();
      if(!response.ok||!body.success||!body.data)throw new Error(body.message||'Unable to refresh clinic activity.');
      apply(body.data);status.hidden=true;
    }catch(error){status.textContent='Unable to refresh clinic activity. Last available values are shown; automatic refresh will retry.';status.hidden=false;}
    finally{clearTimeout(timeout);inFlight=false;}
  }
  if(data)apply(data);else buildChart(selectedRange);
  var timer=setInterval(refresh,30000);
  document.addEventListener('visibilitychange',function(){if(!document.hidden)refresh();});
  window.addEventListener('online',refresh);
  window.addEventListener('pagehide',function(){clearInterval(timer);if(chart){chart.destroy();chart=null;}});
  window.addEventListener('pageshow',function(event){if(event.persisted){refresh();clearInterval(timer);timer=setInterval(refresh,30000);}});
})();
