<?php
require_once __DIR__ . '/../config/config.php';

if (!defined('APP_ENV') || APP_ENV !== 'local') {
    http_response_code(404);
    exit;
}

require_login();
$pageTitle = 'UI Style Guide';
?>
<!doctype html>
<html lang="en">
<head>
  <?php include __DIR__ . '/../includes/head.php'; ?>
</head>
<body>
<div class="app-shell">
  <?php include __DIR__ . '/../components/sidebar.php'; ?>
  <div class="main-content">
    <?php include __DIR__ . '/../components/navbar.php'; ?>
    <main class="content-area" id="mainContent">
      <div class="container-fluid bpc-style-guide">
        <header class="app-page-head">
          <div>
            <p class="sg-eyebrow">BPC School Clinic</p>
            <h2>UI Style Guide</h2>
            <p class="text-muted">Shared theme tokens and components. Use the moon button to preview dark mode.</p>
          </div>
        </header>

        <section class="card sg-section" aria-labelledby="sg-colors-title">
          <div class="card-body">
            <h2 id="sg-colors-title">Brand and status colors</h2>
            <div class="sg-swatches">
              <div class="sg-swatch sg-swatch--brand"><span>BPC primary</span><code>--primary</code></div>
              <div class="sg-swatch sg-swatch--success"><span>Success</span><code>--success</code></div>
              <div class="sg-swatch sg-swatch--danger"><span>Emergency / danger</span><code>--danger</code></div>
              <div class="sg-swatch sg-swatch--warning"><span>Warning</span><code>--warning</code></div>
              <div class="sg-swatch sg-swatch--info"><span>Information</span><code>--info</code></div>
              <div class="sg-swatch sg-swatch--neutral"><span>Neutral surface</span><code>--surface</code></div>
            </div>
          </div>
        </section>

        <section class="card sg-section" aria-labelledby="sg-type-title">
          <div class="card-body sg-type-samples">
            <h2 id="sg-type-title">Typography</h2>
            <h3>Page title</h3>
            <h2>Section title</h2>
            <h3>Card title</h3>
            <p>Body text uses the shared Inter font stack, semantic text colors, and readable line spacing.</p>
            <small>Small text and helper copy</small>
            <label class="d-block mt-2">Form label</label>
          </div>
        </section>

        <section class="card sg-section" aria-labelledby="sg-actions-title">
          <div class="card-body">
            <h2 id="sg-actions-title">Buttons and status</h2>
            <div class="sg-component-row">
              <button class="btn btn-primary" type="button">Primary action</button>
              <button class="btn btn-secondary" type="button">Secondary</button>
              <button class="btn btn-outline-primary" type="button">Outline</button>
              <button class="btn btn-danger" type="button">Emergency action</button>
              <button class="btn btn-primary" type="button" disabled>Disabled</button>
            </div>
            <div class="sg-component-row mt-3">
              <span class="badge text-bg-success">Completed</span>
              <span class="badge text-bg-warning">Waiting</span>
              <span class="badge text-bg-info">In consultation</span>
              <span class="badge text-bg-danger">Emergency</span>
              <span class="badge text-bg-secondary">Cancelled</span>
            </div>
          </div>
        </section>

        <section class="card sg-section" aria-labelledby="sg-form-title">
          <div class="card-body">
            <h2 id="sg-form-title">Form controls</h2>
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label" for="sg-search">Search</label>
                <input class="form-control" id="sg-search" type="search" placeholder="Search students">
                <div class="form-text">Helper text follows the field.</div>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="sg-select">Status</label>
                <select class="form-select" id="sg-select"><option>Choose a status</option><option>Waiting</option></select>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="sg-date">Date</label>
                <input class="form-control" id="sg-date" type="date">
              </div>
              <div class="col-md-6">
                <label class="form-label" for="sg-invalid">Validation example</label>
                <input class="form-control is-invalid" id="sg-invalid" value="Invalid value" aria-describedby="sg-error">
                <div class="invalid-feedback" id="sg-error">Show a clear, actionable error message.</div>
              </div>
            </div>
          </div>
        </section>

        <section class="card sg-section" aria-labelledby="sg-states-title">
          <div class="card-body">
            <h2 id="sg-states-title">Empty state and loading</h2>
            <div class="dbg-empty" role="status">
              <div class="sg-empty-icon" aria-hidden="true"><i class="bi bi-inbox"></i></div>
              <strong>No records to show</strong>
              <p>When a list is empty, explain what the user can do next.</p>
              <button class="btn btn-primary btn-sm" type="button">Create a record</button>
            </div>
            <div class="sg-skeletons" aria-label="Loading placeholder">
              <span class="skeleton sg-skeleton-line"></span>
              <span class="skeleton sg-skeleton-line sg-skeleton-line--short"></span>
            </div>
          </div>
        </section>

        <section class="card sg-section" aria-labelledby="sg-table-title">
          <div class="card-body">
            <h2 id="sg-table-title">Table</h2>
            <div class="table-responsive">
              <table class="table table-hover mb-0">
                <thead><tr><th scope="col">Patient</th><th scope="col">Queue</th><th scope="col">Status</th></tr></thead>
                <tbody><tr><td>Sample patient</td><td>Q-014</td><td><span class="badge text-bg-warning">Waiting</span></td></tr></tbody>
              </table>
            </div>
          </div>
        </section>
      </div>
    </main>
  </div>
</div>
<?php include __DIR__ . '/../components/footer.php'; ?>
</body>
</html>
