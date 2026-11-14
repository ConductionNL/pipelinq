# Tasks: only-the-drawn-pipelines

- [x] 1.1 The repair step creates the defaults only in an empty register
  - Files: lib/Service/DefaultPipelineService.php
  - Test: tests/Unit/Service/DefaultPipelineServiceTest.php
- [x] 1.2 `occ pipelinq:pipelines:remove-mocks` removes unused mock pipelines and reports the rest
  - Files: lib/Service/MockPipelineCleanupService.php, lib/Command/RemoveMockPipelinesCommand.php, appinfo/register_command.php
  - Test: tests/Unit/Service/MockPipelineCleanupServiceTest.php, tests/Unit/Command/RemoveMockPipelinesCommandTest.php
- [ ] 1.3 Live: REFRESH-8e runs the command on :8080 and the pipeline list shows Verkoop, Vragen en meldingen and Evenementen en vergunningen
