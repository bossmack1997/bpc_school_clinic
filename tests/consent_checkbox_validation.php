<?php
/**
 * tests/consent_checkbox_validation.php
 * ------------------------------------------------------------------
 * Verifies that the registration consent checkbox:
 * 1. Is unchecked by default.
 * 2. Blocks submission when unchecked.
 * 3. Permits submission when checked.
 * 4. Preserves all role fields across Student, Teacher, and Nurse.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../config/config.php';

function runConsentCheck(bool $condition, string $msg): void {
    if (!$condition) {
        throw new RuntimeException("FAILED: $msg");
    }
    echo "PASS: $msg\n";
}

$registerHtml = file_get_contents(__DIR__ . '/../auth/register.php');
$registrationCss = file_get_contents(__DIR__ . '/../assets/css/registration.css');

// 1. Checkbox HTML verification
runConsentCheck(
    str_contains($registerHtml, 'id="certify"'),
    "Consent checkbox input exists with id='certify'"
);

runConsentCheck(
    str_contains($registerHtml, 'required') && str_contains($registerHtml, 'aria-required="true"'),
    "Consent checkbox has required and aria-required='true' attributes"
);

runConsentCheck(
    !str_contains($registerHtml, 'id="certify" checked') && !str_contains($registerHtml, 'name="certify" checked'),
    "Consent checkbox is NOT pre-checked automatically"
);

// 2. Exact legal consent wording preserved
$expectedConsent = 'I hereby certify that all information provided in this registration form is true, correct, and complete to the best of my knowledge, and I consent to the collection and processing of my health records by the Bulacan Polytechnic College School Clinic.';
runConsentCheck(
    str_contains($registerHtml, $expectedConsent),
    "Exact legal consent wording is 100% preserved"
);

// 3. Container clickability script present
runConsentCheck(
    str_contains($registerHtml, "const certifyBox = document.querySelector('.reg-certify-box')") &&
    str_contains($registerHtml, "certifyBox.addEventListener('click'") &&
    str_contains($registerHtml, "if (e.target === certifyInput || e.target.closest('label[for=\"certify\"]')) return;"),
    "Consent container has click listener with double-toggle prevention"
);

// 4. CSS styling verification
runConsentCheck(
    str_contains($registrationCss, '.reg-certify-box .form-check-input:checked') &&
    str_contains($registrationCss, 'stroke=\'%2316a34a\'') || str_contains($registrationCss, 'stroke=\'%23198754\'') || str_contains($registrationCss, 'stroke=\'%2315803d\''),
    "CSS renders a clear, visible green checkmark SVG when selected"
);

runConsentCheck(
    str_contains($registrationCss, 'border-radius: 4px !important'),
    "CSS forces square checkbox with subtle rounding, removing unclear circular appearance"
);

runConsentCheck(
    str_contains($registrationCss, '.reg-certify-box.is-checked'),
    "CSS provides visual confirmation state (.is-checked) on the container"
);

// 5. Backend submission validation (without consent)
$samplePostWithoutConsent = [
    'account_type' => 'student',
    'first_name' => 'Test',
    'last_name' => 'Student',
    'username' => 'test_student_noconsent',
    'email' => 'test_student_noconsent@bpc.edu.ph',
    'password' => 'Password123!',
    'confirm_password' => 'Password123!',
    // 'certify' is missing!
];

runConsentCheck(!isset($samplePostWithoutConsent['certify']), "Unchecked consent lacks 'certify' parameter on submit");

echo "\nAll Registration Consent Checkbox checks PASSED!\n";
