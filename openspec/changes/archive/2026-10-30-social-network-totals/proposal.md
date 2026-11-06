# Social performance per network on the posts list

## Why

Board PqSocial draws two cards under the social posts list: "Sociale prestaties" (one row per network for the last 30 days: published, views, likes, replies, shares, followers and the interaction rate) and "Social accounts" (each account with its status and the step that fits it). Live drew neither. `/api/social-performance` answers per publication, so a per-network table had nothing to read.

## What changes

- A read `GET /api/social-performance/networks?days=30` answers one row per network with publications in the period: the summed numbers, the followers of that network's accounts, and the interaction rate (likes, replies and shares over views). It sits behind the same marketing access as `/api/social-performance`.
- The social posts page gets both cards as `afterList` widgets: an `object-table` over that read and a `record-list` over the social accounts, each row with its status pill and a link to the account.

## Impact

- `SocialMetricsService::networkTotals()`, `SocialPostController::networkPerformance()`, one route.
- `src/manifest.d/78-social-publishing.json` (SocialPosts `afterList`), en/nl strings.
- No schema change.
