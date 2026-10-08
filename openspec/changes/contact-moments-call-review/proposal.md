---
kind: code
depends_on: []
---

# Proposal: contact-moments-call-review

## Summary

A recorded call gets a transcript and a short summary on its contact moment,
written for the agent instead of by them. A team lead listens back, scores the
call on the team's scorecard and writes coaching points, and the agent confirms
them. Today a recorded call has a link to the audio and nothing else.

## Motivation

Two rows of the pipelinq capability matrix (`openspec/parity/capabilities.json`,
compared 2026-09-24), both decided `build` by the OpenSpec pass of 2026-09-27.

**`cm-quality-review`**, "Listen back to a recorded conversation, score it and
agree coaching points with the colleague". Rated partial, built.state built.
Matrix evidence: "lib/Service/CtiService.php:359 attachRecording writes the
platform's recording link onto the contact moment ticket, so a recorded call
can be listened back from the ticket; there is no scoring form, scorecard or
coaching record anywhere in lib/ or src/". Note: "listen back only; score and
coaching points are missing". Demand: tender,
https://www.tenderned.nl/aankondigingen/overzicht/318074, with requirements
"intelligence-db requirements#137422 (Het Juridisch Loket, 2023-11-29:
klantinteracties historisch en realtime zoeken en beluisteren voor coaches)" and
"intelligence-db requirements#173511 (CIBG, 2026-03-04: coaching en feedback op
basis van gespreksanalyse)". One competitor rates it yes:

- hubspot-crm: https://knowledge.hubspot.com/calling/review-call-recordings-and-transcripts,
  "Any user who is recording calls made from HubSpot can review and coach on the
  call recording"; https://knowledge.hubspot.com/calling/use-coaching-playlists-to-train-your-team.

**`cm-transcribe`**, "Have a call transcribed and summarised into the contact
moment". Rated no, built.state none. Matrix evidence: "no transcription or speech
service in lib/ or src/; lib/Settings/register.d/70-cti.json:111 keeps only a
recording_url ('audio file never stored in pipelinq'), and channelMetadata
(99-unify-ticket-supertype.json:344) holds at most a 'chat transcript link'".
Demand: tender, https://www.tenderned.nl/aankondigingen/overzicht/414529, with
"intelligence-db requirements#173516 (CIBG Customer Service Platform,
2026-03-04: AI voor gespreksanalyse, transcriptie en samenvattingen)" and
"intelligence-db requirements#204170 (Gemeente Noordwijk, 2026-04-08: realtime
transcriptie in een zaaksysteem)"; changelogs https://www.odoo.com/odoo-20-release-notes
and https://www.pipedrive.com/en/product-updates. Two competitors rate it yes:

- hubspot-crm: https://knowledge.hubspot.com/calling/review-call-recordings-and-transcripts,
  "you can transcribe and analyze recordings" (Sales Hub or Service Hub
  Professional or Enterprise).
- pipedrive: https://support.pipedrive.com/en/article/nova, "After the meeting,
  Nova generates a summary, identifies follow-up actions and suggests CRM updates
  for you to review before saving".

A reviewer scores a call by reading as much as by listening, so the transcript
and the review share one change.

## What changes

- An administrator can switch on transcription. A recorded contact moment is
  then transcribed through Nextcloud's speech-to-text task and summarised; both
  texts are stored on the contact moment, the audio is not.
- The agent sees the summary on TicketDetail and can correct it.
- A scorecard, kept by an administrator, lists the criteria a call is scored on.
- A Call reviews page lists recorded contact moments to review. A reviewer opens
  one, listens, reads the transcript, scores each criterion and writes coaching
  points. The agent sees the review in My Work and marks the points agreed.

## Out of scope

- Live transcription during the call (the Noordwijk requirement). This change
  works on the recording after the call; live transcription needs the telephony
  platform's audio stream and is a separate decision.
- Automatic scoring by a model. Scores come from a person.

## Impact

- `ticket` gains `transcript`, `callSummary`, `transcriptStatus`.
- New schemas `callScorecard` and `callReview`.
- New `lib/Service/CallTranscriptionService.php`, a queued job, and a Call reviews page.
