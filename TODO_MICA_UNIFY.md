# MICA Unification Plan

## Phase 1: Create Reusable Widget Include

- [ ] `includes/mica_widget.php` - SOS Modal + user data + greeting logic (reusable, no duplicate HTML)

## Phase 2: Replace Old AI Assistant Pages

- [ ] `pages/student/ai_assistant.php` - Replace with MICA landing page (redirects to floating widget)
- [ ] `pages/nurse/ai_assistant.php` - Replace with MICA landing page

## Phase 3: Enhance MICA JS

- [ ] `assets/js/mica.js` - Add smart navigation, personalized greeting, quick action buttons, conversation memory, enhanced SOS

## Phase 4: Enhance MICA CSS

- [ ] `assets/css/mica.css` - Enhanced glassmorphism, quick action grid, improved animations

## Phase 5: Update Sidebars

- [ ] `includes/sidebar_student.php` - Rename "AI Health Assistant" → "MICA"
- [ ] `includes/sidebar_nurse.php` - Rename "AI Assistant" → "MICA"
- [ ] `includes/sidebar_admin.php` - Add MICA link

## Phase 6: Update Footer

- [ ] `includes/footer_scripts.php` - Include mica_widget.php
