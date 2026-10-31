# Proposal: roadmap-curated-list

## Why

pl2 measured Functies en roadmap against board PqRoadmap: live listed 65 raw spec entries ("activity-timeline", summaries
such as "@e2e exclude backend service", every one "Beschikbaar", no groups). A spec says how a capability is built; the
page should say what a user gets. The board is a curated list of 36 features in five groups, with Beschikbaar, Beta and
Gepland. Coordinator decision (28 Oct): the curated list lives in pipelinq as a versioned config file and the page reads
that file instead of the raw spec list.

## What changes

- `lib/Settings/roadmap.json`: version, five categories (key to English caption, translated in l10n) and 36 features,
  each with category, status (available, beta, planned), English and Dutch title and summary (the Dutch from the board),
  and optionally the openspec spec it is built from.
- RoadmapFeatureCatalog reads that file first (`curatedFeatures`, `categories`); the spec extraction stays as the
  fallback when the file is absent.
- The categories reach the page as initial state `features_roadmap_categories`; FeaturesRoadmapView passes them to the
  library page, which already groups, filters and captions by category and status.

This amends openspec/specs/features-roadmap: the list is curated, no longer derived from `status: done` specs.
