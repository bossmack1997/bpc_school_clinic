<?php
// Render the same bounded recent-audit result as a desktop table/mobile activity list.
$auditTimezone=new DateTimeZone('Asia/Manila');
?>
<section class="card p-3 mis-audit-summary" aria-labelledby="audit-summary-title">
  <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
    <h2 class="h6 mb-0" id="audit-summary-title">Administrative audit summary</h2>
    <a class="small text-nowrap" href="activity_logs.php">View history</a>
  </div>
  <div class="table-responsive"><table class="table table-sm mb-0">
    <thead><tr><th scope="col">Action</th><th scope="col">Module</th><th scope="col">Time</th></tr></thead>
    <tbody>
    <?php foreach($data['recent_audit'] as $entry):
      $action=(string)$entry['action'];$lower=mb_strtolower($action);
      $icon=match(true){str_contains($lower,'logged out'),str_contains($lower,'logout')=>'box-arrow-right',str_contains($lower,'logged in'),str_contains($lower,'login')=>'box-arrow-in-right',str_contains($lower,'password')=>'key',default=>'clock-history'};
      $module=['auth'=>'Authentication','security'=>'Security','admin'=>'Administration','account_security'=>'Account security','admin_profiles'=>'Administrator profiles','backup'=>'Backup'][$entry['module']]??$entry['module'];
      try {$date=new DateTimeImmutable($entry['created_at'],$auditTimezone);$compact=$date->format('M j, g:i A');$iso=$date->format(DATE_ATOM);}catch(Exception $e){$compact='Time unavailable';$iso='';}
    ?>
      <tr><td class="audit-action"><i class="bi bi-<?=e($icon)?> audit-action-icon" aria-hidden="true"></i><div class="audit-action-copy"><span class="audit-action-title"><?=e($action)?></span><span class="audit-mobile-meta"><?=e($module)?> <span aria-hidden="true">·</span> <time datetime="<?=e($iso)?>" title="<?=e($entry['created_at'])?>"><?=e($compact)?></time></span></div></td><td class="audit-desktop-meta"><?=e($entry['module'])?></td><td class="audit-desktop-meta"><time datetime="<?=e($iso)?>"><?=e($entry['created_at'])?></time></td></tr>
    <?php endforeach; ?>
    <?php if(!$data['recent_audit']): ?><tr><td colspan="3" class="small text-muted">No administrative activity recorded.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</section>
