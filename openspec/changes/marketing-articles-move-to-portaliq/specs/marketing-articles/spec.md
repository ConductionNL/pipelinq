## ADDED Requirements

### Requirement: pipelinq reads marketing articles from portaliq

pipelinq SHALL NOT store marketing articles. Where pipelinq needs an article (an `{{articles}}` block in a campaign template, a social post, a campaign page) it SHALL hold the id of an article in portaliq's `article` schema and read it from the `portaliq` register through OpenRegister. It SHALL check that the app `portaliq` is installed before reading. When portaliq is absent, the block SHALL render nothing and the send SHALL go ahead.

#### Scenario: A template renders a portaliq article

- **GIVEN** a published article in portaliq, and a campaign template whose `articleIds` names it and whose body carries `{{articles}}`
- **WHEN** a marketer previews a blast built on that template
- **THEN** the preview SHALL show the article's title and summary where the marker stood

#### Scenario: Without portaliq the block renders empty and nothing fails

@e2e exclude needs an instance without portaliq; covered by ArticleServiceTest with the app manager reporting portaliq absent.

- **GIVEN** portaliq is not installed
- **WHEN** a blast built on a template with `{{articles}}` is sent
- **THEN** the marker SHALL be replaced by nothing and the blast SHALL be sent

### Requirement: pipelinq tells portaliq where an article is used

pipelinq SHALL answer portaliq's request for the usages of an article with every campaign template, blast and social post that names it, each with its id, display name, kind and a link into pipelinq. The answer SHALL be derived at read time and SHALL NOT be written to the article.

#### Scenario: A template and a blast show up as usages in portaliq

- **GIVEN** a portaliq article named by one pipelinq campaign template and a blast built on that template
- **WHEN** a marketer opens the article in portaliq
- **THEN** its usage list SHALL name the template and the blast, each linking into pipelinq

### Requirement: Existing pipelinq articles move to portaliq once

A repair step SHALL copy every pipelinq `article` object into portaliq's `article` schema with every property intact and `legacyRef` set to `pipelinq:<uuid>`, SHALL rewrite `campaignTemplate.articleIds` and `socialPost.articleId` to the new ids, and SHALL be idempotent. It SHALL report how many articles moved, were already moved and failed. It SHALL do nothing, and say so, when portaliq or its `article` schema is absent.

#### Scenario: Running the move twice moves nothing the second time

@e2e exclude a repair step has no browser surface; covered by MoveMarketingArticlesToPortaliqTest.

- **GIVEN** three pipelinq articles, one named by a campaign template
- **WHEN** the repair step runs twice
- **THEN** portaliq SHALL hold exactly three articles with a `legacyRef`
- **AND** the template SHALL name the portaliq id
- **AND** the second run SHALL report three already moved and none moved

## REMOVED Requirements

### Requirement: A Marketer Writes and Reads an Article in the Interface

**Reason**: Ruben decided on 2026-10-09 that marketing articles belong to portaliq, the fleet's content app. The pages move with the schema.
**Migration**: portaliq's `marketing-articles` capability carries this requirement (change `marketing-articles-from-pipelinq` in ConductionNL/portaliq). The old pipelinq addresses redirect to portaliq.

### Requirement: An Article Moves Through a Declared Lifecycle

**Reason**: The lifecycle is declared on the `article` schema, which moves to portaliq.
**Migration**: Carried unchanged by portaliq's `marketing-articles` capability.

### Requirement: An Agent-Drafted Article Is Marked as Such

**Reason**: The write path that applies the mark moves to portaliq.
**Migration**: Carried unchanged by portaliq's `marketing-articles` capability.

### Requirement: An Article Holds Its Body as Markdown and Its Own Identity

**Reason**: The schema moves to portaliq.
**Migration**: Carried unchanged by portaliq's `marketing-articles` capability; the repair step copies every stored article.

### Requirement: An Article Reports Where It Has Been Used

**Reason**: portaliq owns the article and asks for usages; pipelinq answers for its own objects.
**Migration**: Replaced in pipelinq by "pipelinq tells portaliq where an article is used".
