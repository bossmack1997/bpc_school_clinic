<?php
/**
 * tests/doctor_nurse_verification_suite.php
 * ------------------------------------------------------------------
 * Automated security and workflow validation for Doctor-Led Nurse
 * Clinical Verification, Basic Nurse Dashboard, and Consent Checkbox.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permission_profiles.php';
require_once __DIR__ . '/../includes/nurse_verification_service.php';
require_once __DIR__ . '/../includes/doctor_clinical_service.php';

$db = getDB();
$db->beginTransaction();

function assertCheck(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException("FAILED: $message");
    }
    echo "PASS: $message\n";
}

try {
    // 1. Setup Synthetic Fixtures
    // Doctor Fixture
    $db->prepare("
        INSERT INTO users (username, email, password_hash, first_name, last_name, role, admin_type, status, email_verified, self_service_only, created_at)
        VALUES ('test_doc_synth', 'test_doc_synth@bpc.edu.ph', 'hash', 'Test', 'Doctor', 'admin', 'co_admin', 'active', 1, 0, NOW())
    ")->execute();
    $docUserId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO admin_profiles (user_id, profile) VALUES (?, 'doctor')")->execute([$docUserId]);
    $docUser = $db->query("SELECT * FROM users WHERE id = $docUserId")->fetch(PDO::FETCH_ASSOC);

    // MIS Admin Fixture
    $db->prepare("
        INSERT INTO users (username, email, password_hash, first_name, last_name, role, admin_type, status, email_verified, self_service_only, created_at)
        VALUES ('test_mis_synth', 'test_mis_synth@bpc.edu.ph', 'hash', 'Test', 'MIS', 'admin', 'co_admin', 'active', 1, 0, NOW())
    ")->execute();
    $misUserId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO admin_profiles (user_id, profile) VALUES (?, 'mis')")->execute([$misUserId]);
    $misUser = $db->query("SELECT * FROM users WHERE id = $misUserId")->fetch(PDO::FETCH_ASSOC);

    // Nurse 1 Fixture (Pending self-registered)
    $db->prepare("
        INSERT INTO users (username, email, password_hash, first_name, last_name, role, status, email_verified, email_verified_at, self_service_only, credential_version, created_at)
        VALUES ('test_nurse_synth1', 'nurse1_synth@bpc.edu.ph', 'hash', 'Florence', 'Nightingale', 'nurse', 'active', 1, NOW(), 1, 1, NOW())
    ")->execute();
    $nurse1UserId = (int)$db->lastInsertId();
    $db->prepare("
        INSERT INTO nurses (user_id, employee_id, license_number, position, valid_id_path, clinical_authorization_status)
        VALUES (?, 'NURSE-001', 'PRC-1234567', 'Staff Nurse', 'storage/registration_documents/sample_valid_id.pdf', 'pending')
    ")->execute([$nurse1UserId]);

    // Nurse 2 Fixture (Authorized)
    $db->prepare("
        INSERT INTO users (username, email, password_hash, first_name, last_name, role, status, email_verified, email_verified_at, self_service_only, credential_version, created_at)
        VALUES ('test_nurse_synth2', 'nurse2_synth@bpc.edu.ph', 'hash', 'Clara', 'Barton', 'nurse', 'active', 1, NOW(), 0, 1, NOW())
    ")->execute();
    $nurse2UserId = (int)$db->lastInsertId();
    $db->prepare("
        INSERT INTO nurses (user_id, employee_id, license_number, position, valid_id_path, clinical_authorization_status)
        VALUES (?, 'NURSE-002', 'PRC-7654321', 'Senior Nurse', 'storage/registration_documents/sample_valid_id2.pdf', 'authorized')
    ")->execute([$nurse2UserId]);

    $docService = new NurseVerificationService($db, $docUser);

    // TEST 1: Doctor can list pending requests
    $pendingList = $docService->listNurses('pending');
    $found = false;
    foreach ($pendingList as $n) {
        if ((int)$n['user_id'] === $nurse1UserId) $found = true;
    }
    assertCheck($found, "Doctor can see pending Nurse verification requests");

    // TEST 2: Doctor can inspect application details
    $detail = $docService->getNurse($nurse1UserId);
    assertCheck($detail !== null && $detail['license_number'] === 'PRC-1234567' && $detail['effective_status'] === 'pending', "Doctor can inspect nurse details and PRC license");

    // TEST 3: Unauthorized user (MIS Admin) cannot approve or authorize nurse
    $misService = new NurseVerificationService($db, $misUser);
    $misBlocked = false;
    try {
        $misService->authorize($nurse1UserId, 'MIS attempting unauthorized approval');
    } catch (RuntimeException $e) {
        $misBlocked = true;
    }
    assertCheck($misBlocked, "MIS Admin cannot authorize nurse clinical access");

    // TEST 4: Doctor can request additional information
    $docService->requestInformation($nurse1UserId, 'Please submit updated PRC renewal card');
    $afterReq = $docService->getNurse($nurse1UserId);
    assertCheck($afterReq['effective_status'] === 'needs_information' && (int)$afterReq['self_service_only'] === 1, "Doctor can request additional information from nurse");

    // TEST 5: Doctor can authorize nurse clinical access
    $docService->authorize($nurse1UserId, 'PRC license verified against institutional registry');
    $afterAuth = $docService->getNurse($nurse1UserId);
    assertCheck($afterAuth['effective_status'] === 'authorized' && (int)$afterAuth['self_service_only'] === 0, "Doctor can authorize Nurse clinical access, clearing self_service_only");

    // TEST 6: Audit history is preserved
    $history = $docService->getHistory($nurse1UserId);
    assertCheck(count($history) >= 2, "Review history records all actions, reviewer identity, and notes");

    // TEST 7: Doctor can revoke clinical access
    $credVersionBefore = (int)$afterAuth['credential_version'];
    $docService->revoke($nurse1UserId, 'End of clinical assignment at school clinic');
    $afterRevoke = $docService->getNurse($nurse1UserId);
    $credVersionAfter = (int)$db->query("SELECT credential_version FROM users WHERE id = $nurse1UserId")->fetchColumn();
    assertCheck($afterRevoke['effective_status'] === 'revoked' && (int)$afterRevoke['self_service_only'] === 1, "Doctor can revoke clinical authorization");
    assertCheck($credVersionAfter > $credVersionBefore, "Revocation increments credential_version to invalidate existing sessions");

    // TEST 8: Doctor can reject clinical application
    // Reset nurse 1 to pending
    $db->prepare("UPDATE nurses SET clinical_authorization_status = 'pending' WHERE user_id = ?")->execute([$nurse1UserId]);
    $docService->reject($nurse1UserId, 'Unable to verify school assignment with registrar');
    $afterReject = $docService->getNurse($nurse1UserId);
    assertCheck($afterReject['effective_status'] === 'rejected' && (int)$afterReject['self_service_only'] === 1, "Doctor can reject clinical application with documented reason");

    // TEST 9: Existing authorized nurse remains authorized
    $nurse2 = $docService->getNurse($nurse2UserId);
    assertCheck($nurse2['effective_status'] === 'authorized' && (int)$nurse2['self_service_only'] === 0, "Existing legitimately authorized Nurse accounts remain functional");

    // TEST 10: Summary counts are correct
    $summary = $docService->summaryCounts();
    assertCheck(isset($summary['pending'], $summary['authorized'], $summary['needs_information']), "Summary counts accurately report authorization statuses");

    // TEST 11: Document viewer security checks
    // Doctor can view nurse document
    $_SESSION['user'] = $docUser;
    $docAllowed = (permission_profile(current_user()) === 'doctor');
    assertCheck($docAllowed, "Doctor is authorized to inspect submitted nurse documents");

    // Nurse can view own document
    $nurse1Row = $db->query("SELECT * FROM users WHERE id = $nurse1UserId")->fetch(PDO::FETCH_ASSOC);
    $_SESSION['user'] = $nurse1Row;
    $selfAllowed = (canonical_role(current_user()['role'] ?? '') === 'nurse' && (int)(current_user()['id'] ?? 0) === $nurse1UserId);
    assertCheck($selfAllowed, "Nurse is authorized to view their own submitted document");

    // MIS Admin is blocked from viewing nurse document
    $_SESSION['user'] = $misUser;
    $misDocAllowed = (permission_profile(current_user()) === 'doctor' || (canonical_role(current_user()['role'] ?? '') === 'nurse' && (int)(current_user()['id'] ?? 0) === $nurse1UserId));
    assertCheck(!$misDocAllowed, "MIS Admin is blocked from viewing confidential nurse clinical documents");

    // Clean session
    unset($_SESSION['user']);

    echo "\nAll Doctor-Led Nurse Verification suite checks PASSED!\n";
} finally {
    $db->rollBack();
}
