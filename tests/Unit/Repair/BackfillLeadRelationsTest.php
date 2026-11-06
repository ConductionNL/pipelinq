<?php

/**
 * Unit tests for the BackfillLeadRelations repair step.
 *
 * `lead.pipeline` is required, and OpenRegister validates required on every
 * save, so a lead without a pipeline cannot be edited at all until it is
 * backfilled. `lead.client` is optional (decision 207, 12 October 2026). These
 * tests cover what the step has to get right:
 *
 *   - a lead missing a pipeline is put on the default pipeline
 *   - a lead without a client STAYS without one: the step used to match or
 *     create a client for it, and doing that now would link a deal to a
 *     customer nobody chose
 *   - a lead that has a pipeline and a stage is left untouched, client or not,
 *     so a re-run is a no-op
 *
 * The fakes mirror the REAL OpenRegister ObjectService signatures
 * (`findAll(array $config, bool $_rbac, bool $_multitenancy)` and
 * `saveObject(object:, extend:, register:, schema:, uuid:, _rbac:, ...)`),
 * not the shape this caller happens to use. A fake written from the call site
 * agrees with the caller's mistakes and cannot fail.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/lead-management/spec.md#requirement-lead-crud-mvp
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Repair;

use JsonSerializable;
use OCA\Pipelinq\Repair\BackfillLeadRelations;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * In-memory ObjectService double matching OpenRegister's real signatures.
 */
class FakeBackfillObjectService {

	/**
	 * Stored objects keyed by schema then id.
	 *
	 * @var array<string, array<string, array<string,mixed>>>
	 */
	public array $store = [];

	/**
	 * Every saveObject() call, for asserting what the step wrote.
	 *
	 * @var array<int, array<string,mixed>>
	 */
	public array $saves = [];

	/**
	 * Filter-aware lookup mirroring ObjectService::findAll().
	 *
	 * @param array<string,mixed> $config        Config carrying `filters` and `limit`.
	 * @param bool                $_rbac         Ignored by the fake.
	 * @param bool                $_multitenancy Ignored by the fake.
	 *
	 * @return array<int, array<string,mixed>> Matching rows.
	 */
	public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
		$schema = (string)($config['filters']['schema'] ?? '');

		return array_values($this->store[$schema] ?? []);
	}//end findAll()

	/**
	 * Record and apply a save, mirroring ObjectService::saveObject().
	 *
	 * @param array<string,mixed> $object        The object data.
	 * @param array|null          $extend        Ignored by the fake.
	 * @param string|null         $register      Register id.
	 * @param string|null         $schema        Schema id.
	 * @param string|null         $uuid          Existing uuid, or null to create.
	 * @param bool                $_rbac         Ignored by the fake.
	 * @param bool                $_multitenancy Ignored by the fake.
	 * @param bool                $silent        Ignored by the fake.
	 * @param bool                $_validation   Ignored by the fake.
	 * @param array|null          $uploadedFiles Ignored by the fake.
	 * @param mixed               $currentUser   Ignored by the fake.
	 *
	 * @return array<string,mixed> The stored object.
	 */
	public function saveObject(
		array $object,
		?array $extend = [],
		?string $register = null,
		?string $schema = null,
		?string $uuid = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
		bool $silent = false,
		bool $_validation = true,
		?array $uploadedFiles = null,
		mixed $currentUser = null,
	): array {
		$id = ($uuid ?? ($object['id'] ?? 'new'));
		$object['id'] = $id;
		$this->store[(string)$schema][$id] = $object;
		$this->saves[] = $object;

		return $object;
	}//end saveObject()
}//end class

/**
 * Tests for BackfillLeadRelations.
 *
 * @covers \OCA\Pipelinq\Repair\BackfillLeadRelations
 * @uses   \OCA\Pipelinq\Service\LeadStagePlacer
 */
class BackfillLeadRelationsTest extends TestCase {

	/**
	 * The fake object store.
	 *
	 * @var FakeBackfillObjectService
	 */
	private FakeBackfillObjectService $objects;

