# Design: latestState() asks as a probe

- `askChunk()` passes `$probe` as the ninth constructor argument of the decision event. PHP ignores an extra argument on an older integriq, so nothing breaks there.
- `probeOne()` is a separate method, not a flag on `decideOne()`, so a send can never pass it by accident.
- The answer is read the same way: `opted-out`, `opted-in` (allowed with consent) or `unknown`.
