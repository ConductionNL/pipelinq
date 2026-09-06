## Purpose

The content hub. An article is a piece of writing the tenant owns: a markdown body, a hero image, a summary, links and tags, written once and reused by a mailing, a social post and a public page. It carries a lifecycle from draft to published, a mark when an agent drafted it, and an answer to the question a marketer actually asks before rewriting anything: where has this already been used?

## ADDED Requirements

### Requirement: An Article Holds Its Body as Markdown and Its Own Identity

An article SHALL carry a title, a URL-safe slug, a summary, a markdown body, a language, an author and a status. The slug SHALL be unique within the register, because it is what a public page and a link both address. The body SHALL be stored as markdown and never as HTML, so the same text can be rendered into an email, a post and a page without one of the three owning the markup.

#### Scenario: An article is created as a draft

- **WHEN** a marketer creates an article with a title and a body
- **THEN** the article SHALL be stored with status `draft`, the author set to the authenticated user and `publishedAt` empty
- **AND** a slug SHALL be present, derived from the title when the marketer supplied none

#### Scenario: A second article cannot claim a slug already in use

- **GIVEN** an article whose slug is `nieuwe-release-najaar`
- **WHEN** a marketer creates another article with the same slug
- **THEN** the request SHALL be refused with a validation error naming the slug
- **AND** the second article SHALL NOT be stored

#### Scenario: An article without a title is refused

- **WHEN** a marketer saves an article whose title is empty
- **THEN** the request SHALL be refused with a validation error naming the title
- **AND** nothing SHALL be stored

### Requirement: An Article Moves Through a Declared Lifecycle

The status field SHALL be `draft`, `review`, `published` or `archived`, and the legal moves between them SHALL be declared on the schema rather than implemented in a service. Publishing SHALL stamp `publishedAt`; a second publish SHALL NOT move the first stamp. Archiving SHALL leave the article readable and SHALL leave every reference to it intact, because a mailing already sent still names it.

#### Scenario: Publishing a reviewed article stamps the publication moment

- **GIVEN** an article in status `review`
- **WHEN** a marketer publishes it
- **THEN** the article SHALL be in status `published` with `publishedAt` set to the moment of publication

#### Scenario: Publishing twice keeps the first publication moment

- **GIVEN** an article already published, with `publishedAt` set
- **WHEN** it is published a second time
- **THEN** `publishedAt` SHALL still hold the first value

#### Scenario: An archived article stays readable and keeps its references

- **GIVEN** a published article named by a campaign template
- **WHEN** a marketer archives it
- **THEN** the article SHALL be in status `archived` and still readable
- **AND** the template SHALL still name it, and its usages SHALL still list that template

#### Scenario: A transition the lifecycle does not declare is refused

- **GIVEN** an article in status `archived`
- **WHEN** a caller tries to publish it directly
- **THEN** the transition SHALL be refused and the status SHALL still be `archived`

### Requirement: An Agent-Drafted Article Is Marked as Such

An article written or modified by an agent SHALL carry `agentAuthored` true and `agentAuthoredBy` naming the agent, applied by the write path itself and never taken from a client request (ADR-088). A person editing an agent-authored article SHALL be able to clear the mark by taking authorship, and the interface SHALL show the mark wherever the article is read.

#### Scenario: A client cannot claim an article was written by a person

- **WHEN** a create or update request carries `agentAuthored` or `agentAuthoredBy` in its body
- **THEN** both fields SHALL be ignored and the stored values SHALL be the ones the write path determined

#### Scenario: The mark is visible on the article

- **GIVEN** an article with `agentAuthored` true and `agentAuthoredBy` naming an agent
- **WHEN** a marketer opens the article
- **THEN** the page SHALL show that an agent drafted it and name the agent

### Requirement: An Article Reports Where It Has Been Used

An article SHALL be able to answer which campaign templates, mailings and blasts reference it. That answer SHALL be derived at read time from the referencing objects and SHALL NOT be stored on the article, so removing a reference removes the usage without a second write. An article no one references SHALL report an empty list rather than an error.

#### Scenario: Usages name the templates and blasts that reference the article

- **GIVEN** an article named by one campaign template, and a blast that used that template
- **WHEN** the usages of the article are read
- **THEN** the answer SHALL name the template and the blast, each with its id, its display name and its kind

#### Scenario: Removing a reference removes the usage

- **GIVEN** an article named by a campaign template
- **WHEN** the article is removed from that template's `articleIds`
- **THEN** the usages of the article SHALL no longer name the template
- **AND** nothing SHALL have been written to the article

#### Scenario: An unused article reports an empty list

- **GIVEN** an article no template, mailing or blast references
- **WHEN** its usages are read
- **THEN** the answer SHALL be an empty list and the response SHALL be successful

### Requirement: A Marketer Writes and Reads an Article in the Interface

The Marketing menu SHALL carry an Articles entry between Templates and Lists. The index SHALL show each article as a card with its hero image, its title, its summary and a status chip. The detail page SHALL render the markdown body as formatted text, show the hero image, list where the article has been used, and open an editor in which the body is written with a markdown editor and the hero image is picked from Nextcloud Files.

#### Scenario: The Articles page lists the seeded articles

- **GIVEN** a fresh instance carrying the seeded demo articles
- **WHEN** a marketer opens Marketing and then Articles
- **THEN** the page SHALL list the three seeded articles, each with its title and its status

#### Scenario: The detail page renders the body as formatted text

- **GIVEN** a published article whose body carries a markdown heading and a list
- **WHEN** a marketer opens the article
- **THEN** the body SHALL render as a heading and a list, not as the markdown source

#### Scenario: A marketer writes a new article and publishes it

- **WHEN** a marketer creates an article, writes a body in the markdown editor and publishes it
- **THEN** the article SHALL appear on the Articles page with status `published`
