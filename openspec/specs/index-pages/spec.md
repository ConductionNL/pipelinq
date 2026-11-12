# index-pages Specification

## Purpose
How the list pages draw their rows, such as titles that open the record.

## Requirements

### Requirement: The social posts index draws its titles as row links

The social posts index SHALL draw each post title as the default row link (underlined, weight 600), in both profiles.

#### Scenario: Board PqSocial

- **WHEN** the marketer opens Social posts
- **THEN** each post title is an underlined link at weight 600
- **AND** clicking the row still opens the post
