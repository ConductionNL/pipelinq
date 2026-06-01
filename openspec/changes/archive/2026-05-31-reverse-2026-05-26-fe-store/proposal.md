# Reverse-spec — Frontend state stores

> SUPERSEDED 2026-06-01 (partial): Of the 60 store methods this reverse-spec
> documented, ~22 belonged to `src/store/modules/kennisbank.js` (migrated to the
> XWiki leaf — `migrate-kennisbank-to-xwiki-leaf`) plus the automation store
> (migrated to the Flow leaf — `migrate-automation-to-flow-leaf`); those modules
> have been removed. The synced requirement in
> `openspec/specs/openregister-integration/spec.md` was re-scoped to the store
> modules that still exist. The remaining modules' coverage stands. Do NOT
> resurrect the deleted kennisbank.js/automation store code.

Retroactively specifies the observed behavior of 60 method(s) implementing Pinia store actions and getters. The code already exists and is exercised in the running app — this change documents its true capabilities as REQs and annotates each method with an `@spec` reference. No code logic changes.

## Affected code units

- `src/store/modules/agentProfiles.js::_countOpenRequests`
- `src/store/modules/agentProfiles.js::deleteProfile`
- `src/store/modules/agentProfiles.js::fetchProfiles`
- `src/store/modules/agentProfiles.js::getWorkload`
- `src/store/modules/agentProfiles.js::saveProfile`
- `src/store/modules/kennisbank.js::_addToRecentlyViewed`
- `src/store/modules/kennisbank.js::_fetch`
- `src/store/modules/kennisbank.js::_getApiUrl`
- `src/store/modules/kennisbank.js::articleCountsByCategory`
- `src/store/modules/kennisbank.js::autocompleteArticles`
- `src/store/modules/kennisbank.js::categoryTree`
- `src/store/modules/kennisbank.js::checkDuplicateTitle`
- `src/store/modules/kennisbank.js::createArticle`
- `src/store/modules/kennisbank.js::createCategory`
- `src/store/modules/kennisbank.js::deleteArticle`
- `src/store/modules/kennisbank.js::deleteCategory`
- `src/store/modules/kennisbank.js::fetchArticle`
- `src/store/modules/kennisbank.js::fetchArticleFeedback`
- `src/store/modules/kennisbank.js::fetchArticles`
- `src/store/modules/kennisbank.js::fetchCategories`
- `src/store/modules/kennisbank.js::publishedArticles`
- `src/store/modules/kennisbank.js::recentlyViewed`
- `src/store/modules/kennisbank.js::searchArticles`
- `src/store/modules/kennisbank.js::submitFeedback`
- `src/store/modules/kennisbank.js::updateArticle`
- `src/store/modules/kennisbank.js::updateCategory`
- `src/store/modules/kennisbank.js::visibleArticles`
- `src/store/modules/leadSources.js::addSource`
- `src/store/modules/leadSources.js::fetchSources`
- `src/store/modules/leadSources.js::removeSource`
- `src/store/modules/leadSources.js::renameSource`
- `src/store/modules/prospect.js::createLeadFromProspect`
- `src/store/modules/prospect.js::fetchProspects`
- `src/store/modules/queues.js::deleteQueue`
- `src/store/modules/queues.js::fetchQueue`
- `src/store/modules/queues.js::fetchQueueItems`
- `src/store/modules/queues.js::fetchQueues`
- `src/store/modules/queues.js::saveQueue`
- `src/store/modules/requestChannels.js::addChannel`
- `src/store/modules/requestChannels.js::fetchChannels`
- `src/store/modules/requestChannels.js::removeChannel`
- `src/store/modules/requestChannels.js::renameChannel`
- `src/store/modules/settings.js::fetchSettings`
- `src/store/modules/settings.js::saveSettings`
- `src/store/modules/skills.js::deleteSkill`
- `src/store/modules/skills.js::fetchSkills`
- `src/store/modules/skills.js::saveSkill`
- `src/store/modules/survey.js::_fetch`
- `src/store/modules/survey.js::completionRate`
- `src/store/modules/survey.js::createSurvey`
- `src/store/modules/survey.js::deleteSurvey`
- `src/store/modules/survey.js::fetchResponses`
- `src/store/modules/survey.js::fetchSurvey`
- `src/store/modules/survey.js::fetchSurveys`
- `src/store/modules/survey.js::npsScore`
- `src/store/modules/survey.js::responseCount`
- `src/store/modules/survey.js::satisfactionAverage`
- `src/store/modules/survey.js::submitPublicResponse`
- `src/store/modules/survey.js::updateSurvey`
- `src/store/store.js::initializeStores`

## Approach

- Read each implementation; extract observable inputs/outputs and domain meaning.
- Cluster methods with shared observable behavior under a small set of REQs (capability-level, observed-not-aspirational).
- Annotate each method's docblock with `@spec openspec/.../tasks.md#task-N` pointing at its task.

Reverse-spec retrofit (ADR-020). See the [retrofit playbook](../../../../.github/docs/claude/retrofit.md).
