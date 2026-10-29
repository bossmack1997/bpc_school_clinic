# Simplify Doctor and MIS account workflows

Doctors can invite one Nurse at a time from Manage Nurses. Nurses choose a private
password through an expiring single-use link and remain restricted until the
Doctor approves their credentials. MIS account creation supports Students and
Teachers through individual entry and CSV/XLSX previews; MIS retains invitation
resend for technical recovery.

Doctor Dashboard shows patient, waiting, review and follow-up counts from database
queries, with local failure messages when a source is unavailable. Navigation and
account recovery wording are simplified while retaining clinical tools and audit
history. Activation guidance now refers recipients to their inviter.

Validation: 422 broad disposable HTTP integration checks and 144 focused
verification/provisioning checks passed. Modified PHP syntax, Doctor browser test
JavaScript syntax and git diff whitespace checks passed. The focused suite adds
Doctor invitation route authorization, CSRF, forged-role, confirmation, transport
failure and replay regressions.

Release checks remaining: actual browser layouts/runtime, staging application of
existing account provisioning, physician queue and credential migrations, and real
SMTP invitation/reset delivery. No production migration or deployment performed.
See DOCTOR_MIS_SIMPLIFICATION_20261029.md for the recovered audit and status matrix.
