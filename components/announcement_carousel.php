<?php
require_once __DIR__.'/../includes/announcement_service.php';
$announcementError=false;
try{$clinicAnnouncements=(new AnnouncementService(getDB(),current_user()))->listing();}catch(Throwable $e){$clinicAnnouncements=[];$announcementError=true;error_log('Dashboard announcements unavailable');}
?>
<section class="clinic-announcements card mb-4" data-announcement-carousel aria-roledescription="carousel" aria-label="Clinic Announcements and Updates">
  <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap p-3 border-bottom"><h2 class="h5 mb-0">Clinic Announcements &amp; Updates</h2><button class="btn btn-sm btn-outline-success" type="button" data-carousel-play aria-label="Pause automatic announcements" hidden>Pause</button></div>
  <div data-announcement-slides aria-live="off">
  <?php foreach($clinicAnnouncements as $index=>$announcement): ?>
    <article class="clinic-announcement-slide p-3" data-announcement-slide <?= $index?'hidden':'' ?> aria-roledescription="slide" aria-label="<?=($index+1).' of '.count($clinicAnnouncements)?>">
      <?php if($announcement['image_url']): ?><img class="clinic-announcement-image" src="<?=e($announcement['image_url'])?>" alt="" loading="lazy"><?php endif; ?>
      <div class="clinic-announcement-copy"><span class="badge bg-success-subtle text-success mb-2"><?=e($announcement['category'])?></span><h3 class="h5"><?=e($announcement['title'])?></h3><p class="mb-2 clinic-announcement-description"><?=e($announcement['content'])?></p><small class="text-muted">Posted by <?=e(trim(($announcement['first_name']??'').' '.($announcement['last_name']??''))?:'Clinic')?> · <?=e($announcement['created_at'])?></small></div>
    </article>
  <?php endforeach; ?>
  </div>
  <p class="text-muted p-3 mb-0" data-announcement-empty role="status" <?=$clinicAnnouncements?'hidden':''?>><?=$announcementError?'Clinic announcements are temporarily unavailable. Please retry later.':'No clinic announcements at this time.'?></p>
  <div class="d-flex align-items-center justify-content-between gap-2 p-3 border-top" data-carousel-controls hidden>
    <button type="button" class="btn btn-sm btn-outline-success" data-carousel-prev aria-label="Previous announcement">Previous</button><div class="announcement-indicators" data-carousel-indicators aria-label="Choose announcement"></div><button type="button" class="btn btn-sm btn-outline-success" data-carousel-next aria-label="Next announcement">Next</button>
  </div>
</section>
<script src="<?=e(asset_url('js/announcement-carousel.js',true))?>" defer></script>
