<?php

/**
 * The edges of StageLimitGuard: unreadable pipelines, sparse mappings and failing counts.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Listener;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\Pipelinq\Listener\StageLimitGuard;
use OCA\Pipelinq\Service\SchemaMapService;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * An unconfigured, unreadable or missing pipeline lets the save through;
 * a mapping without a slug is skipped and one without a column means
 * `stage`; a schema with no configured id and a failing count add nothing.
 *
 * @covers \OCA\Pipelinq\Listener\StageLimitGuard
 * @uses   \OCA\Pipelinq\AppInfo\OpenRegisterAutoloader
 */
class StageLimitGuardEdgesTest extends TestCase {
	/**
	 * OpenRegister's object service.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objects;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The filters each count() call received.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $counted = [];

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
		$this->counted = [];
	}//end setUp()

	/**
	 * Build the guard.
	 *
	 * @param array<string, string> $config App-config values.
	 *
	 * @return StageLimitGuard The guard.
	 */
	private function guard(array $config = ['register' => 'r-1', 'pipeline_schema' => 's-pipeline', 'lead_schema' => 's-lead']): StageLimitGuard {
		$schemaMap = $this->createMock(originalClassName: SchemaMapService::class);
		$schemaMap->method('resolveEntityType')->willReturnCallback(
			static fn (?string $id): ?string => ['s-lead' => 'lead'][$id] ?? null
		);
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($config[$key] ?? $default)
		);
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));

		return new StageLimitGuard(
			objectService: $this->objects,
			schemaMap: $schemaMap,
			appConfig: $appConfig,
			l10n: $l10n,
			logger: $this->logger,
		);
	}//end guard()

	/**
	 * A pipeline with the given mappings and a Proposal stage holding at most 2.
	 *
	 * @param array<int, array<string, string>> $mappings The property mappings.
	 *
	 * @return ObjectEntity The pipeline.
	 */
	private function pipeline(array $mappings): ObjectEntity {
		$pipeline = new ObjectEntity();
		$pipeline->setUuid('p-1');
		$pipeline->setObject(['propertyMappings' => $mappings, 'stages' => [['name' => 'Proposal', 'maxItems' => 2]]]);
		return $pipeline;
	}//end pipeline()

	/**
	 * Stub count() to record its filters and return the given total, or throw.
	 *
	 * @param int|\Throwable $result The count, or the error to throw.
	 *
	 * @return void
	 */
	private function stubCount(int|\Throwable $result): void {
		$this->objects->method('count')->willReturnCallback(
			function (array $config) use ($result): int {
				$this->counted[] = $config['filters'];
				if ($result instanceof \Throwable) {
					throw $result;
				}

				return $result;
			}
		);
	}//end stubCount()

	/**
	 * Create a lead in the given stage and return the event.
	 *
	 * @param StageLimitGuard      $guard The guard.
	 * @param array<string, mixed> $data  The lead data.
	 *
	 * @return ObjectCreatingEvent The handled event.
	 */
	private function create(StageLimitGuard $guard, array $data = ['pipeline' => 'p-1', 'stage' => 'Proposal']): ObjectCreatingEvent {
		$lead = new ObjectEntity();
		$lead->setSchema('s-lead');
		$lead->setObject($data);
		$event = new ObjectCreatingEvent(object: $lead);
		$guard->handle(event: $event);
		return $event;
	}//end create()

	/**
	 * Without a pipeline schema configured nothing is read and the save passes.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredPipelineSchemaLetsTheSaveThrough(): void {
		$this->objects->expects($this->never())->method('find');

		$event = $this->create(guard: $this->guard(config: ['register' => 'r-1', 'lead_schema' => 's-lead']));

		$this->assertFalse($event->isPropagationStopped());
	}//end testAnUnconfiguredPipelineSchemaLetsTheSaveThrough()

	/**
	 * An unreadable pipeline is logged and the save passes.
	 *
	 * @return void
	 */
	public function testAnUnreadablePipelineIsLoggedAndLetsTheSaveThrough(): void {
		$this->objects->method('find')->willThrowException(new \RuntimeException('denied'));
		$this->objects->expects($this->never())->method('count');
		$this->logger->expects($this->once())->method('warning')->with(
			'StageLimitGuard: pipeline not readable',
			['pipeline' => 'p-1', 'exception' => 'denied']
		);

		$event = $this->create(guard: $this->guard());

		$this->assertFalse($event->isPropagationStopped());
	}//end testAnUnreadablePipelineIsLoggedAndLetsTheSaveThrough()

	/**
	 * A missing pipeline lets the save through without counting.
	 *
	 * @return void
	 */
	public function testAMissingPipelineLetsTheSaveThrough(): void {
		$this->objects->method('find')->willReturn(null);
		$this->objects->expects($this->never())->method('count');

		$event = $this->create(guard: $this->guard());

		$this->assertFalse($event->isPropagationStopped());
	}//end testAMissingPipelineLetsTheSaveThrough()

	/**
	 * A mapping without a slug is skipped; one without a column counts on `stage`.
	 *
	 * @return void
	 */
	public function testSparseMappingsDefaultTheColumnAndSkipMissingSlugs(): void {
		$this->objects->method('find')->willReturn($this->pipeline(mappings: [['columnProperty' => 'phase'], ['schemaSlug' => 'lead']]));
		$this->stubCount(result: 2);

		$event = $this->create(guard: $this->guard());

		$this->assertSame(expected: [['schema' => 's-lead', 'register' => 'r-1', 'pipeline' => 'p-1', 'stage' => 'Proposal']], actual: $this->counted);
		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(expected: 'Stage Proposal is full (2 of 2)', actual: $event->getErrors()['message']);
	}//end testSparseMappingsDefaultTheColumnAndSkipMissingSlugs()

	/**
	 * A mapped schema with no configured id adds nothing to the count.
	 *
	 * @return void
	 */
	public function testAMappedSchemaWithoutAConfiguredIdIsNotCounted(): void {
		$this->objects->method('find')->willReturn($this->pipeline(mappings: [['schemaSlug' => 'lead'], ['schemaSlug' => 'complaint']]));
		$this->stubCount(result: 1);

		$event = $this->create(guard: $this->guard());

		$this->assertSame(expected: [['schema' => 's-lead', 'register' => 'r-1', 'pipeline' => 'p-1', 'stage' => 'Proposal']], actual: $this->counted);
		$this->assertFalse($event->isPropagationStopped());
	}//end testAMappedSchemaWithoutAConfiguredIdIsNotCounted()

	/**
	 * A failing count is logged, counts as zero and lets the save through.
	 *
	 * @return void
	 */
	public function testAFailingCountIsLoggedAndCountsAsZero(): void {
		$this->objects->method('find')->willReturn($this->pipeline(mappings: [['schemaSlug' => 'lead', 'columnProperty' => 'stage']]));
		$this->stubCount(result: new \RuntimeException('index down'));
		$this->logger->expects($this->once())->method('warning')->with(
			'StageLimitGuard: counting a stage failed',
			['stage' => 'Proposal', 'exception' => 'index down']
		);

		$event = $this->create(guard: $this->guard());

		$this->assertFalse($event->isPropagationStopped());
	}//end testAFailingCountIsLoggedAndCountsAsZero()
}//end class
