## ADDED Requirements

### Requirement: The Templates Form Lets a Marketer Pick Articles

The campaign template form SHALL let a marketer choose published articles and order them, and SHALL say where in the body they will appear. The form SHALL make the `{{articles}}` marker easy to place rather than expecting the marketer to remember it. Picking articles for a template whose body carries no marker SHALL warn the marketer that the articles will not be rendered, and SHALL still save.

#### Scenario: A marketer picks two articles for a template

- **GIVEN** two published articles
- **WHEN** a marketer opens a campaign template, picks both and saves
- **THEN** the template SHALL be stored with both article ids in the chosen order

#### Scenario: Picking articles for a body without the marker warns the marketer

- **GIVEN** a campaign template whose body carries no `{{articles}}` marker
- **WHEN** a marketer picks an article
- **THEN** the form SHALL warn that the articles will not appear until the marker is placed
- **AND** saving SHALL still succeed

#### Scenario: The blast preview shows the embedded articles

- **GIVEN** a campaign template naming two articles and carrying the marker
- **WHEN** a marketer previews a blast built on that template
- **THEN** the preview SHALL show both articles' titles and summaries where the marker stood
