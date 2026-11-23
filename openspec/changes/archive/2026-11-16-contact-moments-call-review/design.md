# Design: contact-moments-call-review

## Context (read at pipelinq development cfe0a0a51, Nextcloud server 35 checkout)

- **Recording.** `CtiService::attachRecording()` (:359) saves `recording_url` and
  `recording_retention_expires_at` on the contact moment ticket
  (`lib/Settings/register.d/70-cti.json`: "URL of the telephony-platform-hosted
  recording (audio file never stored in pipelinq)", "timestamp past which the
  platform deletes the recording"). It returns whether the write happened.
- **Speech to text.** Nextcloud TaskProcessing task type
  `AudioToText::ID = 'core:audio2text'` (`lib/public/TaskProcessing/TaskTypes/AudioToText.php`);
  a provider app may be absent, so availability is checked at run time.
- **Summaries.** pipelinq asks hermiq for model work through a lazy resolve that
  degrades to nothing (`lib/Service/Competitor/RelevanceScorer.php`).
- **Retention.** Contact moments carry `x-openregister-archival` P2Y
  (`register.d/99-unify-ticket-supertype.json:27`), enforced by OpenRegister.
- **My Work.** `src/views/MyWork.vue` is the agent's own worklist.

## Decisions

### D1. Transcribe after attach, never keep the audio

When `attachRecording()` succeeds and app config `call_transcription_enabled`
is true, pipelinq queues `CallTranscriptionJob` (a `QueuedJob`) for the ticket.
The job downloads the recording to a temporary stream through Nextcloud's HTTP
client, submits a `core:audio2text` task, and on completion writes
`transcript` and sets `transcriptStatus` (`pending, done, failed,
unavailable`). The temporary file is deleted in a `finally`. If the recording's
retention has passed, or no provider serves the task type, the status says so.

### D2. Summary through hermiq, correctable by the agent

With a transcript, the job asks hermiq for a summary of at most five sentences
and writes `callSummary`. Without hermiq, `callSummary` stays empty. The agent
can edit `callSummary` on TicketDetail; the transcript is read only.

### D3. Scorecards and reviews are records

- `callScorecard`: `title`, `criteria[]` (`key`, `label`, `weight`, `scale`
  1 to 5), `isActive`.
- `callReview`: `contactMoment` (ticket ref), `scorecard` ref, `agent` (uid),
  `reviewer` (uid), `scores[]` (`key`, `score`, `remark`), `total` (weighted,
  computed on save), `coachingPoints[]` (`text`, `agreedAt`), `status`
  (`draft, shared, agreed`).
  Read rights: the reviewer, the agent reviewed, and the group
  `pipelinq-call-review`. An agent never sees another agent's reviews. These
  rules sit in the schema's authorization block, so OpenRegister enforces them
  on every read path.

### D4. Screens

- Call reviews page (`/call-reviews`, for the review group): recorded contact
  moments with filters agent, date and reviewed or not, and the reviews.
- TicketDetail: summary card, transcript panel, recording link, and Review this
  call for the review group.
- Review form: one row per criterion, remarks, coaching points, Share with agent.
- My Work (agent): shared reviews with Agree per coaching point.

## Risks

- Transcripts are personal data about the caller. They follow the contact
  moment's retention (P2Y) because they are fields on it, and the feature is off
  until an administrator switches it on. The user doc names the TaskProcessing
  provider as the place the audio is sent.
- A long call can take minutes to transcribe; the status field makes the wait
  visible and the job never blocks the call's completion.
