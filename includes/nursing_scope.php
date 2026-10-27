<?php
/** Reject physician decisions even when submitted outside the visible nursing UI. */
function enforce_nursing_documentation(array $input): void
{
    foreach (['diagnosis','prescription','digital_signature','treatment'] as $field) {
        if (isset($input[$field]) && (!is_string($input[$field]) || trim($input[$field])!=='')) {
            admin_access_denied('Physician diagnosis, prescribing and treatment decisions require the Doctor workflow. Document nursing observations and authorized nursing interventions instead.');
        }
    }
    if (isset($input['nursing_interventions']) && (!is_string($input['nursing_interventions']) || mb_strlen($input['nursing_interventions'])>5000)) {
        http_response_code(400);json_response(false,[],'Nursing interventions must be text of up to 5000 characters.');
    }
}
