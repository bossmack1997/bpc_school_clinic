/* Scoped history renderer. Bootstrap owns keyboard-accessible collapse behavior. */
(() => {
  'use strict';
  const base = typeof APP_URL !== 'undefined' ? APP_URL : (window.APP_URL || '');
  const escape = value => String(value ?? '').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const icons = {scan:'qr-code-scan',visit:'clipboard-check',queue:'ticket-detailed',consultation:'heart-pulse',visit_completed:'check-circle',certificate:'file-medical',medicine:'capsule'};
  function dateLabel(value, dateOnly = false) {
    if (!value) return '';
    const input=String(value), date=new Date(input.replace(' ','T') + (/^\d{4}-\d{2}-\d{2}$/.test(input)?'T00:00:00+08:00':/[zZ]|[+-]\d{2}:?\d{2}$/.test(input)?'':'+08:00'));
    if (Number.isNaN(date.getTime())) return escape(value);
    const options={timeZone:'Asia/Manila',month:'short',day:'numeric',year:'numeric'};
    if (!dateOnly) Object.assign(options,{hour:'numeric',minute:'2-digit'});
    return date.toLocaleString('en-PH',options);
  }
  function badge(status) {
    const value=String(status || 'unknown');
    const kind=['success','completed'].includes(value)?'success':['duplicate','pending','waiting'].includes(value)?'warning':['invalid','expired','error','failed','disabled','not_found','cancelled'].includes(value)?'error':'neutral';
    const label=value==='success'?'Successful':value.replace(/_/g,' ').replace(/^./,c=>c.toUpperCase());
    return `<span class="pa-status pa-status-${kind}">${escape(label)}</span>`;
  }
  const state={timeline:{offset:0,rows:[],xhr:null},scans:{offset:0,rows:[],xhr:null}};
  function render(kind) {
    const timeline=kind==='timeline', rows=state[kind].rows;
    const html=rows.map(row=> timeline ? `<div class="hp-timeline-item"><div class="hp-timeline-marker"><i class="bi bi-${icons[row.type] || 'circle'}" aria-hidden="true"></i></div><div class="hp-event-content"><div class="pa-event-heading"><span class="hp-event-title">${escape(row.title)}</span>${badge(row.status)}</div>${row.detail?`<div class="hp-event-detail">${escape(row.detail)}</div>`:''}${row.reference && !String(row.detail || '').includes(row.reference)?`<div class="hp-event-detail">${escape(row.reference)}</div>`:''}<div class="hp-event-time">${dateLabel(row.timestamp,row.precision_kind==='date')}</div></div></div>` :
      `<div class="pa-scan-row"><div class="pa-event-heading"><span class="pa-scan-title">${escape(String(row.purpose || 'QR scan').replace(/_/g,' ').replace(/^./,c=>c.toUpperCase()))}</span>${badge(row.status)}</div><div class="pa-scan-meta">${dateLabel(row.scan_time)}</div><div class="pa-scan-meta">${[row.scan_location,row.scanner_name].filter(Boolean).map(escape).join(' · ')}</div>${row.queue_number || row.queue_id || row.visit_id?`<div class="pa-scan-meta">${escape(row.queue_number?'Queue '+row.queue_number:row.queue_id?'Queue #'+row.queue_id:'Visit #'+row.visit_id)}</div>`:''}</div>`).join('');
    $(timeline?'#qrTimeline':'#qrScanHistoryRows').html(html || `<p class="pa-empty">${timeline?'No visit timeline events available yet.':'No QR scan history available yet.'}</p>`);
  }
  function load(kind, reset = false) {
    const item=state[kind], timeline=kind==='timeline', button=$(timeline?'#visitTimelineMore':'#qrScanHistoryMore');
    if (item.xhr) { if (!reset) return; item.xhr.abort(); }
    if (reset) { item.offset=0; item.rows=[]; }
    button.prop('disabled',true);
    const xhr=$.get(base+'/api/qr_codes.php',{action:timeline?'patient_visit_timeline':'patient_scan_history',offset:item.offset,limit:timeline?10:5});
    item.xhr=xhr;
    xhr.done(res=>{
      if (item.xhr!==xhr) return;
      if (!res.success) { showError(res.message); return; }
      const page=(timeline?res.timeline:res.data) || [];
      item.rows.push(...page);item.offset+=page.length;
      $(timeline?'#visitTimelineCount':'#qrScanHistoryCount').text(Number(res.total || 0));
      if(timeline) $('#cardTimeline').text(Number(res.total || 0)+' events');
      render(kind);button.prop('hidden',!res.has_more).text(timeline?'Show more events':'View more scans');
    }).fail((error,status)=>{if(status!=='abort' && item.xhr===xhr)showError(error.responseJSON?.message);}).always(()=>{if(item.xhr===xhr){item.xhr=null;button.prop('disabled',false);}});
    function showError(message) {
      const target=$(timeline?'#qrTimeline':'#qrScanHistoryRows');
      if (!item.rows.length) target.html(`<p class="pa-empty" role="alert">${escape(message || 'History could not be loaded. Please try again.')}</p>`);
      button.prop('hidden',false).text('Retry');
    }
  }
  window.BPCPatientActivity={refresh(){load('timeline',true);load('scans',true);}};
  $(function(){
    const root=document.querySelector('.patient-activity-sections');if(!root)return;
    $('#visitTimelineMore').on('click',()=>load('timeline'));
    $('#qrScanHistoryMore').on('click',()=>load('scans'));
    if(root.dataset.autoLoad==='true')window.BPCPatientActivity.refresh();
  });
})();
