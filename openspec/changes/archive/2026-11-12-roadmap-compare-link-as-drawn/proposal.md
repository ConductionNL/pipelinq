# Proposal: roadmap-compare-link-as-drawn

## Why

pl6 on :8080, PqRoadmap: the "Hoe pipelinq zich verhoudt tot andere helpdesks" line stood 16px in and 12px down at
15px, off the board's page padding, and the title stood 2.5px high. The line was pipelinq's own copy of a link the
library page already draws (`CnFeaturesAndRoadmapPage` `compareUrl`), so its look could not follow the library's board
look.

## What changes

- FeaturesRoadmapView hands the comparison URL (the reader's language) and its text to the library page and drops its
  own copy of the line and its styles. The library draws the link, its new-tab hint and, from nc-vue
  fix/bpq5-roadmap-compare-board-inset, its board-look place.

## Reach

FeaturesRoadmapView only. The link, its target, its hint and its language stay as they were.
