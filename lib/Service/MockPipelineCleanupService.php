<?php

/**
 * Pipelinq MockPipelineCleanupService.
 *
 * Removes the mock pipelines an instance collected before it was given real
 * ones: the shipped defaults, the demo seed's and the mock register's.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/only-the-drawn-pipelines/specs/pipeline/spec.md#requirement-mock-pipelines-can-be-removed-when-nothing-is-on-them
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Finds the known mock pipelines and removes each one no lead or ticket is on.
 *
 * A pipeline counts as a mock by its slug (the mock register's) or by its exact
 * title and description (the shipped defaults and the demo seed's, which carry
 * no slug). A renamed or re-described copy is no longer a mock and is left alone.
 *
 * @spec openspec/changes/only-the-drawn-pipelines/specs/pipeline/spec.md#requirement-mock-pipelines-can-be-removed-when-nothing-is-on-them
 */
class MockPipelineCleanupService {
	/**
	 * Slugs of the mock register's pipelines (lib/Settings/pipelinq_mock_register.json).
	 */
	public const MOCK_SLUGS = [
		'pipeline-voorbeeld-title-1-1',
		'pipeline-voorbeeld-title-2-2',
		'pipeline-voorbeeld-title-3-3',
	];

	/**
	 * Title and description of the mock pipelines that carry no slug: the demo
	 * seed's (lib/Settings/demo_seed_data.json) and the mock register's.
	 * The shipped defaults are added from PipelineStageData.
	 */
	private const MOCK_TITLES = [
		['[Demo] Sales pipeline', 'Demo pipeline: standard commercial stages.'],
		['[Demo] Service pipeline', 'Demo pipeline: service-desk handling stages.'],
		['Voorbeeld Title 1', 'Voorbeeld Description 1'],
		['Voorbeeld Title 2', 'Voorbeeld Description 2'],
		['Voorbeeld Title 3', 'Voorbeeld Description 3'],
	];

	/**
	 * The objects that sit on a pipeline, by the app config key of their schema.
	 */
	private const USER_SCHEMA_KEYS = ['lead' => 'lead_schema', 'ticket' => 'ticket_schema'];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig             $appConfig     The app config.
	 * @param PipelineStageData      $stageData     The shipped default pipelines.
	 * @param ObjectServiceInterface $objectService OpenRegister's published object service.
	 * @param SystemServiceAccount   $systemAccount The account the writes run as when nobody is signed in.
	 * @param LoggerInterface        $logger        The logger.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly PipelineStageData $stageData,
		private readonly ObjectServiceInterface $objectService,
		private readonly SystemServiceAccount $systemAccount,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Remove every mock pipeline no lead or ticket is on, and report the rest.
	 *
	 * The usage count reads past RBAC and organisation scoping on purpose: a
	 * lead hidden from the acting account still sits on the pipeline, and must
	 * keep it. The delete itself keeps OpenRegister's checks.
	 *
	 * @param bool $dryRun Report what would be removed without removing it.
	 *
	 * @return array<string, list<array<string, mixed>>> `removed`, `kept` (with `usedBy`), `failed` and `defaults`,
	 *                                                  each a list of pipelines by `id` and `title`.
	 *
	 * @throws RuntimeException When the register, the pipeline schema or a schema objects sit on is not configured.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $dryRun is the command's --dry-run option.
	 *
	 * @spec openspec/changes/only-the-drawn-pipelines/specs/pipeline/spec.md#requirement-mock-pipelines-can-be-removed-when-nothing-is-on-them
	 */
	public function run(bool $dryRun=false): array {
		return $this->systemAccount->runAsCurrentOrSystem(fn (): array => $this->runAsCaller(dryRun: $dryRun));
	}//end run()

	/**
	 * Whether a pipeline is one of the known mocks.
	 *
	 * @param array<string, mixed> $pipeline The pipeline object.
	 *
	 * @return bool True for a known mock pipeline.
	 *
	 * @spec openspec/changes/only-the-drawn-pipelines/specs/pipeline/spec.md#requirement-mock-pipelines-can-be-removed-when-nothing-is-on-them
	 */
	public function isMock(array $pipeline): bool {
		$slug = (string) ($pipeline['@self']['slug'] ?? $pipeline['slug'] ?? '');
		if (in_array($slug, self::MOCK_SLUGS, true) === true) {
			return true;
		}

		$pair = [(string) ($pipeline['title'] ?? ''), (string) ($pipeline['description'] ?? '')];

		return in_array($pair, $this->mockTitles(), true);
	}//end isMock()

