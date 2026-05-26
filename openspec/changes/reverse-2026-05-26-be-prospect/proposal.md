# Reverse-spec — Prospect discovery and ICP

Retroactively specifies the observed behavior of 15 method(s) implementing prospect discovery, ICP configuration and external company lookups. The code already exists and is exercised in the running app — this change documents its true capabilities as REQs and annotates each method with an `@spec` reference. No code logic changes.

## Affected code units

- `lib/Controller/ProspectController.php::index`
- `lib/Service/IcpConfigReader.php::getJsonArray`
- `lib/Service/IcpConfigReader.php::getString`
- `lib/Service/IcpConfigReader.php::setBool`
- `lib/Service/IcpConfigReader.php::setJsonArray`
- `lib/Service/IcpConfigReader.php::setString`
- `lib/Service/IcpConfigService.php::getCriteria`
- `lib/Service/IcpConfigService.php::getIcpHash`
- `lib/Service/IcpConfigService.php::getSettings`
- `lib/Service/IcpConfigService.php::isConfigured`
- `lib/Service/IcpConfigService.php::saveSettings`
- `lib/Service/KvkResultMapper.php::mapResult`
- `lib/Service/OpenCorporatesResultMapper.php::mapResult`
- `lib/Service/ProspectDiscoveryService.php::createLeadFromProspect`
- `lib/Service/ProspectDiscoveryService.php::discover`

## Approach

- Read each implementation; extract observable inputs/outputs and domain meaning.
- Cluster methods with shared observable behavior under a small set of REQs (capability-level, observed-not-aspirational).
- Annotate each method's docblock with `@spec openspec/.../tasks.md#task-N` pointing at its task.

Reverse-spec retrofit (ADR-020). See the [retrofit playbook](../../../../.github/docs/claude/retrofit.md).
