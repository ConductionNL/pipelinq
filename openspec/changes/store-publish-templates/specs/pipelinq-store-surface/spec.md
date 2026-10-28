# pipelinq-store-surface: delta for store-publish-templates

## ADDED Requirements

### Requirement: An administrator publishes a template from local configuration

Pipelinq MUST list the local configuration objects a store item of a kind may carry. It MUST publish an item whose components are copies of the chosen objects without their identity, to the configured registry, and only for a user OpenRegister's `canPublish()` admits. An object outside the kind, or one that is not on this instance, MUST refuse the publish.

#### Scenario: publish the Woo pipeline

@e2e exclude Asserted in tests/Unit/Service/Store/StoreTemplateAssemblerTest.php and tests/Unit/Controller/StoreControllerTest.php; the dialog is covered by nextcloud-vue's CnStorePagePublish test.

- GIVEN the pipeline "Woo-vragen" on this instance and the store's registry is `local`
- WHEN an administrator publishes "Pipeline voor Woo-vragen" 1.0.0 of kind pipeline with that pipeline chosen
- THEN the registry holds a commercial-template "Pipeline voor Woo-vragen" whose one component is the pipeline without its id or owner

#### Scenario: someone who may not publish

@e2e exclude Asserted in tests/Unit/Controller/StoreControllerTest.php.

- GIVEN a user who is not an administrator
- WHEN they publish
- THEN the answer is 403 and nothing is sent
