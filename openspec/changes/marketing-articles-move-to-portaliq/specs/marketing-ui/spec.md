## MODIFIED Requirements

### Requirement: The Templates Form Lets a Marketer Pick Articles

The campaign template form SHALL let a marketer choose published articles from portaliq and order them, and SHALL say where in the body they will appear. The form SHALL make the `{{articles}}` marker easy to place rather than expecting the marketer to remember it. Picking articles for a template whose body carries no marker SHALL warn the marketer that the articles will not be rendered, and SHALL still save. Each picked article SHALL link to the article in portaliq. When portaliq is not installed, the picker SHALL say that articles are written in portaliq and SHALL offer no choices.

#### Scenario: A marketer picks two articles for a template

- **GIVEN** two published articles in portaliq
- **WHEN** a marketer opens a campaign template, picks both and saves
- **THEN** the template SHALL be stored with both portaliq article ids in the chosen order

#### Scenario: Picking articles for a body without the marker warns the marketer

- **GIVEN** a campaign template whose body carries no `{{articles}}` marker
- **WHEN** a marketer picks an article
- **THEN** the form SHALL warn that the articles will not appear until the marker is placed
- **AND** saving SHALL still succeed

#### Scenario: The blast preview shows the embedded articles

- **GIVEN** a campaign template naming two portaliq articles and carrying the marker
- **WHEN** a marketer previews a blast built on that template
- **THEN** the preview SHALL show both articles' titles and summaries where the marker stood

## ADDED Requirements

### Requirement: The Marketing menu no longer carries Articles

pipelinq SHALL NOT offer an Articles page, an article editor or an Articles card on the Modules page. The addresses `/articles` and `/articles/:id` SHALL redirect to portaliq's Articles page and to the same article there when portaliq is installed.

#### Scenario: An old article link opens the article in portaliq

- **GIVEN** an article that moved to portaliq
- **WHEN** a marketer opens its old pipelinq address
- **THEN** the browser SHALL land on that article in portaliq
