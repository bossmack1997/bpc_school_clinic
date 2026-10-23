# Global MICA and medical oversight audit — 24 October 2026

Pre-edit audit: branch `main`, clean working tree. No AGENTS.md was found in
the project source inventory (scratch browser profiles excluded).

## Reuse

The database retains admin, nurse, student and teacher roles. admin_profiles
distinguishes MIS and Doctor without changing role identifiers. current_user
refreshes account state from the database; require_login restricts pending Nurses.
The Doctor command center already retrieves consultations, queue, follow-ups,
physician referrals, emergencies, inventory and clinical activity with independent
source-failure handling. Its existing verification card uses real Nurse counts.

NurseVerificationService and the existing Doctor verification page provide private
credential review, CSRF, password reauthentication, row locks, application versions,
review history and notifications. Public Nurse email activation intentionally
provides only the restricted account portal; clinical authorization remains a
separate human Doctor decision. Rejected applications are retained. Reuse this
workflow and its existing guarded migration; do not create another approval table.

AIService already centralizes OpenRouter, context, grounded workflows, session
history, recommendations and voice integration. Existing clinical tools enforce
clinical access, and the shared floating component prevents duplicate rendering.
Existing synthetic HTTP/browser suites exercise Doctor, Nurse and other roles.

## Repair findings

- ChatHistory uses one session key without user/profile/version scope.
- MICA endpoint action policy uses database role alone, conflating MIS and Doctor.
- Doctor daily summary retrieves general identity-registration counts.
- Alert listing/detail use admin target role, exposing MIS alerts to Doctors.
- Legacy admin profiles can fall through to broad clinical grounding/context.
- Pending-appointment context contains two concatenated SELECT statements.
- UI welcomes, chips and hub quick actions conflate MIS/Doctor and suggest Teacher
  advisory access despite the personal patient role.
- Nurse applicant grounding returns counts only, even when a permitted list is asked.

## Approach

Extend the existing authorization and context services; isolate history and filter
alerts using verified profiles. Keep clinical data grounded locally, with bounded
applicant projections and human-only approval. Correct shared role suggestions.
Preserve QR, voice/TTS, providers, notifications and operational services. Add
regression coverage to the existing disposable database suite. No production
database writes, deployment, commits or pushes are part of this implementation.
