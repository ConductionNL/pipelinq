---
title: Linking to the agent panel
description: How another app opens pipelinq's contact-centre agent panel with a plain URL.
---

# Linking to the agent panel

Pipelinq owns the contact-centre agent panel: the live call, who is calling
and their open items. Any other app can send a user there with an ordinary
hyperlink. It needs no pipelinq component, no iframe and no build-time
dependency on pipelinq.

## The link

The panel lives at the pipelinq page route `/apps/pipelinq/kcc/agent-panel`.
Build the URL with `@nextcloud/router`, as for any other cross-app link:

```js
import { generateUrl } from '@nextcloud/router'

// The agent's live queue: the newest active call is shown.
const panel = generateUrl('/apps/pipelinq/kcc/agent-panel')

// One call the linking page already knows: that call is focused.
const focused = generateUrl('/apps/pipelinq/kcc/agent-panel?callId={callId}', { callId })
```

`generateUrl()` only fills `{placeholders}`; it does not turn extra
parameters into a query string. So write `callId` as a placeholder in the
query, as above. The router encodes the value, so a call id with `/`, `&` or
spaces arrives unchanged.

In a manifest page, the same URL goes into a widget's link config as plain
text; no code is needed.

## What the panel does with it

| Link | Panel |
|---|---|
| no `callId` | shows the agent's newest active call |
| `?callId=42` | focuses call `42` |
| empty `callId`, or one longer than 255 characters | ignored, as if there were none |

Pipelinq builds its own links to the panel with the same call, in
`src/services/agentPanelLink.js` (`agentPanelUrl()`), and reads the focused
call with `focusedCallId()`. `tests/vitest/kccAgentPanel.spec.js` builds the
link the way a consuming app does and checks that the panel reads the same
call back.

## Spec

- OpenSpec change: `openspec/changes/kcc-agent-panel/`, requirement
  "Another app links in with a plain URL".
