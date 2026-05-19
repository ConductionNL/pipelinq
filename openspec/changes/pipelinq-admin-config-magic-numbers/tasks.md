# Tasks — pipelinq: move hardcoded constants to admin-config

ADR-032 cap respected (≤20 unchecked tasks).

Spec-only change. Code paths listed are implementation hints for the apply phase.

## Phase 7 — hardcoded magic-number cleanup

All paths per `.claude/audit-2026-05-03/04-hardcoded.md`. Each becomes admin-config (default
preserved).

- [ ] 7.1 `lib/BackgroundJob/KennisbankReviewJob.php:41` —
      `DEFAULT_REVIEW_INTERVAL = 180` (days?) → admin-config
      `pipelinq.kennisbank.review_interval_days` (default `180`).
- [ ] 7.2 `lib/BackgroundJob/QueueOverflowJob.php:41` — `INTERVAL = 300` (seconds) →
      admin-config `pipelinq.queue_overflow.poll_interval_seconds` (default `300`).
- [ ] 7.3 `lib/BackgroundJob/TaskExpiryJob.php:43` — `INTERVAL = 900` → admin-config
      `pipelinq.task_expiry.poll_interval_seconds` (default `900`).
- [ ] 7.4 `lib/BackgroundJob/TaskExpiryJob.php:50` —
      `ESCALATION_THRESHOLD = 14400` → admin-config
      `pipelinq.task_expiry.escalation_threshold_seconds` (default `14400`).
- [ ] 7.5 `lib/BackgroundJob/TaskExpiryJob.php:57` —
      `IN_PROGRESS_GRACE = 86400` → admin-config
      `pipelinq.task_expiry.in_progress_grace_seconds` (default `86400`).
- [ ] 7.6 `lib/BackgroundJob/TaskEscalationJob.php:43` —
      `ESCALATION_THRESHOLD_HOURS = 4` → admin-config
      `pipelinq.task_escalation.threshold_hours` (default `4`).
- [ ] 7.7 `lib/Service/TaskService.php:73` — `BUSINESS_HOUR_START = 8` → admin-config
      `pipelinq.task.business_hour_start` (default `8`). NL-specific assumption removed.
- [ ] 7.8 `lib/Service/TaskService.php:80` — `BUSINESS_HOUR_END = 17` → admin-config
      `pipelinq.task.business_hour_end` (default `17`).
- [ ] 7.9 `lib/Service/ProspectDiscoveryService.php:36` — `CACHE_TTL = 3600` →
      admin-config `pipelinq.prospect_discovery.cache_ttl_seconds` (default `3600`).
- [ ] 7.10 `lib/Service/KvkApiClient.php:37` —
      `API_BASE = 'https://api.kvk.nl/api/v1'` → admin-config
      `pipelinq.kvk.api_base_url` (default `https://api.kvk.nl/api/v1`). Class is
      LEGITIMATE third-party client; only the URL becomes admin-config so EU/UK regional
      endpoints can be configured.
- [ ] 7.11 `lib/Service/OpenCorporatesApiClient.php:37` —
      `API_BASE = 'https://api.opencorporates.com/v0.4'` → admin-config
      `pipelinq.opencorporates.api_base_url`.
- [ ] 7.12 Confirm Dutch state literals from the lifecycle+notification slice are removed
      from source after lifecycle migration (no `'gepubliceerd'|'nieuw'|'openbaar'`
      literals in `lib/`).
