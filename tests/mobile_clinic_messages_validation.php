<?php
/**
 * tests/mobile_clinic_messages_validation.php
 * ------------------------------------------------------------------
 * Verifies that the mobile Clinic Messages CSS:
 * 1. Has compact topbar header on <= 991px.
 * 2. Hides excessive desktop elements on mobile header (clock, MICA pill, etc.).
 * 3. Keeps hamburger menu and page title single-row and visible.
 * 4. Constrains conversation toolbar to compact non-wrapping layout on <= 767px.
 * 5. Uses compact SOS button and compact avatar.
 * 6. Sets 16px font-size on message input to prevent iOS zoom.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../config/config.php';

function runMsgCheck(bool $condition, string $msg): void {
    if (!$condition) {
        throw new RuntimeException("FAILED: $msg");
    }
    echo "PASS: $msg\n";
}

$css = file_get_contents(__DIR__ . '/../assets/css/messenger.css');

runMsgCheck(
    str_contains($css, 'body.clinic-messages-page .topbar') && str_contains($css, 'height: 50px !important'),
    "Mobile topbar height is streamlined to 50px"
);

runMsgCheck(
    str_contains($css, 'body.clinic-messages-page .topbar-right :is(.topbar-clock, .mica-status-indicator, .dark-toggle, #emergencyShieldBtn, button[data-action="open-sos"])') &&
    str_contains($css, 'display: none !important'),
    "Excessive secondary desktop items are hidden on mobile messaging header"
);

runMsgCheck(
    str_contains($css, 'body.clinic-messages-page #sidebarToggle') &&
    str_contains($css, 'width: 36px !important') &&
    str_contains($css, 'height: 36px !important'),
    "Mobile hamburger toggle is compact and centered"
);

runMsgCheck(
    str_contains($css, 'body.clinic-messages-page .messenger-header') &&
    str_contains($css, 'flex-wrap: nowrap !important'),
    "Conversation toolbar is forced to single-row without wrapping"
);

runMsgCheck(
    str_contains($css, 'body.clinic-messages-page .messenger-header .btn-outline-danger') &&
    str_contains($css, 'height: 28px !important'),
    "SOS shortcut in conversation toolbar is compact (28px height)"
);

runMsgCheck(
    str_contains($css, 'body.clinic-messages-page .messenger-compose-row #messageInput') &&
    str_contains($css, 'font-size: 16px !important'),
    "Message composer input has 16px font-size preventing mobile browser zoom"
);

echo "\nAll Mobile Clinic Messages UI checks PASSED!\n";

