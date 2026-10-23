<?php
require_once __DIR__.'/../../config/config.php';require_role('nurse');$pageTitle='Clinic Announcements';
?>
<!doctype html><html lang="en"><head><?php include __DIR__.'/../../includes/head.php'; ?></head><body><div class="app-wrapper">
<?php include __DIR__.'/../../components/sidebar.php'; ?><div class="main-content"><?php include __DIR__.'/../../components/navbar.php'; ?>
<main class="content-area patient-profile-page" id="mainContent"><h1 class="h4">Clinic Announcements</h1><p class="text-muted">Manage routine clinic updates. Publication dates use Asia/Manila time. Clinically sensitive advisories require the institution's physician review procedure before publication.</p>
<div id="announcementManagerStatus" class="alert alert-info" role="status">Loading announcements...</div>
<div class="row g-3"><section class="col-12 col-lg-7"><h2 class="h5">Announcement records</h2><div id="announcementsList"></div></section>
<section class="col-12 col-lg-5"><form id="announcementForm" class="card p-3"><h2 class="h5" id="announcementFormTitle">Create announcement</h2><fieldset id="announcementFields"><input type="hidden" name="id" value=""><label class="form-label d-block">Title<input class="form-control" name="title" required maxlength="200"></label>
<label class="form-label d-block">Description<textarea class="form-control" name="content" rows="5" required maxlength="10000"></textarea></label>
<label class="form-label d-block">Category<input class="form-control" name="category" maxlength="50" value="general" required></label>
<label class="form-label d-block">Audience<select class="form-select" name="audience"><option value="all">Everyone</option><option value="students">Students</option><option value="teachers">Teachers</option><option value="nurses">Nurses</option><option value="admin">Administrators</option></select></label>
<label class="form-label d-block">Publication status<select class="form-select" name="publication_status"><option value="draft">Draft</option><option value="published">Published</option><option value="archived">Archived</option></select></label>
<label class="form-label d-block">Start (optional)<input class="form-control" type="datetime-local" name="starts_at"></label><label class="form-label d-block">Expiry (optional)<input class="form-control" type="datetime-local" name="expires_at"></label>
<label class="form-label d-block">Optional image (JPEG, PNG, WebP; up to 5 MB)<input class="form-control" type="file" name="image" accept="image/jpeg,image/png,image/webp"></label>
<label class="form-check my-3"><input class="form-check-input" type="checkbox" name="is_pinned" value="1"> <span class="form-check-label">Pin to top</span></label>
<div class="d-flex flex-wrap gap-2"><button class="btn btn-success" type="submit">Save announcement</button><button class="btn btn-outline-secondary" type="reset">New announcement</button></div></fieldset></form></section></div>
</main></div></div><?php include __DIR__.'/../../components/footer.php'; ?><script src="<?=e(asset_url('js/announcement-manager.js',true))?>"></script></body></html>
