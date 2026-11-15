## ADDED Requirements

### Requirement: A Campaign Template May Embed Articles

A campaign template SHALL be able to name articles, in the order the marketer chose. A template body carrying the `{{articles}}` marker SHALL have that marker replaced, in both the HTML and the plain-text body, by the named articles rendered as title, summary and hero image. An article whose `portalPageRef` is set SHALL render a "read more" link to that page; an article without one SHALL render no link at all rather than a link that goes nowhere. A template naming no articles, or a body carrying no marker, SHALL render exactly as it does today.

#### Scenario: The articles block renders into the HTML body

@e2e exclude the substitution happens inside `BlastService::renderTemplate()` on the way to an openconnector send, and the CI instance installs no openconnector, so no browser run reaches a rendered body. Asserted by tests/Unit/Service/ArticleRenderingTest.php (testHtmlBlockRendersTitleSummaryAndHero, testTemplateWithoutMarkerIsUnchanged).

- **GIVEN** a campaign template whose HTML body carries `{{articles}}` and which names two published articles
- **WHEN** a delivery is rendered from that template
- **THEN** the HTML body SHALL carry both articles' titles and summaries in the order the template named them
- **AND** the marker SHALL no longer appear in the rendered body

#### Scenario: The articles block renders into the plain-text body

@e2e exclude same send path as above; the text body never reaches a browser. Asserted by tests/Unit/Service/ArticleRenderingTest.php (testTextBlockRendersTitleAndSummary).

- **GIVEN** a campaign template whose plain-text body carries `{{articles}}` and which names one article
- **WHEN** a delivery is rendered from that template
- **THEN** the text body SHALL carry the article's title and summary as plain text with no HTML tags

#### Scenario: An article without a portal page renders no read-more link

@e2e exclude same send path as above. Asserted by tests/Unit/Service/ArticleRenderingTest.php (testArticleWithoutPortalPageRefRendersNoLink, testArticleWithPortalPageRefRendersReadMoreLink).

- **GIVEN** a template naming one article whose `portalPageRef` is empty
- **WHEN** a delivery is rendered from that template
- **THEN** neither body SHALL carry a read-more link for that article
- **AND** when the same article carries a `portalPageRef`, both bodies SHALL carry a read-more link to it

#### Scenario: A template naming no articles renders unchanged

@e2e exclude same send path as above. Asserted by tests/Unit/Service/ArticleRenderingTest.php (testTemplateNamingNoArticlesRendersAnEmptyBlock).

- **GIVEN** a template whose body carries `{{articles}}` but which names no articles
- **WHEN** a delivery is rendered from that template
- **THEN** the marker SHALL be removed and no article markup SHALL be added
