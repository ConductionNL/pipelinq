# xwiki-proxy: retired

## REMOVED Requirements

### Requirement: xWiki search prefers OpenRegister with legacy fallback

**Reason**: pipelinq no longer proxies xWiki. Every surface reads OpenRegister's `xwiki` integration (`/apps/openregister/api/integrations/xwiki/*`), which owns the source, its credentials and its unavailable answer.

**Migration**: `kb-search` widgets and the `xwiki` integration's tab replace the proxy's callers. A direct xWiki base URL is configured as an `xwiki` source in integriq, not in pipelinq.
