# EU icons for labelling AI-generated content

## Location

Served as plain static files from here (`static/img/ai-disclosure/`),
referenced by `<AiDisclosure>` via `useBaseUrl('img/ai-disclosure/<file>')`
rather than a webpack `.svg` import. This preset also enables
`@docusaurus/plugin-svgr` (via the classic preset), which unconditionally
turns any `.svg` imported from a JS/JSX/MDX file into an inlined React
component, regardless of a `?url` suffix. An inlined SVGR component would
also leak the unscoped `.cls-1`/`.cls-2` fill classes below across every
mark rendered on the same page (two different marks would fight over the
same class names). A plain `<img src>` pointed at an untouched static
file avoids both problems.

## Where these came from

Downloaded from the European Commission on 2026-07-29:

- Source page: https://digital-strategy.ec.europa.eu/en/policies/eu-icons-labelling-ai-generated-content
- SVG package: https://ec.europa.eu/newsroom/dae/redirection/document/129546
- Published 2026-06-10 as part of Section 2 of the Code of Practice on
  Transparency of AI-Generated Content.

## Licence

The Commission makes these icons "publicly available for everyone to use freely,
without the need for attribution." They are vendored here rather than hot-linked
so the site builds offline and under a restricted egress policy — `ec.europa.eu`
is not on the pipeline's allowlist, so a build-time fetch would fail closed.

## What each icon means

| File prefix     | Official name          | Use for |
|-----------------|------------------------|---------|
| `ai-`           | Basic                  | AI assisted the work, or a custom/interactive label is used |
| `ai-generated-` | Fully AI-Generated     | Entirely AI-produced, no human-authored elements or editorial control (prompting excluded) |
| `ai-modified-`  | Partially AI-Modified  | Pre-existing human content partially altered with AI |

Each comes in four colour treatments: `black`, `white`, `black-transparent`,
`white-transparent`.

**Aspect ratios differ per icon** and must be preserved — the basic mark is
square, the two word-marks are wide:

| Icon | viewBox |
|---|---|
| `ai-*` | `0 0 566.93 566.93` (1:1) |
| `ai-modified-*` | `0 0 1700.79 566.93` (~3:1) |
| `ai-generated-*` | `0 0 1789.84 566.93` (~3.16:1) |

## Two things that are easy to get wrong

**1. These icons do not make you compliant.** Their use is *optional*; the
Article 50 labelling obligation is not. The Commission states plainly that using
them "does not establish legal compliance" on its own. Any copy rendered
alongside them must therefore not claim compliance.

**2. Do not imply Code-of-Practice adherence.** The Commission asks that
non-signatories' use of the icons not signal adherence to the Code. Unless
Conduction has signed it, the disclosure copy must stay factual ("this page was
generated with AI") and avoid wording that reads as conformity to the Code.

## Renaming, and one upstream typo

Files were renamed from the Commission's originals (which contain spaces and
inconsistent casing) to kebab-case for predictable imports. One original is
misspelled upstream — `LABEL_AI MOFIFIED_black.svg`, i.e. "MOFIFIED" — and is
vendored here as `ai-modified-black.svg`. If the package is ever re-downloaded,
check whether that typo has been corrected before assuming a mapping.

Original → vendored:

```
LABEL_AI_black.svg                       -> ai-black.svg
LABEL_AI_black transparent.svg           -> ai-black-transparent.svg
LABEL_AI_white.svg                       -> ai-white.svg
LABEL_AI_white transparent.svg           -> ai-white-transparent.svg
LABEL_AI GENERATED_black.svg             -> ai-generated-black.svg
LABEL_AI GENERATED_black transparent.svg -> ai-generated-black-transparent.svg
LABEL_AI GENERATED_white.svg             -> ai-generated-white.svg
LABEL_AI GENERATED_white transparent.svg -> ai-generated-white-transparent.svg
LABEL_AI MOFIFIED_black.svg              -> ai-modified-black.svg          (sic)
LABEL_AI MODIFIED_black transparent.svg  -> ai-modified-black-transparent.svg
LABEL_AI MODIFIED_white.svg              -> ai-modified-white.svg
LABEL_AI MODIFIED_white transparent.svg  -> ai-modified-white-transparent.svg
```
