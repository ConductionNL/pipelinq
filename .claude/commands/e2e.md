---
description: Run the pipelinq Playwright end-to-end suite
---

The suite has no default target, on purpose (see `tests/e2e/base-url.ts`). Ask which instance to use if `PLAYWRIGHT_BASE_URL` is not set; do not point it at a shared development container.

```bash
npm run test:e2e:install                                # one-time Chromium download
PLAYWRIGHT_BASE_URL=<your instance> npm run test:e2e    # tests/e2e
```

$ARGUMENTS
