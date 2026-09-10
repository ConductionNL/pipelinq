---
status: done
---

# features-roadmap Specification

## Purpose

The Features & roadmap page answers two different questions, and it keeps them
apart. "What can pipelinq do, and is it stable" is the feature list and the
roadmap. "How does pipelinq compare to the alternatives" is the capability
comparison. Losing either one to the other is a regression: the first is what a
user checks before relying on a capability, the second is what a buyer checks
before choosing a system at all.

The comparison is a vendor-authored comparison of other people's software, so
the page states its own limits before it states a score.

**The subject is the help desk.** Pipelinq is compared against two open source
help desks, on questions a service desk answers. That boundary is the point:
pipelinq also does CRM, marketing and point of sale, and a table that mixed
those in would compare products nobody puts side by side.

**Surface**: `src/manifest.json#FeaturesRoadmap` (`type: "custom"`,
`component: "FeaturesRoadmapView"`, registered in `src/registry.js`), route
`/features-roadmap`, reachable from the footer menu entry
`FeaturesRoadmapMenu`.

## Requirements

### Requirement: The page MUST keep the shipped feature list and the roadmap

The page SHALL render the library's `CnFeaturesAndRoadmapPage`. Adding the
comparison SHALL NOT remove or replace the features tab or the roadmap tab.

#### Scenario: Features page renders controls

- **GIVEN** a user opens `/features-roadmap`
- **WHEN** the page loads
- **THEN** the features section MUST be the section that is shown
- **AND** the shipped feature cards MUST be visible
- **AND** the roadmap toggle and the suggest-a-feature link MUST be reachable

### Requirement: The page MUST present the capability comparison by area

The page SHALL offer a second section that compares pipelinq against the
systems in `src/data/capabilityComparison.json` across every capability in that
file. Capabilities SHALL be grouped by their area, and an area SHALL open to
reveal its rows. Each row SHALL show its number, the capability, and a rating
for every system.

A rating SHALL be conveyed by a word, never by colour alone.

A rating MAY be `unknown`, and `unknown` SHALL be rendered and counted like any
other rating rather than left blank. A later round can add a capability row
without re-reading the products an earlier round rated, and the honest cell for
those products is one that says nobody looked. Hiding it would show a reader a
rival scored over fewer rows than ours with nothing to explain the difference.

#### Scenario: Areas summarise before they expand

- **GIVEN** a user opens the comparison section
- **WHEN** the areas are listed
- **THEN** each area MUST state how many capabilities it holds and how pipelinq scored
- **AND** the individual rows MUST stay collapsed until the reader opens that area

### Requirement: Every rival rating MUST name the reading it came from

Pipelinq does not rate a product it has not run. Every capability row SHALL
carry a `source`, naming the question in the reading that produced the rival
ratings on that row. A row without a source is a row whose rival cells nobody
can check, which is the failure this whole page exists to avoid.

The rival columns SHALL be the ratings that reading gave, unchanged. Rewording a
row for a help desk audience SHALL NOT change the question it asks, because the
answer travels with the question and not with the wording.

#### Scenario: A row without a source fails

@e2e exclude The source is a field in a committed JSON file, not a rendered thing. The failure mode is an edited row, which no browser can see; it is asserted in tests/vitest/capabilityComparison.spec.js.

- **GIVEN** a capability row is added without a `source`
- **WHEN** the unit suite runs
- **THEN** it MUST fail and name the row

### Requirement: The comparison MUST state its own limits

The comparison section SHALL state, before any score:

1. That only open source software the team could install and run itself was
   compared, that the named systems are the whole field, and that a product's
   absence is not a verdict on that product.
2. The date the reading was made, and that some ratings are already out of date
   because open source moves fast.
3. That a rating is the team's own reading and is not proof that a product does
   or does not have a capability.
4. A plain recommendation that the reader run their own evaluation, and that
   this table does not replace testing against their own requirements. This one
   is not optional and it is not a restatement of item 3: item 3 tells the
   reader what to discount, and this tells them what to do about it. A panel
   that discounts itself three times and never says "go and test" reads as
   hedging.
5. The first concrete step: shortlist the capabilities they need and test every
   system against that shortlist.
6. That only our own column is corrected between rounds, and that a rival is
   re-rated only by installing it again. When any rating in our own column has
   been corrected since the reading, also how many were corrected and when.
   Re-rating a competitor without re-reading the product would be a guess
   presented as a correction.
