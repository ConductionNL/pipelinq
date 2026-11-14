# call-review

**Status:** In progress

## Overview

Recorded calls are transcribed and summarised on their contact moment, and a
team lead can review a call against a scorecard and write coaching points the
agent agrees. From pipelinq matrix rows `cm-transcribe` and `cm-quality-review`.

## Switching transcription on

Transcription is off until an administrator switches it on:

```
occ config:app:set pipelinq call_transcription_enabled --value=true
```

It also needs a Nextcloud app that provides speech to text (the
`core:audio2text` task type), such as a local Whisper app or an app that calls
a hosted model. Without one, a contact moment shows the transcript status
**unavailable**.

## Where the audio goes

When the telephony platform attaches a recording to a contact moment, pipelinq
queues a background job. The job downloads the recording into a temporary file,
hands it to Nextcloud's speech-to-text task, and deletes the temporary file
whether the task worked or not. The audio is sent to the app that provides
speech to text, and to wherever that app sends it; choose that app with this
in mind. The audio itself is never stored in Nextcloud Files or OpenRegister.

The transcript and a short summary are stored on the contact moment. The
summary is written by hermiq when it is installed; without hermiq it stays
empty. The agent can correct the summary; the transcript cannot be edited.
Both follow the contact moment's retention period.

The transcript status tells where it stands: pending, done, failed,
unavailable (no speech-to-text app) or expired (the platform no longer keeps
the recording).

## Scorecards and reviews

An administrator keeps a **call scorecard**: the criteria a call is scored on,
each with a weight and a scale of 1 to 5.

A member of the group `pipelinq-call-review` writes a **call review** of a
recorded call: a score and remark per criterion and coaching points. On every
save pipelinq computes the review's total, the weighted mean of its scores,
rounded to one decimal. A review starts as a draft and is shared with the agent.

## Who sees a review

A review is read by:

- the group `pipelinq-call-review`,
- the reviewer who wrote it,
- the agent whose call it is.

Another agent never sees it. OpenRegister enforces this on every read, so it
holds in lists, search and the API alike. Only the call review group can change
the scores and who the review is about; the agent can mark coaching points
agreed. When every coaching point of a shared review carries an agreed date,
the review becomes **agreed**.

## Specification

Full specification: `openspec/changes/contact-moments-call-review/specs/call-review/spec.md`
