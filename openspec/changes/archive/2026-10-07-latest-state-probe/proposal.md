# latestState() asks integriq as a probe

Follows `opt-out-before-send` (pipelinq#2162). Approved by Ruben on 2026-10-05. Contract: hydra `opt-out-per-purpose`, integriq `opt-out-per-purpose`.

## Why

`ConsentService::latestState()` (`lib/Service/ConsentService.php:222`) shows a contact's messaging consent on screen (`lib/Controller/MessagingController.php:245`). It asks integriq through `OutboundSendDecisionRequestedEvent`. integriq logs every ask in its 7-year decision log. So opening a contact writes a "suppressed" row for a message nobody sent.

## What changes

- `IntegriqConsentClient::probeOne()` asks with `probe: true`, the event's ninth argument.
- `latestState()` uses it. Every send keeps asking without the flag, so sends stay logged.
- An integriq without the flag ignores it and logs the ask, as today.

## Capabilities

### Modified capabilities

- `consent-in-integriq`: adds REQ-CII-007.

## Rollback

Revert the PR. `latestState()` asks for real again and the log grows again.
