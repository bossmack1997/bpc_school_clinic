<?php
require_once __DIR__ . '/../../config/config.php';
require_role('student');
$db = getDB();
$u = current_user();

$stmt = $db->prepare("SELECT id FROM students WHERE user_id = ?");
$stmt->execute([$u['id']]);
$studentId = $stmt->fetchColumn();

$profile = null;
if ($studentId) {
    $stmt = $db->prepare("SELECT * FROM wellbeing_profiles WHERE student_id = ?");
    $stmt->execute([$studentId]);
    $profile = $stmt->fetch();
}

$pageTitle = 'Health & Well-being';
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include __DIR__ . '/../../includes/head.php'; ?></head>
<body>
<div class="app-wrapper">
  <?php include __DIR__ . '/../../components/sidebar.php'; ?>
  <div class="main-content">
    <?php include __DIR__ . '/../../components/navbar.php'; ?>
    <div class="content-area page-enter">

      <!-- Privacy Notice -->
      <div class="alert alert-info d-flex align-items-start gap-2 border-0 rounded-4" role="alert">
        <i class="bi bi-shield-lock-fill fs-4 text-primary"></i>
        <div>
          <strong>Privacy Notice</strong>
          <p class="mb-0 small">Your health and well-being information is sensitive. It should only be accessible to authorized clinic personnel when needed to provide appropriate care or support. You decide what to share — nothing here is required.</p>
        </div>
      </div>

      <form id="wellbeingForm">
        <!-- MEDICAL INFORMATION -->
        <div class="card p-3 mb-3">
          <h6 class="section-title mb-3"><i class="bi bi-heart-pulse-fill text-success me-1"></i> Medical Information <span class="badge bg-light text-dark ms-1" style="font-size:.65rem;">Optional</span></h6>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Known Medical Conditions</label>
              <textarea class="form-control" name="known_medical_conditions" rows="2" placeholder="e.g. Asthma, Diabetes"><?= e($profile['known_medical_conditions'] ?? '') ?></textarea>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Allergies</label>
              <textarea class="form-control" name="allergies" rows="2" placeholder="e.g. Penicillin, Peanuts"><?= e($profile['allergies'] ?? '') ?></textarea>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Previous Serious Illnesses</label>
              <textarea class="form-control" name="previous_illnesses" rows="2" placeholder="Optional"><?= e($profile['previous_illnesses'] ?? '') ?></textarea>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Current Medications</label>
              <textarea class="form-control" name="current_medications" rows="2" placeholder="Optional"><?= e($profile['current_medications'] ?? '') ?></textarea>
            </div>
            <div class="col-12">
              <label class="form-label small fw-semibold">Other Important Health Information</label>
              <textarea class="form-control" name="other_medical_info" rows="2" placeholder="Optional"><?= e($profile['other_medical_info'] ?? '') ?></textarea>
            </div>
          </div>
        </div>

        <!-- MENTAL & EMOTIONAL WELL-BEING -->
        <div class="card p-3 mb-3">
          <h6 class="section-title mb-3"><i class="bi bi-emoji-smile-fill text-success me-1"></i> Mental &amp; Emotional Well-being <span class="badge bg-light text-dark ms-1" style="font-size:.65rem;">Optional</span></h6>
          <div class="alert alert-light border small mb-3">
            You are <strong>not required to provide a diagnosis</strong>. Share only what you are comfortable with so clinic staff can support you appropriately.
          </div>
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label small fw-semibold">Concerns you want clinic staff to know about</label>
              <textarea class="form-control" name="concerns" rows="2" placeholder="Optional — share only if you wish"><?= e($profile['concerns'] ?? '') ?></textarea>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Previous professional support</label>
              <textarea class="form-control" name="previous_support" rows="2" placeholder="Optional"><?= e($profile['previous_support'] ?? '') ?></textarea>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Previous diagnosis / condition (if voluntarily disclosed)</label>
              <textarea class="form-control" name="diagnosis_disclosed" rows="2" placeholder="Optional — only if you choose to share"><?= e($profile['diagnosis_disclosed'] ?? '') ?></textarea>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Current support / treatment</label>
              <textarea class="form-control" name="current_support" rows="2" placeholder="Optional"><?= e($profile['current_support'] ?? '') ?></textarea>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Other relevant information</label>
              <textarea class="form-control" name="other_wellbeing" rows="2" placeholder="Optional"><?= e($profile['other_wellbeing'] ?? '') ?></textarea>
            </div>
          </div>
        </div>

        <!-- SUPPORT PREFERENCES -->
        <div class="card p-3 mb-3">
          <h6 class="section-title mb-3"><i class="bi bi-people-fill text-success me-1"></i> Support Preferences</h6>
          <p class="small text-muted">How would you prefer clinic staff to approach or support you when you are having difficulty?</p>
          <?php
          $prefs = [];
          if (!empty($profile['support_preferences'])) {
              $dec = json_decode($profile['support_preferences'], true);
              if (is_array($dec)) $prefs = $dec;
          }
          $options = [
              'Speak to me privately',
              'Give me some time before asking questions',
              'Explain things slowly',
              'Allow me to have a trusted person present',
          ];
          foreach ($options as $opt):
              $checked = in_array($opt, $prefs, true) ? 'checked' : '';
          ?>
          <div class="form-check">
            <input class="form-check-input pref-check" type="checkbox" name="support_preferences[]" value="<?= e($opt) ?>" id="pref_<?= md5($opt) ?>" <?= $checked ?>>
            <label class="form-check-label" for="pref_<?= md5($opt) ?>"><?= e($opt) ?></label>
          </div>
          <?php endforeach; ?>
          <div class="form-check">
            <input class="form-check-input pref-check" type="checkbox" name="support_preferences[]" value="__custom__" id="pref_custom_toggle"
              <?= (isset($prefs[0]) && !in_array($prefs[0], $options, true) && count($prefs) === 1) ? 'checked' : '' ?>>
            <label class="form-check-label" for="pref_custom_toggle">Other (please specify)</label>
          </div>
          <input type="text" class="form-control mt-2" id="pref_custom" name="support_preferences[]" placeholder="Your preference" disabled>
        </div>

        <div class="card p-3 mb-3">
          <label class="form-label small fw-semibold">Anything else you'd like to share</label>
          <textarea class="form-control" name="student_notes" rows="2" placeholder="Optional"><?= e($profile['student_notes'] ?? '') ?></textarea>
        </div>

        <!-- Consent -->
        <div class="card p-3 mb-3 border-primary">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="consentBox" name="consent_status" value="granted"
              <?= (!empty($profile) && $profile['consent_status'] === 'granted') ? 'checked' : '' ?>>
            <label class="form-check-label" for="consentBox">
              <strong>I acknowledge the privacy notice above</strong> and consent to storing my health and well-being information so authorized clinic staff can use it to support me.
            </label>
          </div>
          <?php if (!empty($profile) && $profile['consent_at']): ?>
            <div class="small text-muted mt-2">Last acknowledged: <?= format_datetime($profile['consent_at']) ?></div>
          <?php endif; ?>
        </div>

        <button class="btn btn-success w-100 mb-4" type="submit"><i class="bi bi-shield-check me-1"></i> Save My Information</button>
      </form>

    </div>
  </div>
</div>
<?php include __DIR__ . '/../../components/footer.php'; ?>
<script>
$(function () {
  // Custom preference toggle
  function syncCustom() {
    const customOn = $('#pref_custom_toggle').is(':checked');
    $('#pref_custom').prop('disabled', !customOn);
    if (!customOn) $('#pref_custom').val('');
  }
  $('#pref_custom_toggle').on('change', syncCustom);
  syncCustom();

  $('#wellbeingForm').on('submit', function (e) {
    e.preventDefault();
    if (!$('#consentBox').is(':checked')) {
      toastError('Please acknowledge the privacy notice before saving.');
      return;
    }
    // Move custom value into the array only if checked
    const data = $(this).serialize() + '&action=save&csrf_token=' + $('meta[name="csrf-token"]').attr('content');
    $.post(APP_URL + '/api/wellbeing.php', data, function (res) {
      if (res.success) toastSuccess(res.message);
      else toastError(res.message);
    }, 'json').fail(function () { toastError('Something went wrong.'); });
  });
});
</script>
</body>
</html>