	/**
	 * Build the step with the given leads, clients and pipelines.
	 *
	 * @param array<int,array<string,mixed>> $leads     Lead rows.
	 * @param array<int,array<string,mixed>> $clients   Client rows.
	 * @param array<int,array<string,mixed>> $pipelines Pipeline rows.
	 *
	 * @return BackfillLeadRelations The step under test.
	 */
	private function makeStep(array $leads, array $clients, array $pipelines): BackfillLeadRelations {
		$this->objects = new FakeBackfillObjectService();
		foreach ($leads as $row) {
			$this->objects->store['lead-schema'][$row['id']] = $row;
		}

		foreach ($clients as $row) {
			$this->objects->store['client-schema'][$row['id']] = $row;
		}

		foreach ($pipelines as $row) {
			$this->objects->store['pipeline-schema'][$row['id']] = $row;
		}

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				return match ($key) {
					'register' => 'reg',
					'lead_schema' => 'lead-schema',
					'client_schema' => 'client-schema',
					'pipeline_schema' => 'pipeline-schema',
					default => $default,
				};
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->objects);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('get')->willReturn(null);

		return new BackfillLeadRelations(
			$appConfig,
			$container,
			$groupManager,
			$this->createMock(LoggerInterface::class),
		);
	}//end makeStep()

	/**
	 * The saved version of one lead.
	 *
	 * @param string $id The lead id.
	 *
	 * @return array<string,mixed>|null The saved row, or null when never saved.
	 */
	private function saved(string $id): ?array {
		foreach ($this->objects->saves as $save) {
			if (($save['id'] ?? '') === $id) {
				return $save;
			}
		}

		return null;
	}//end saved()

	/**
	 * A lead with no pipeline is placed on the default one.
	 *
	 * @return void
	 */
	public function testAssignsTheDefaultPipeline(): void {
		$step = $this->makeStep(
			leads: [['id' => 'l1', 'title' => 'Acme deal', 'client' => 'c1']],
			clients: [['id' => 'c1', 'name' => 'Acme']],
			pipelines: [
				['id' => 'p-other', 'title' => 'Other'],
				['id' => 'p-default', 'title' => 'Sales', 'isDefault' => true],
			]
		);

		$step->run($this->createMock(IOutput::class));

		$this->assertSame('p-default', $this->saved('l1')['pipeline'] ?? null);
	}//end testAssignsTheDefaultPipeline()

	/**
	 * A lead without a client keeps none, and no client is made for it.
	 *
	 * The lead lacks a pipeline too, so the step does save it: that is what
	 * makes this able to fail. A lead the step never saves would pass whether
	 * or not a client leg existed.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/lead-management/spec.md#requirement-lead-crud-mvp
	 */
	public function testALeadWithoutAClientKeepsNone(): void {
		$step = $this->makeStep(
			leads: [['id' => 'l1', 'title' => 'Gemeente Amsterdam - CRM 2026']],
			// A client whose name occurs in the title: the old step linked it.
			clients: [['id' => 'c-ams', 'name' => 'Gemeente Amsterdam']],
			pipelines: [['id' => 'p1', 'title' => 'Sales', 'isDefault' => true]]
		);

		$step->run($this->createMock(IOutput::class));

		$saved = $this->saved('l1');
		$this->assertSame('p1', $saved['pipeline'] ?? null, 'control: the step did save this lead');
		$this->assertArrayNotHasKey('client', $saved, 'no client may be linked to a lead that has none');
		$this->assertSame(['c-ams'], array_keys($this->objects->store['client-schema']), 'no client may be created');
	}//end testALeadWithoutAClientKeepsNone()

	/**
	 * A lead with a pipeline and no client is complete: it is not saved again.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/lead-management/spec.md#requirement-lead-crud-mvp
	 */
	public function testALeadWithoutAClientIsComplete(): void {
		$step = $this->makeStep(
			leads: [['id' => 'l1', 'title' => 'Totally New BV', 'pipeline' => 'p1']],
			clients: [],
			pipelines: [['id' => 'p1', 'title' => 'Sales']]
		);

		$output = $this->createMock(IOutput::class);
		$output->expects($this->never())->method('warning');
		$step->run($output);

		$this->assertSame([], $this->objects->saves, 'a lead without a client must not be saved again');
	}//end testALeadWithoutAClientIsComplete()

	/**
	 * A lead on a pipeline without a stage is put in the first open stage.
	 *
	 * Its entry time is the lead's creation, so the repair does not restart aging.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pipeline-numbers-tell-the-truth/specs/lead-management/spec.md
	 */
	public function testPlacesAStagelessLeadInTheFirstOpenStage(): void {
		$step = $this->makeStep(
			leads: [[
				'id' => 'l1',
				'title' => 'Acme deal',
				'client' => 'c1',
				'pipeline' => 'p1',
				'@self' => ['created' => '2026-09-01T08:00:00+00:00'],
			]],
			clients: [['id' => 'c1', 'name' => 'Acme']],
			pipelines: [[
				'id' => 'p1',
				'title' => 'Sales',
				'stages' => [
					['name' => 'Lost', 'order' => 0, 'isClosed' => true],
					['name' => 'Qualified', 'order' => 3],
					['name' => 'New', 'order' => 1],
				],
			]]
		);

		$step->run($this->createMock(IOutput::class));

		$saved = $this->saved('l1');
		$this->assertSame('New', $saved['stage'] ?? null);
		$this->assertSame(1, $saved['stageOrder'] ?? null);
		$this->assertSame('2026-09-01T08:00:00+00:00', $saved['stageEnteredAt'] ?? null);
	}//end testPlacesAStagelessLeadInTheFirstOpenStage()

	/**
	 * A complete lead is not rewritten, so a re-run is a no-op.
	 *
	 * @return void
	 */
	public function testLeavesCompleteLeadsUntouched(): void {
		$step = $this->makeStep(
			leads: [['id' => 'l1', 'title' => 'Done', 'pipeline' => 'p1', 'client' => 'c1']],
			clients: [['id' => 'c1', 'name' => 'Acme']],
			pipelines: [['id' => 'p1', 'title' => 'Sales']]
		);

		$step->run($this->createMock(IOutput::class));

		$this->assertSame([], $this->objects->saves, 'a complete lead must not be saved again');
	}//end testLeavesCompleteLeadsUntouched()

	/**
	 * Build the step around a given object service and configuration.
	 *
	 * @param object|null          $objectService The ObjectService double, or null when the container cannot supply one.
	 * @param array<string,string> $config        App config values by key.
	 * @param LoggerInterface|null $logger        Logger, when a test reads what was logged.
	 *
	 * @return BackfillLeadRelations The step under test.
	 */
	private function makeStepAround(?object $objectService, array $config, ?LoggerInterface $logger = null): BackfillLeadRelations {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($config): string {
				return ($config[$key] ?? $default);
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		if ($objectService === null) {
			$container->method('get')->willThrowException(new RuntimeException('openregister is not installed'));
		} else {
			$container->method('get')->willReturn($objectService);
		}

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('get')->willReturn(null);

		return new BackfillLeadRelations(
			$appConfig,
			$container,
			$groupManager,
			($logger ?? $this->createMock(LoggerInterface::class)),
		);
	}//end makeStepAround()

	/**
	 * The configuration of an installed pipelinq.
	 *
	 * @return array<string,string> App config values by key.
	 */
	private function configured(): array {
		return [
			'register' => 'reg',
			'lead_schema' => 'lead-schema',
			'pipeline_schema' => 'pipeline-schema',
		];
	}//end configured()

	/**
	 * Collect what the step tells the admin.
	 *
	 * @param array<int,string> $infos    Filled with every info line.
	 * @param array<int,string> $warnings Filled with every warning line.
	 *
	 * @return IOutput The output double.
	 */
	private function recordingOutput(array &$infos, array &$warnings): IOutput {
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(
			static function (string $message) use (&$infos): void {
				$infos[] = $message;
			}
		);
		$output->method('warning')->willReturnCallback(
			static function (string $message) use (&$warnings): void {
				$warnings[] = $message;
			}
		);

		return $output;
	}//end recordingOutput()

	/**
	 * Without a configured register the step does nothing and says so.
	 *
	 * @return void
	 */
	public function testSkipsWhenTheRegisterIsNotConfigured(): void {
		$objects = new FakeBackfillObjectService();
		$objects->store['lead-schema']['l1'] = ['id' => 'l1', 'title' => 'Orphan'];
		$step = $this->makeStepAround(objectService: $objects, config: ['lead_schema' => 'lead-schema']);
		$infos = [];
		$warnings = [];

		$step->run($this->recordingOutput($infos, $warnings));

		$this->assertSame([], $objects->saves);
		$this->assertCount(1, $infos);
		$this->assertStringContainsString('not configured', $infos[0]);
	}//end testSkipsWhenTheRegisterIsNotConfigured()

	/**
	 * Without OpenRegister the step warns and stops instead of failing the upgrade.
	 *
	 * @return void
	 */
	public function testSkipsWhenOpenRegisterIsUnavailable(): void {
		$step = $this->makeStepAround(objectService: null, config: $this->configured());
		$infos = [];
		$warnings = [];

		$step->run($this->recordingOutput($infos, $warnings));

		$this->assertSame([], $infos);
		$this->assertCount(1, $warnings);
		$this->assertStringContainsString('openregister is not installed', $warnings[0]);
	}//end testSkipsWhenOpenRegisterIsUnavailable()

	/**
	 * A failing read is logged and reads as "no leads", never as an exception.
	 *
	 * @return void
	 */
	public function testAFailingReadIsLoggedAndLeavesNothingToDo(): void {
		$objects = new class() extends FakeBackfillObjectService {

			/**
			 * A read that fails, as OpenRegister's does on a missing schema.
			 *
			 * @param array<string,mixed> $config        Ignored.
			 * @param bool                $_rbac         Ignored.
			 * @param bool                $_multitenancy Ignored.
			 *
			 * @return array<int, array<string,mixed>> Never returns.
			 */
			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				throw new RuntimeException('schema not found');
			}//end findAll()
		};
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'BackfillLeadRelations: findAll failed',
			['schema' => 'lead-schema', 'error' => 'schema not found']
		);
		$step = $this->makeStepAround(objectService: $objects, config: $this->configured(), logger: $logger);
		$infos = [];
		$warnings = [];

		$step->run($this->recordingOutput($infos, $warnings));

		$this->assertSame(['BackfillLeadRelations: no leads to inspect'], $infos);
		$this->assertSame([], $objects->saves);
	}//end testAFailingReadIsLoggedAndLeavesNothingToDo()

	/**
	 * With no pipeline at all, a lead without one stays stuck and the admin is told.
	 *
	 * @return void
	 */
	public function testALeadWithNoPipelineToAssignIsReportedStuck(): void {
		$objects = new FakeBackfillObjectService();
		$objects->store['lead-schema']['l1'] = ['id' => 'l1', 'title' => 'Orphan'];
		// No pipeline schema configured: there is nothing to assign.
		$step = $this->makeStepAround(objectService: $objects, config: ['register' => 'reg', 'lead_schema' => 'lead-schema']);
		$infos = [];
		$warnings = [];

		$step->run($this->recordingOutput($infos, $warnings));

		$this->assertSame([], $objects->saves);
		$this->assertCount(2, $warnings);
		$this->assertStringContainsString('could not resolve a pipeline for lead "Orphan"', $warnings[0]);
		$this->assertStringContainsString('1 lead(s) still lack a pipeline', $warnings[1]);
		$this->assertStringContainsString('0 lead(s) backfilled, 0 already complete, 1 still unresolved', $infos[0]);
	}//end testALeadWithNoPipelineToAssignIsReportedStuck()

	/**
	 * A save OpenRegister refuses is logged and counted as stuck; the next lead still runs.
	 *
	 * @return void
	 */
	public function testARefusedSaveIsCountedAsStuckAndTheRunGoesOn(): void {
		$objects = new class() extends FakeBackfillObjectService {

			/**
			 * Refuse the first lead, accept the rest.
			 *
			 * @param array<string,mixed> $object        The object data.
			 * @param array|null          $extend        Ignored.
			 * @param string|null         $register      Register id.
			 * @param string|null         $schema        Schema id.
			 * @param string|null         $uuid          The uuid.
			 * @param bool                $_rbac         Ignored.
			 * @param bool                $_multitenancy Ignored.
			 * @param bool                $silent        Ignored.
			 * @param bool                $_validation   Ignored.
			 * @param array|null          $uploadedFiles Ignored.
			 * @param mixed               $currentUser   Ignored.
			 *
			 * @return array<string,mixed> The stored object.
			 */
			public function saveObject(
				array $object,
				?array $extend = [],
				?string $register = null,
				?string $schema = null,
				?string $uuid = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
				bool $silent = false,
				bool $_validation = true,
				?array $uploadedFiles = null,
				mixed $currentUser = null,
			): array {
				if ($uuid === 'l1') {
					throw new RuntimeException('The required property (title) is missing');
				}

				return parent::saveObject($object, $extend, $register, $schema, $uuid);
			}//end saveObject()
		};
		$objects->store['lead-schema']['l1'] = ['id' => 'l1'];
		$objects->store['lead-schema']['l2'] = ['id' => 'l2', 'title' => 'Second'];
		$objects->store['pipeline-schema']['p1'] = ['id' => 'p1', 'title' => 'Sales'];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'BackfillLeadRelations: failed to save lead',
			['uuid' => 'l1', 'error' => 'The required property (title) is missing']
		);
		$step = $this->makeStepAround(objectService: $objects, config: $this->configured(), logger: $logger);
		$infos = [];
		$warnings = [];

		$step->run($this->recordingOutput($infos, $warnings));

		$this->assertCount(1, $objects->saves);
		$this->assertSame('l2', $objects->saves[0]['id']);
		$this->assertSame('p1', $objects->saves[0]['pipeline']);
		$this->assertStringContainsString('1 lead(s) backfilled, 0 already complete, 1 still unresolved', $infos[0]);
		$this->assertCount(1, $warnings);
	}//end testARefusedSaveIsCountedAsStuckAndTheRunGoesOn()

	/**
	 * With no default pipeline the first stored one is used; a pipeline row without an id is passed over.
	 *
	 * @return void
	 */
	public function testFallsBackToTheFirstPipelineWhenNoneIsTheDefault(): void {
		$objects = new FakeBackfillObjectService();
		$objects->store['lead-schema']['l1'] = ['id' => 'l1', 'title' => 'Orphan'];
		$objects->store['pipeline-schema']['broken'] = ['title' => 'No id'];
		$objects->store['pipeline-schema']['p1'] = ['id' => 'p1', 'title' => 'Sales'];
		$objects->store['pipeline-schema']['p2'] = ['id' => 'p2', 'title' => 'Service'];
		$step = $this->makeStepAround(objectService: $objects, config: $this->configured());

		$step->run($this->createMock(IOutput::class));

		$this->assertCount(1, $objects->saves);
		$this->assertSame('p1', $objects->saves[0]['pipeline']);
	}//end testFallsBackToTheFirstPipelineWhenNoneIsTheDefault()

	/**
	 * Rows arrive as entities on a real OpenRegister: they are read through jsonSerialize(),
	 * and a row that is neither an array nor serialisable, or has no id, is passed over.
	 *
	 * @return void
	 */
	public function testReadsEntityRowsAndPassesOverUnusableOnes(): void {
		$entity = new class() implements JsonSerializable {

			/**
			 * The lead as OpenRegister serialises it.
			 *
			 * @return array<string,mixed> The lead.
			 */
			public function jsonSerialize(): array {
				return ['id' => 'l1', 'title' => 'From an entity'];
			}//end jsonSerialize()
		};
		$scalarEntity = new class() implements JsonSerializable {

			/**
			 * A row that serialises to something that is not a lead.
			 *
			 * @return string Not an array.
			 */
			public function jsonSerialize(): string {
				return 'not a row';
			}//end jsonSerialize()
		};
		$objects = new FakeBackfillObjectService();
		$objects->store['lead-schema'] = [
			'a' => $entity,
			'b' => $scalarEntity,
			'c' => 'a bare string',
			'd' => ['title' => 'No id'],
		];
		$objects->store['pipeline-schema']['p1'] = ['id' => 'p1', 'title' => 'Sales', 'isDefault' => true];
		$step = $this->makeStepAround(objectService: $objects, config: $this->configured());
		$infos = [];
		$warnings = [];

		$step->run($this->recordingOutput($infos, $warnings));

		$this->assertCount(1, $objects->saves);
		$this->assertSame('l1', $objects->saves[0]['id']);
		$this->assertSame('p1', $objects->saves[0]['pipeline']);
		$this->assertStringContainsString('1 lead(s) backfilled, 1 already complete, 0 still unresolved', $infos[0]);
		$this->assertSame([], $warnings);
	}//end testReadsEntityRowsAndPassesOverUnusableOnes()
}//end class
