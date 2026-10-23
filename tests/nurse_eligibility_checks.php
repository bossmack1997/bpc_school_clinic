<?php
// Database-free checks for NurseVerificationService::evaluateEligibility().
// Run from the project root: php tests/nurse_eligibility_checks.php
require_once __DIR__ . '/../includes/nurse_verification_service.php';

$failures = 0;
function check(string $name, bool $ok): void { global $failures; echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL; if (!$ok) $failures++; }
function blockers(array $r, bool $inst = true): array { return NurseVerificationService::evaluateEligibility($r, $inst)['blockers']; }

$complete = ['account_status' => 'active', 'email_verified' => 1, 'license_number' => '0123456', 'position' => 'School Nurse I', 'valid_id_path' => 'storage/registration_documents/x.pdf'];
$noFile = $complete; // BASE_PATH file existence is checked only when the file is absent.
$r = NurseVerificationService::evaluateEligibility($complete, true);
check('complete evidence is only blocked by a missing stored file (no file in test env)', !$r['eligible'] ? count($r['blockers']) === 1 && str_contains($r['blockers'][0], 'missing from storage') : true);

$nodoc = array_diff_key($complete, ['valid_id_path' => 1]);
check('user 3 shape: inactive + unverified + no position/doc blocked', count(blockers(['account_status' => 'inactive', 'email_verified' => 0, 'license_number' => 'A1234'] , true)) >= 4);
check('user 6 shape: pending + unverified + no PRC', count(blockers(['account_status' => 'pending', 'email_verified' => 0], true)) === 5);
check('user 171 shape: unverified email blocks', in_array('Email address has not been verified by the Nurse', blockers(array_replace($complete, ['email_verified' => 0])), true));
check('users 177/182 shape: missing license blocks', in_array('PRC registration number is missing', blockers(array_replace($complete, ['license_number' => null])), true));
check('whitespace-only license is missing', in_array('PRC registration number is missing', blockers(array_replace($complete, ['license_number' => '   '])), true));
check('invalid license format blocks', in_array('PRC registration number format is invalid', blockers(array_replace($complete, ['license_number' => '!!'])), true));
check('missing position blocks', in_array('School appointment position is missing', blockers(array_replace($complete, ['position' => ''])), true));
check('traversal document path blocks', in_array('Supporting credential path is invalid', blockers(array_replace($complete, ['valid_id_path' => 'storage/registration_documents/../../config/config.php'])), true));
check('no document blocks', in_array('Supporting credential document is missing', blockers($nodoc), true));
check('institutional attestation required', in_array('Institutional appointment verification not confirmed by the Doctor', blockers($complete, false), true));
check('employee_id is not substituted for PRC', in_array('PRC registration number is missing', blockers(array_replace($complete, ['license_number' => '', 'employee_id' => 'EMP-1'])), true));
check('institutional check is never auto-passed', NurseVerificationService::evaluateEligibility($complete, false)['checks']['institutional']['ok'] === null);

echo $failures ? "$failures FAILED" . PHP_EOL : 'All checks passed' . PHP_EOL;
exit($failures ? 1 : 0);

