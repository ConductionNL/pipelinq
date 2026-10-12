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

use OCA\Pipelinq\Repair\BackfillLeadRelations;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

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
}//end class
