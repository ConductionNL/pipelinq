<?php

/**
 * Unit tests for the BackfillLeadRelations repair step.
 *
 * `lead.pipeline` and `lead.client` became required, and OpenRegister validates
 * required on every save — so a lead without them cannot be edited at all until
 * it is backfilled. These tests cover what the step has to get right:
 *
 *   - a lead missing a pipeline is put on the default pipeline
 *   - a lead missing a client is MATCHED to an existing client by name before
 *     any client is created, because minting a second record for a customer
 *     that already exists splits their history
 *   - the longest matching name wins, so "Gemeente Amsterdam" beats "Gemeente"
 *   - a lead matching nothing gets a client created through the contact-first
 *     path, since `contactsUid` is required and only that path mints one
 *   - two leads for the same unknown company share ONE created client
 *   - a lead that already has both is left untouched, so a re-run is a no-op
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
use OCA\Pipelinq\Service\ContactSyncService;
use OCA\Pipelinq\Service\LeadClientResolver;
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
 */
class BackfillLeadRelationsTest extends TestCase {

	/**
	 * The fake object store.
	 *
	 * @var FakeBackfillObjectService
	 */
	private FakeBackfillObjectService $objects;

	/**
	 * Names passed to client creation, in order.
	 *
	 * @var array<int,string>
	 */
	private array $created = [];

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

		// The resolver is REAL, with only its client-creating collaborator faked.
		// Mocking the resolver itself would delete the matching rules these
		// tests exist to check — the mock would answer whatever it was told to.
		$contactSync = $this->createMock(ContactSyncService::class);
		$this->created = [];
		$contactSync->method('createWithContact')->willReturnCallback(
			function (string $objectType, array $form): array {
				$this->created[] = $form['name'];

				return ['id' => 'made-' . count($this->created)];
			}
		);
		$resolver = new LeadClientResolver($contactSync, $this->createMock(LoggerInterface::class));

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('get')->willReturn(null);

		return new BackfillLeadRelations(
			$appConfig,
			$container,
			$resolver,
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
	 * An existing client is matched by name rather than a new one created.
	 *
	 * @return void
	 */
	public function testMatchesAnExistingClientInsteadOfCreatingOne(): void {
		$step = $this->makeStep(
			leads: [['id' => 'l1', 'title' => 'Gemeente Amsterdam - CRM 2026', 'pipeline' => 'p1']],
			clients: [['id' => 'c-ams', 'name' => 'Gemeente Amsterdam']],
			pipelines: [['id' => 'p1', 'title' => 'Sales']]
		);

		$step->run($this->createMock(IOutput::class));

		$this->assertSame('c-ams', $this->saved('l1')['client'] ?? null);
		$this->assertSame([], $this->created, 'no client should be created when one matches');
	}//end testMatchesAnExistingClientInsteadOfCreatingOne()

	/**
	 * The longest matching client name wins.
	 *
	 * @return void
	 */
	public function testPrefersTheLongestMatchingName(): void {
		$step = $this->makeStep(
			leads: [['id' => 'l1', 'title' => 'Gemeente Amsterdam - CRM 2026', 'pipeline' => 'p1']],
			// The SHORT name is listed last on purpose. With the
			// longest-wins guard removed, the last match would win and the
			// assertion below would pass anyway — ordering them the other way
			// makes this test unable to fail.
			clients: [
				['id' => 'c-long', 'name' => 'Gemeente Amsterdam'],
				['id' => 'c-short', 'name' => 'Gemeente'],
			],
			pipelines: [['id' => 'p1', 'title' => 'Sales']]
		);

		$step->run($this->createMock(IOutput::class));

		$this->assertSame('c-long', $this->saved('l1')['client'] ?? null);
	}//end testPrefersTheLongestMatchingName()

	/**
	 * With no match, a client is created through the contact-first path.
	 *
	 * @return void
	 */
	public function testCreatesAClientWhenNothingMatches(): void {
		$step = $this->makeStep(
			leads: [['id' => 'l1', 'title' => 'Totally New BV', 'pipeline' => 'p1']],
			clients: [['id' => 'c-other', 'name' => 'Someone Else']],
			pipelines: [['id' => 'p1', 'title' => 'Sales']]
		);

		$step->run($this->createMock(IOutput::class));

		$this->assertSame(['Totally New BV'], $this->created);
		$this->assertSame('made-1', $this->saved('l1')['client'] ?? null);
	}//end testCreatesAClientWhenNothingMatches()

	/**
	 * Two leads for the same unknown company share one created client.
	 *
	 * @return void
	 */
	public function testReusesAClientCreatedEarlierInTheSameRun(): void {
		$step = $this->makeStep(
			leads: [
				['id' => 'l1', 'title' => 'Nieuw Bedrijf', 'pipeline' => 'p1'],
				['id' => 'l2', 'title' => 'Nieuw Bedrijf', 'pipeline' => 'p1'],
			],
			clients: [],
			pipelines: [['id' => 'p1', 'title' => 'Sales']]
		);

		$step->run($this->createMock(IOutput::class));

		$this->assertCount(1, $this->created, 'the second lead must reuse the first created client');
		$this->assertSame('made-1', $this->saved('l1')['client'] ?? null);
		$this->assertSame('made-1', $this->saved('l2')['client'] ?? null);
	}//end testReusesAClientCreatedEarlierInTheSameRun()

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