	/**
	 * Remove the unused mocks as the acting account.
	 *
	 * @param bool $dryRun Report without removing.
	 *
	 * @return array<string, list<array<string, mixed>>> `removed`, `kept` (with `usedBy`), `failed` and `defaults`,
	 *                                                  each a list of pipelines by `id` and `title`.
	 *
	 * @throws RuntimeException When the register or a schema is not configured.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $dryRun is the command's --dry-run option.
	 */
	private function runAsCaller(bool $dryRun): array {
		$registerId = $this->config(key: 'register');
		$schemaId   = $this->config(key: 'pipeline_schema');
		$userSchemas = array_map(fn (string $key): string => $this->config(key: $key), self::USER_SCHEMA_KEYS);

		$report = ['removed' => [], 'kept' => [], 'failed' => [], 'defaults' => []];
		$found  = $this->objectService->findAll(
			['filters' => ['register' => $registerId, 'schema' => $schemaId], 'limit' => 1000]
		);

		$where = ['register' => $registerId, 'schema' => $schemaId, 'objectSchemas' => $userSchemas];
		foreach ($found as $result) {
			$outcome = $this->handle(pipeline: $this->rowOf(result: $result), where: $where, dryRun: $dryRun);
			if ($outcome['bucket'] !== null) {
				$report[$outcome['bucket']][] = $outcome['entry'];
			}

			if ($outcome['default'] === true) {
				$report['defaults'][] = ['id' => $outcome['entry']['id'], 'title' => $outcome['entry']['title']];
			}
		}

		return $report;
	}//end runAsCaller()

	/**
	 * What happens to one pipeline: left alone, kept because objects are on it, removed, or refused.
	 *
	 * @param array<string, mixed>                                                            $pipeline The pipeline object.
	 * @param array{register: string, schema: string, objectSchemas: array<string, string>} $where    Register, pipeline schema and object schemas.
	 * @param bool                                                                            $dryRun   Report without removing.
	 *
	 * @return array{bucket: ?string, entry: array<string, mixed>, default: bool} The report bucket (null: not a mock),
	 *                                                                          the pipeline's entry and whether it stays a default.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $dryRun is the command's --dry-run option.
	 */
	private function handle(array $pipeline, array $where, bool $dryRun): array {
		$entry     = ['id' => (string) ($pipeline['id'] ?? $pipeline['@self']['id'] ?? ''), 'title' => (string) ($pipeline['title'] ?? '')];
		$isDefault = (($pipeline['isDefault'] ?? false) === true);

		if ($this->isMock(pipeline: $pipeline) === false || $entry['id'] === '') {
			return ['bucket' => null, 'entry' => $entry, 'default' => $isDefault];
		}

		$usedBy = $this->usedBy(registerId: $where['register'], schemaIds: $where['objectSchemas'], pipelineId: $entry['id']);
		if ($usedBy !== []) {
			return ['bucket' => 'kept', 'entry' => $entry + ['usedBy' => $usedBy], 'default' => $isDefault];
		}

		if ($dryRun === true) {
			return ['bucket' => 'removed', 'entry' => $entry, 'default' => false];
		}

		if ($this->objectService->deleteObject($entry['id'], $where['register'], $where['schema']) === false) {
			return ['bucket' => 'failed', 'entry' => $entry, 'default' => $isDefault];
		}

		$this->logger->info('Pipelinq: removed mock pipeline '.$entry['title'], ['id' => $entry['id']]);

		return ['bucket' => 'removed', 'entry' => $entry, 'default' => false];
	}//end handle()

	/**
	 * Which kinds of object (lead, ticket) sit on a pipeline.
	 *
	 * @param string                $registerId The register.
	 * @param array<string, string> $schemaIds  The schema id per kind of object.
	 * @param string                $pipelineId The pipeline's id.
	 *
	 * @return list<string> The kinds with at least one object on the pipeline.
	 */
	private function usedBy(string $registerId, array $schemaIds, string $pipelineId): array {
		$kinds = [];
		foreach ($schemaIds as $kind => $schemaId) {
			$objects = $this->objectService->findAll(
				['filters' => ['register' => $registerId, 'schema' => $schemaId, 'pipeline' => $pipelineId], 'limit' => 1],
				_rbac: false,
				_multitenancy: false
			);
			if (count($objects) > 0) {
				$kinds[] = $kind;
			}
		}

		return $kinds;
	}//end usedBy()

	/**
	 * The title and description pairs that mark a mock pipeline.
	 *
	 * @return list<array{0: string, 1: string}> The pairs.
	 */
	private function mockTitles(): array {
		$pairs = self::MOCK_TITLES;
		foreach ([$this->stageData->getSalesPipelineData(), $this->stageData->getServiceRequestsPipelineData()] as $shipped) {
			$pairs[] = [(string) $shipped['title'], (string) $shipped['description']];
		}

		return $pairs;
	}//end mockTitles()

	/**
	 * A configured id, or a refusal when it is not set.
	 *
	 * @param string $key The app config key.
	 *
	 * @return string The configured value.
	 *
	 * @throws RuntimeException When the key is not set.
	 */
	private function config(string $key): string {
		$value = $this->appConfig->getValueString(Application::APP_ID, $key, '');
		if ($value === '') {
			throw new RuntimeException('Pipelinq is not configured: '.$key.' is empty');
		}

		return $value;
	}//end config()

	/**
	 * An object or array result as an array.
	 *
	 * @param mixed $result The result.
	 *
	 * @return array<string, mixed> The result as an array.
	 */
	private function rowOf(mixed $result): array {
		if (is_object($result) === true && method_exists($result, 'jsonSerialize') === true) {
			return (array) $result->jsonSerialize();
		}

		return (array) $result;
	}//end rowOf()
}//end class
