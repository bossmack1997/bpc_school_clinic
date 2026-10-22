<?php // Reusable only within already-authorized patient pages. ?>
<div class="patient-activity-sections" data-auto-load="<?= !empty($activityAutoLoad) ? 'true' : 'false' ?>">
  <section class="pa-card pa-timeline" aria-labelledby="visitTimelineToggle">
    <h2 class="pa-heading">
      <button type="button" id="visitTimelineToggle" class="pa-toggle collapsed" data-bs-toggle="collapse" data-bs-target="#visitTimelinePanel" aria-expanded="false" aria-controls="visitTimelinePanel">
        <span><i class="bi bi-clock-history" aria-hidden="true"></i> Visit Timeline <span class="pa-count" id="visitTimelineCount" aria-label="Total visit events">…</span></span>
        <i class="bi bi-chevron-down pa-chevron" aria-hidden="true"></i>
      </button>
    </h2>
    <div class="collapse" id="visitTimelinePanel" role="region" aria-labelledby="visitTimelineToggle">
      <div class="pa-body">
        <div id="qrTimeline" class="hp-minimal-timeline" aria-live="polite"><p class="pa-empty">Loading visit events…</p></div>
        <button class="btn btn-sm btn-outline-success pa-more" type="button" id="visitTimelineMore" hidden>Show more events</button>
      </div>
    </div>
  </section>
  <section class="pa-card pa-scans" aria-labelledby="qrScanHistoryHeading">
    <h2 class="pa-heading pa-scan-heading" id="qrScanHistoryHeading"><i class="bi bi-qr-code-scan" aria-hidden="true"></i> QR Scan History <span class="pa-count" id="qrScanHistoryCount">…</span></h2>
    <div class="pa-body">
      <div id="qrScanHistoryRows" aria-live="polite"><p class="pa-empty">Loading QR scan history…</p></div>
      <button class="btn btn-sm btn-outline-success pa-more" type="button" id="qrScanHistoryMore" hidden>View more scans</button>
    </div>
  </section>
</div>
