# navigation-ia delta: nextcloud-vue-2-73-1

## ADDED Requirements

### Requirement: The appVersion define comes from the shared library (REQ-NIA-109)
pipelinq's webpack config MUST define `appVersion` with `appVersionDefine`
from `@conduction/nextcloud-vue/webpack`, with the app id `pipelinq` and the
`appinfo/info.xml` version as the fallback. pipelinq MUST NOT carry its own
copy of that helper.

#### Scenario: The webpack config builds the define
@e2e exclude Asserted in tests/vitest/appVersionDefine.spec.js: the library export is replaced by a spy and the config is loaded fresh.
- **GIVEN** pipelinq depends on @conduction/nextcloud-vue 2.73.1 or later
- **WHEN** webpack loads `webpack.config.js`
- **THEN** the config MUST call the library's `appVersionDefine('pipelinq', <info.xml version>)`
- **AND** the user settings footer MUST read the installed version, as REQ-NIA-108 asks