7. When rows have been added to the list since the reading, how many, when, and
   that the rival columns are unrated on them. This is the same rule as item 6
   pointed at the list instead of at a score: we may re-rate ourselves because
   we can read our own code, and we may not rate a product we did not open. A
   guess in a rival's column is worse than an empty cell, because a reader
   cannot tell the two apart.
8. What the capability list is made of, that it is written in our own shape,
   and that it grows. A capability none of the three has is absent from the
   LIST rather than from the market. The rows are framed the way pipelinq
   splits the work, so a product that splits it differently scores low without
   being worse. **That bias runs in our favour, which is exactly why the panel
   has to declare it.** A total that flatters us for a structural reason is
   worth less than no total. And without the growth clause, a reader who
   watches the totals fall between two releases has no way to tell a growing
   denominator from a regressing product.

#### Scenario: The panel advises the reader to test for themselves

- **GIVEN** a reader opens the comparison
- **WHEN** they read the panel
- **THEN** it MUST recommend that they run their own evaluation
- **AND** it MUST say that the table does not replace testing against their own requirements

#### Scenario: A reader can date the claim

- **GIVEN** a reader opens the comparison
- **WHEN** they read the disclaimer
- **THEN** the date the reading was made MUST be shown in their own language

#### Scenario: The panel says only our own column is corrected

- **GIVEN** a reader opens the comparison
- **WHEN** they read the panel
- **THEN** it MUST say that a correction applies to our own column only

#### Scenario: The panel accounts for rows a later round added

@e2e exclude No row in this round carries `addedOn`, so the sentence is correctly absent from the page and a browser has nothing to find. The mechanism that renders it, and the rule that a rival cell may only be `unknown` on such a row, are both asserted in tests/vitest/capabilityComparison.spec.js.

- **GIVEN** a round has added capability rows since the rivals were read
- **WHEN** a reader opens the comparison
- **THEN** the panel MUST say how many rows were added and when
- **AND** it MUST say that the rival columns are unrated on those rows
- **AND** those rows MUST show `unknown` for every rival, never a guess

### Requirement: The comparison data MUST match the reading it came from

`src/data/capabilityComparison.json` is written once, offline, from a private
reading of two installed products, so no CI job can regenerate it and diff the
result. The data SHALL therefore be guarded by assertions: the row count,
unique ids, every row filed under a declared area, every row carrying a source,
every rating drawn from the known set, and the per-system totals.

Two further assertions guard the `unknown` rating, because it is the one value
that can be written for the wrong reason. Our own column SHALL never be
`unknown`: we can read our own code, so an empty cell there is an unfinished
row that understates our score for free. And a row carrying a rival `unknown`
SHALL carry `addedOn`, while a row carrying `addedOn` SHALL be `unknown` for
every rival. That pins the value to its only honest cause.

The four caveats SHALL additionally be asserted against the rendered component,
not only end to end. Each one is a plain paragraph inside a note card, and
deleting one while editing the panel around it breaks nothing a build can see.

@e2e exclude Guarded by assertions over the committed data file in tests/vitest/capabilityComparison.spec.js and by a mounted-component spec in tests/vitest/featuresRoadmapComparison.spec.js, neither of which a browser can reach: the failure mode is an edited JSON row or a deleted paragraph, not a broken screen.

#### Scenario: An edited row changes a total and fails

- **GIVEN** a capability row is edited by hand
- **WHEN** the unit suite runs
- **THEN** the tally assertion for the affected system MUST fail

#### Scenario: A guessed rival rating fails

- **GIVEN** a row added after the rivals were read
- **WHEN** somebody fills a rival cell on it with a rating
- **THEN** the unit suite MUST fail and name the row

### Requirement: Every user-visible string MUST exist in Dutch

Capability names and area names SHALL carry a Dutch variant beside the English
one (`name_nl` beside `name`). The page SHALL render the Dutch variant for a
Dutch locale and fall back to English when a Dutch variant is absent or blank.
Page chrome SHALL be translated through `t('pipelinq', …)` and `l10n/nl.json`.

@e2e exclude The e2e instance runs one locale, so a Dutch render cannot be driven there. The locale selection is asserted directly in tests/vitest/capabilityComparison.spec.js (groupByArea with nl).

#### Scenario: A Dutch reader gets a Dutch table

- **GIVEN** a user whose locale is Dutch
- **WHEN** they open the comparison
- **THEN** the area names and the capability names MUST render in Dutch
