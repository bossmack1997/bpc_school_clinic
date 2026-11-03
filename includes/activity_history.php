<?php
/** Safe presentation only; raw audit actions and details remain in storage. */
function activity_history_expression(): string
{
    $groups = [
        'Logged in' => ['Login','Logged in','Logged in after email verification'],
        'Logged out' => ['Logged out'],
        'Changed password' => ['Changed password','Reset password','Password reset'],
        'Verified email' => ['Completed email OTP verification'],
        'Updated profile' => ['Updated profile info','Updated personal information','Updated nurse profile','Updated profile picture'],
        'Updated personal settings' => ['Updated personal settings'],
        'Created an account' => ['Created administrator account','Created doctor account','Created nurse account','Provisioned account batch','Invited Nurse account'],
        'Activated an account' => ['Provisioned account activated'],
        'Updated an account' => ['Updated admin account status','Disabled admin account'],
        'Resent account invitation' => ['Resent account activation'],
        'Imported student records' => ['Imported students','Imported student records'],
        'Imported teacher records' => ['Imported teachers','Imported teacher records'],
        'Created an appointment' => ['Created appointment'],
        'Updated an appointment' => ['Updated appointment'],
        'Recorded a consultation' => ['Physician consultation saved','Walk-in consultation','Walk-in consultation (Staff)','Completed nursing care'],
        'Updated a consultation' => ['Updated consultation'],
        'Updated patient records' => ['Updated patient health profile','Updated medical health profile','Uploaded medical record','Added vaccination record'],
        'Submitted professional credentials' => ['Nurse credentials submitted'],
        'Updated medicine inventory' => ['Added medicine','Updated medicine','Deleted medicine','Imported medicines'],
        'Dispensed medicine' => ['Dispensed medicine'],
        'Requested password reset' => ['Requested password reset'],
        'Downloaded system backup' => ['Downloaded system backup'],
        'Verified system backup' => ['Verified system backup'],
        'Reviewed a security incident' => ['Security incident transition'],
        'Released a login lockout' => ['Cleared login lockout','Cleared active login lockouts'],
        'Viewed patient records' => ['Accessed central patient workspace','Viewed student medical information'],
        'Prepared a clinical draft' => ['MICA clinical draft prepared'],
    ];
    $quote = static fn(string $value): string => "'" . str_replace("'", "''", $value) . "'";
    $sql = 'CASE';
    foreach ($groups as $label => $actions) {
        $sql .= ' WHEN al.action IN (' . implode(',', array_map($quote, $actions)) . ') THEN ' . $quote($label);
    }
    // Only the recorded decision marker is interpreted; remarks never leave the server.
    foreach (['authorized'=>'Approved Nurse credentials','rejected'=>'Rejected Nurse credentials','needs_information'=>'Requested more Nurse credential information','revoked'=>'Revoked Nurse authorization'] as $state=>$label) {
        $sql .= " WHEN al.action='Nurse clinical review' AND al.module='nurse_clinical_authorization' AND al.details REGEXP '^Account #[0-9]+; decision $state;' THEN " . $quote($label);
    }
    return $sql . " WHEN al.action='Nurse clinical review' THEN 'Reviewed Nurse credentials' ELSE 'Other recorded activity' END";
}
