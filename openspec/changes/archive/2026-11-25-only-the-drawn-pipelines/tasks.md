# Tasks: only-the-drawn-pipelines

- [x] 1.1 The repair step creates the defaults only in an empty register
  - Files: lib/Service/DefaultPipelineService.php
  - Test: tests/Unit/Service/DefaultPipelineServiceTest.php
- [x] 1.2 `occ pipelinq:pipelines:remove-mocks` removes unused mock pipelines and reports the rest
  - Files: lib/Service/MockPipelineCleanupService.php, lib/Command/RemoveMockPipelinesCommand.php, appinfo/register_command.php
  - Test: tests/Unit/Service/MockPipelineCleanupServiceTest.php, tests/Unit/Command/RemoveMockPipelinesCommandTest.php
- [x] 1.3 Live: REFRESH-8e runs the command on :8080 and the pipeline list shows Verkoop, Vragen en meldingen and Evenementen en vergunningen
  - Live 25 Nov 2026 (sandbox NC 34 + MariaDB + OpenRegister main, Zuiddrecht set installed: 'Every declared account, group and object is on this instance.'): `remove-mocks --dry-run` would remove only the seeded "Sales"; the run removed it (1 removed, 0 kept, 0 refused) and warned that Verkoop and Vragen en meldingen are both marked default (the set marks the lead and the request board). The pipeline list then reads Verkoop, Vragen en meldingen, Evenementen en vergunningen (total 3).
