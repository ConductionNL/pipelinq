<?php

/**
 * Unit tests for PipelineBoardService: the cards of one pipeline's board.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/pipeline/spec.md#requirement-kanban-board-view-mvp
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Pipelinq\Service\PipelineBoardService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PipelineBoardService.
 *
 * @spec openspec/specs/pipeline/spec.md#requirement-kanban-board-view-mvp
 */
class PipelineBoardServiceTest extends TestCase {

	/**
	 * Captured findAll() configs.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $calls = [];

	/**
	 * Build the service over fixtures keyed `<schema>` or `<schema>:<ticketType>`.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $fixtures The rows.
	 *
	 * @return PipelineBoardService
	 */
	private function service(array $fixtures): PipelineBoardService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $appId, string $key, string $default = ''): string {
				return $key === 'register' ? 'register-1' : $key;
			}
		);

		$test = $this;
		$objectService = new class($fixtures, $test) extends ObjectService {
			/**
			 * @param array<string, array<int, array<string, mixed>>> $fixtures The rows.
			 * @param PipelineBoardServiceTest                        $test     The test, to record calls.
			 */
			public function __construct(
				private array $fixtures,
				private PipelineBoardServiceTest $test,
			) {
			}

			/**
			 * @param array<string, mixed> $config        The query.
			 * @param boolean              $_rbac         Whether to enforce RBAC checks.
			 * @param boolean              $_multitenancy Whether to enforce tenant scoping.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(
				array $config = [],
				bool $_rbac = true,
				bool $_multitenancy = true,
			): array {
				$this->test->record(config: $config);
				$filters = ($config['filters'] ?? []);
				$key = (string) ($filters['schema'] ?? '');
				if (isset($filters['ticketType']) === true) {
					$key .= ':'.(string) $filters['ticketType'];
				}

				$rows = ($this->fixtures[$key] ?? []);
				if (isset($filters['id']) === true) {
					$rows = array_values(array_filter($rows, static fn (array $row): bool => ($row['id'] ?? '') === $filters['id']));
				}

				return $rows;
			}
		};

		return new PipelineBoardService(appConfig: $appConfig, objectService: $objectService);
	}//end service()

	/**
	 * Record a findAll() call.
	 *
	 * @param array<string, mixed> $config The query.
	 *
	 * @return void
	 */
	public function record(array $config): void {
		$this->calls[] = $config;
	}//end record()

	/**
	 * An unscoped pipeline shows its leads and its requests, each marked with
	 * its kind and stage property, and reads them with the caller's permissions.
	 *
	 * @return void
	 */
	public function testAnUnscopedPipelineShowsLeadsAndRequests(): void {
		$service = $this->service(
			[
				'pipeline_schema'        => [['id' => 'p1', 'title' => 'Sales']],
				'lead_schema'            => [['id' => 'l1', 'title' => 'Acme', 'stage' => 'New', 'pipeline' => 'p1']],
				'ticket_schema:request'  => [['id' => 'r1', 'title' => 'Broken link', 'stage' => 'Triage', 'pipeline' => 'p1', 'ticketType' => 'request']],
			]
		);

		$items = $service->items(pipelineId: 'p1');

		$this->assertSame(2, $items['total']);
		$this->assertSame(['lead', 'request'], array_column($items['results'], '_schemaSlug'));
		$this->assertSame(['lead', 'ticket'], array_column($items['results'], '_objectType'));
		$this->assertSame(['New', 'Triage'], array_column($items['results'], 'stage'));
		$this->assertSame('request', $items['results'][1]['ticketType']);
		foreach ($this->calls as $call) {
			$this->assertSame('register-1', $call['filters']['register']);
		}

		$this->assertSame(['pipeline' => 'p1'], array_intersect_key($this->calls[1]['filters'], ['pipeline' => true]));
	}//end testAnUnscopedPipelineShowsLeadsAndRequests()

	/**
	 * A mapped kind reads its stage from the property the mapping names.
	 *
	 * @return void
	 */
	public function testAMappingNamesTheKindsAndTheirStageProperty(): void {
		$service = $this->service(
			[
				'pipeline_schema'         => [['id' => 'p2', 'propertyMappings' => [['schemaSlug' => 'complaint', 'columnProperty' => 'phase']]]],
				'ticket_schema:complaint' => [['id' => 'c1', 'phase' => 'Intake', 'pipeline' => 'p2', 'ticketType' => 'complaint']],
				'lead_schema'             => [['id' => 'l9', 'stage' => 'New', 'pipeline' => 'p2']],
			]
		);

		$items = $service->items(pipelineId: 'p2');

		$this->assertSame(1, $items['total']);
		$this->assertSame('phase', $items['results'][0]['_columnProperty']);
		$this->assertSame('Intake', $items['results'][0]['stage']);
	}//end testAMappingNamesTheKindsAndTheirStageProperty()

	/**
	 * The older entityType scopes a pipeline to one kind or both.
	 *
	 * @return void
	 */
	public function testTheOlderEntityTypeScopesThePipeline(): void {
		$service = $this->service([]);

		$this->assertSame(['request'], $service->slugsOf(pipeline: ['entityType' => 'request']));
		$this->assertSame(['lead', 'request'], $service->slugsOf(pipeline: ['entityType' => 'both']));
		$this->assertSame(['lead', 'request'], $service->slugsOf(pipeline: []));
	}//end testTheOlderEntityTypeScopesThePipeline()

	/**
	 * Without a pipeline the default one is shown, and a chip's assignee
	 * narrows the cards on the server.
	 *
	 * @return void
	 */
	public function testTheDefaultPipelineAndAnAssigneeChip(): void {
		$service = $this->service(
			[
				'pipeline_schema' => [['id' => 'p1'], ['id' => 'p2', 'isDefault' => true]],
				'lead_schema'     => [['id' => 'l1', 'stage' => 'New', 'pipeline' => 'p2']],
			]
		);

		$items = $service->items(pipelineId: '', filters: ['assignee' => 'anna', 'ignored' => 'x']);

		$this->assertSame('p2', $items['pipeline']);
		$leadQuery = $this->calls[1]['filters'];
		$this->assertSame('anna', $leadQuery['assignee']);
		$this->assertSame('p2', $leadQuery['pipeline']);
		$this->assertArrayNotHasKey('ignored', $leadQuery);
	}//end testTheDefaultPipelineAndAnAssigneeChip()

	/**
	 * A pipeline the caller cannot read gives no board.
	 *
	 * @return void
	 */
	public function testAnUnreadablePipelineGivesNoBoard(): void {
		$service = $this->service(['pipeline_schema' => []]);

		$this->assertNull($service->items(pipelineId: 'p-hidden'));
		$this->assertNull($service->items(pipelineId: ''));
	}//end testAnUnreadablePipelineGivesNoBoard()
}//end class
