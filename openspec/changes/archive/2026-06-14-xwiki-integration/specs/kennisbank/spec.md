## MODIFIED Requirements

---

### Requirement: Kennisbank Navigation

The kennisbank sidebar navigation entry MUST be replaced by the xWiki integration entry.

#### Scenario: Sidebar navigation shows xWiki instead of kennisbank

- GIVEN the user views the Pipelinq sidebar navigation
- WHEN the navigation renders
- THEN the "Kennisbank" entry MUST link to the xWiki NC app page (if available) or show the xWiki widget view
- AND the old `/kennisbank` route MUST still be accessible via direct URL but no longer appear in navigation

---

## REMOVED Requirements

---

### Requirement: Article Management (Deprecated)

The built-in article CRUD (create, edit, publish, archive) is deprecated in favor of xWiki's native article management.

**Reason**: xWiki provides professional wiki features (versioning, permissions, templates, macros, collaboration) that are superior to the built-in kennisbank. Maintaining a parallel knowledge system duplicates effort.

**Migration**: Existing kennisbank articles remain accessible at `/kennisbank`. Administrators should manually recreate important articles in xWiki. The built-in kennisbank code will be removed in a future major version.

#### Scenario: Deprecated routes still function

- GIVEN the user navigates to `/kennisbank` directly
- WHEN the page loads
- THEN the existing kennisbank view MUST still render
- AND a deprecation banner MUST be shown: "De ingebouwde kennisbank is vervangen door xWiki. Gebruik de xWiki integratie voor nieuwe artikelen." (translatable)

---

### Requirement: Rich Text Editing (Deprecated)

The built-in Markdown editor for kennisbank articles is deprecated.

**Reason**: xWiki provides a full WYSIWYG editor with templates, macros, and structured content support.

**Migration**: Users create and edit articles directly in xWiki at the configured instance URL.

#### Scenario: Editor shows deprecation notice

- GIVEN the user navigates to `/kennisbank/articles/new` or `/kennisbank/articles/:id/edit`
- WHEN the editor page loads
- THEN a deprecation banner MUST be shown with a link to the xWiki instance
- AND the editor MUST still function for editing existing articles

---

### Requirement: Kennisbank Background Job (Deprecated)

The `KennisbankReviewJob` background job for review reminders is deprecated.

**Reason**: xWiki has its own notification and review mechanisms.

**Migration**: Disable the background job in a future version. For now it continues to run for existing kennisbank articles.

#### Scenario: Background job continues for existing articles

- GIVEN existing kennisbank articles exist in OpenRegister
- WHEN the `KennisbankReviewJob` runs
- THEN it MUST continue to send review notifications for existing articles
- AND it MUST NOT affect xWiki content
