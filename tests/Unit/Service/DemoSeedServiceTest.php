<?php

/**
 * Unit tests for DemoSeedService.
 *
 * Covers REQ-SETUP-PIP-008: seeding on a clean install (linked demo set),
 * idempotent re-run (no duplicates), removal scoping (deletes exactly the
 * [Demo]-marked set, never real data), archival retention, and the
 * unprovisioned-install guard.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/align-claims-and-first-hour/specs/first-time-setup/spec.md#requirement-req-setup-pip-008--optional-demo-data-seed
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Pipelinq\Service\ContactVcardService;
use OCA\Pipelinq\Service\DemoSeedService;
use OCA\Pipelinq\Service\Marketing\JourneyService;
use OCA\Pipelinq\Service\Marketing\ListObjectStore;
use OCA\Pipelinq\Service\TicketService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for DemoSeedService.
 *
 * Since unify-ticket-supertype the requests + contactmomenten sections both
 * seed the unified `ticket` schema (id 25 here), separated by the `ticketType`
 * discriminator, while the seed file keeps its legacy field names.
 *
 * @spec openspec/changes/align-claims-and-first-hour/specs/first-time-setup/spec.md#requirement-req-setup-pip-008--optional-demo-data-seed
 * @spec openspec/changes/unify-ticket-supertype/specs/unify-ticket-supertype/spec.md#requirement-create-surfaces-write-tickets
 */
class DemoSeedServiceTest extends TestCase {
	/**
	 * The unified ticket schema id used throughout this test.
	 *
	 * @var string
	 */
	private const TICKET_SCHEMA_ID = '25';

	/**
	 * Schema-id map used by the provisioned config (schema config key => id).
	 *
	 * @var array<string, string>
	 */
	private const SCHEMA_IDS = [
		'client_schema' => '21',
		'lead_schema' => '22',
		'contact_schema' => '23',
		'pipeline_schema' => '24',
		'product_schema' => '27',
		'task_schema' => '28',
		'contract_schema' => '29',
	];

	/**
	 * Section => [schema id, seed-file field, persisted lookup field, ticketType].
	 *
	 * Mirrors the service SECTIONS: the ticket sections read a legacy field name
	 * from the seed file and persist it under the ticket field name.
	 *
	 * @var array<string, array{0: string, 1: string, 2: string, 3: string|null}>
	 */
	private const SECTION_SCHEMAS = [
		'clients' => ['21', 'name', 'name', null],
		'contacts' => ['23', 'name', 'name', null],
		'pipelines' => ['24', 'title', 'title', null],
		'products' => ['27', 'name', 'name', null],
		'leads' => ['22', 'title', 'title', null],
		'requests' => [self::TICKET_SCHEMA_ID, 'title', 'title', 'request'],
		'complaints' => [self::TICKET_SCHEMA_ID, 'title', 'title', 'complaint'],
		'contactmomenten' => [self::TICKET_SCHEMA_ID, 'subject', 'title', 'interaction'],
		'tasks' => ['28', 'subject', 'subject', null],
		'contracts' => ['29', 'title', 'title', null],
	];

	/**
	 * Mocked app config.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig $appConfig;

	/**
	 * Mocked container.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface $container;

	/**
	 * Mocked OpenRegister ObjectService.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface $objectService;

	/**
	 * Mocked contact-first identity provisioner.
	 *
	 * @var ContactVcardService&MockObject
	 */
	private ContactVcardService $contactVcardService;

	/**
	 * Mocked unified ticket resolver.
	 *
	 * @var TicketService&MockObject
	 */
	private TicketService $ticketService;

	/**
	 * Mocked journey write path.
	 *
	 * @var JourneyService&MockObject
	 */
	private JourneyService $journeyService;

	/**
	 * Mocked session-free object store.
	 *
	 * @var ListObjectStore&MockObject
	 */
	private ListObjectStore $store;

	/**
	 * Service under test.
	 *
	 * @var DemoSeedService
	 */
	private DemoSeedService $service;

	/**
	 * Wire the service with a provisioned config + container by default.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->contactVcardService = $this->createMock(ContactVcardService::class);
		$this->ticketService = $this->createMock(TicketService::class);
		$this->journeyService = $this->createMock(JourneyService::class);
		$this->store = $this->createMock(ListObjectStore::class);

		$this->store->method('schemaSlug')
			->willReturnCallback(static fn (string $configKey, string $default): string => $default);
		$this->store->method('idOf')
			->willReturnCallback(static fn (?array $payload): string => (string)($payload['id'] ?? ''));

		$this->container->method('get')
			->with('OCA\OpenRegister\Service\ObjectService')
			->willReturn($this->objectService);

		// Contact-first provisioning succeeds by default with a stable NC uid.
		$this->contactVcardService->method('provisionContactFromForm')
			->willReturnCallback(
				static fn (array $form, string $objectType): array => [
					'contactsUid' => 'nc-contact-' . md5((string)$form['name']),
					'name' => (string)$form['name'],
					'email' => (string)($form['email'] ?? ''),
					'phone' => (string)($form['phone'] ?? ''),
				]
			);

		$this->service = new DemoSeedService(
			appConfig: $this->appConfig,
			container: $this->container,
			contactVcardService: $this->contactVcardService,
			ticketService: $this->ticketService,
			journeyService: $this->journeyService,
			store: $this->store,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * Configure register + schema ids as provisioned.
	 *
	 * @return void
	 */
	private function provisionConfig(): void {
		$this->appConfig->method('getValueString')
			->willReturnCallback(
				static function (string $app, string $key, string $default = ''): string {
					$values = array_merge(['register' => '11'], self::SCHEMA_IDS);
					return $values[$key] ?? $default;
				}
			);

		$this->ticketService->method('getSchemaId')->willReturn(self::TICKET_SCHEMA_ID);
		$this->ticketService->method('getRegisterId')->willReturn('11');
		$this->ticketService->method('isConfigured')->willReturn(true);
	}//end provisionConfig()

	/**
	 * Load the shipped seed definitions (the service reads the same file).
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private static function definitions(): array {
		$path = dirname(__DIR__, 3) . '/lib/Settings/demo_seed_data.json';
		return json_decode((string)file_get_contents($path), true);
	}//end definitions()

	/**
	 * Build the rendered-row store an already-seeded install would return,
	 * keyed by schema id, including a non-demo decoy row per schema.
	 *
	 * The ticket schema holds both ticket sections; each row carries its
	 * `ticketType` so the service can narrow on the discriminator.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private static function seededStore(): array {
		$store = [];
		foreach (self::SECTION_SCHEMAS as $section => [$schemaId, $sourceField, $lookupField, $ticketType]) {
			$rows = ($store[$schemaId] ?? []);
			foreach (self::definitions()[$section] as $i => $definition) {
				$row = [
					'id' => $section . '-uuid-' . $i,
					$lookupField => (string)$definition['data'][$sourceField],
				];
				if ($ticketType !== null) {
					$row['ticketType'] = $ticketType;
				}

				$rows[] = $row;
			}

			$store[$schemaId] = $rows;
		}

		// Decoy: one real (non-demo) object per schema that must never be touched.
		// The ticket decoy is a real request-type ticket.
		foreach ($store as $schemaId => $rows) {
			$decoy = [
				'id' => 'schema-' . $schemaId . '-real-object',
				'name' => 'Real record ' . $schemaId,
				'title' => 'Real record ' . $schemaId,
				'subject' => 'Real record ' . $schemaId,
			];
			if ($schemaId === self::TICKET_SCHEMA_ID) {
				$decoy['ticketType'] = 'request';
			}

			$rows[] = $decoy;
			$store[$schemaId] = $rows;
		}

		return $store;
	}//end seededStore()

	/**
	 * Wire findAll to serve rows from a mutable store keyed by schema id.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $store The row store (by reference).
	 *
	 * @return void
	 */
	private function mockFindAllFromStore(array &$store): void {
		$this->objectService->method('findAll')
			->willReturnCallback(
				static function (array $config) use (&$store): array {
					$schemaId = (string)($config['filters']['schema'] ?? '');
					return $store[$schemaId] ?? [];
				}
			);
	}//end mockFindAllFromStore()

	/**
	 * Build a saveObject return value carrying a uuid.
	 *
	 * @param string $uuid The uuid.
	 *
	 * ADR-084: saveObject() declares `ObjectEntityInterface`, so a bare
	 * getUuid()-exposing anonymous double no longer satisfies the return type —
	 * the real entity is used instead.
	 *
	 * @return ObjectEntity Entity carrying the uuid.
	 */
	private static function savedEntity(string $uuid): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);

		return $entity;
	}//end savedEntity()

	/**
	 * Seeding a clean install creates the full linked demo set.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/align-claims-and-first-hour/specs/first-time-setup/spec.md#requirement-req-setup-pip-008--optional-demo-data-seed
	 */
	/**
	 * The wizard's choice step is offered declining and the shipped set.
	 *
	 * 🔴 "NO THANKS" HAS TO BE SAYABLE. This app implemented a
	 * `skip-demo-data` action that no manifest step could reach, so the step
	 * stayed outstanding and CnAppRoot reopened the wizard over every page
	 * unless the operator seeded data they did not want.
	 *
	 * @return void
	 */
	public function testTheChoiceStepIsOfferedDecliningAndTheShippedSet(): void {
		$choices = $this->service->listChoices();

		$this->assertSame(['none', 'demo'], array_column($choices, 'id'));
		foreach ($choices as $choice) {
			$this->assertNotSame('', $choice['label']);
			$this->assertNotSame('', $choice['description']);
			$this->assertNotSame('', $choice['icon']);
		}

	}//end testTheChoiceStepIsOfferedDecliningAndTheShippedSet()

	/**
	 * The card promises no object count, because this seeder builds its objects.
	 *
	 * There is no honest number until it has run, and a made-up one is worse
	 * than none: the wizard renders no stat for a zero.
	 *
	 * @return void
	 */
	public function testTheOfferedSetPromisesNoCountItCannotKnow(): void {
		$demo = $this->service->listChoices()[1];

		$this->assertSame(0, $demo['objectCount']);
		// 🔴 NO NUMBER IN THE SENTENCE EITHER. The wizard translates a card's
		// description by literal lookup, so an interpolated count would leave a
		// Dutch operator reading English.
		$this->assertDoesNotMatchRegularExpression('/\d/', $demo['description']);

	}//end testTheOfferedSetPromisesNoCountItCannotKnow()

	public function testSeedOnCleanInstallCreatesLinkedDemoSet(): void {
		$this->provisionConfig();

		// Nothing exists yet.
		$this->objectService->method('findAll')->willReturn([]);

		$savedPayloads = [];
		$sequence = 0;
		$this->objectService->method('saveObject')
			->willReturnCallback(
				function (array $data, array $extend, string $register, string $schema) use (&$savedPayloads, &$sequence): object {
					$sequence++;
					$uuid = 'uuid-' . $schema . '-' . $sequence;
					$savedPayloads[] = ['schema' => $schema, 'data' => $data, 'uuid' => $uuid];
					return self::savedEntity($uuid);
				}
			);

		// Ticket sections write through TicketService, which forces ticketType.
		$this->ticketService->method('save')
			->willReturnCallback(
				function (string $ticketType, array $payload, ?string $uuid = null) use (&$savedPayloads, &$sequence): object {
					$sequence++;
					$payload['ticketType'] = $ticketType;
					$newUuid = 'uuid-' . self::TICKET_SCHEMA_ID . '-' . $sequence;
					$savedPayloads[] = [
						'schema' => self::TICKET_SCHEMA_ID,
						'data' => $payload,
						'uuid' => $newUuid,
					];
					return self::savedEntity($newUuid);
				}
			);

		$result = $this->service->seed();

		self::assertTrue($result['success']);
		self::assertSame(5, $result['created']['clients']);
		self::assertSame(4, $result['created']['contacts']);
		self::assertSame(2, $result['created']['pipelines']);
		self::assertSame(3, $result['created']['products']);
		self::assertSame(6, $result['created']['leads']);
		self::assertSame(8, $result['created']['requests']);
		self::assertSame(3, $result['created']['complaints']);
		self::assertSame(12, $result['created']['contactmomenten']);
		self::assertSame(3, $result['created']['tasks']);
		self::assertSame(2, $result['created']['contracts']);
		self::assertSame(0, array_sum($result['skipped']));

		// Every seeded object carries the demo marker on its lookup field.
		// `subject` is the task section's lookup field; `name` / `title` cover
		// the rest.
		foreach ($savedPayloads as $payload) {
			$lookup = $payload['data']['name']
				?? ($payload['data']['title'] ?? ($payload['data']['subject'] ?? ''));
			self::assertStringStartsWith(DemoSeedService::DEMO_PREFIX, $lookup);
		}

		// Every client AND every contact carries the contact-first provisioned NC
		// contact uid. register.d/15-unify-client-contact.json marks contactsUid
		// REQUIRED on BOTH schemas, so a section that skipped provisioning would
		// be rejected by OpenRegister at runtime with "The required property
		// (contactsUid) is missing" — measured on run 31097862359, where the
		// contacts section did exactly that and failed the whole seed with a 500.
		$identityRows = 0;
		foreach ($savedPayloads as $payload) {
			if ($payload['schema'] === '21' || $payload['schema'] === '23') {
				self::assertStringStartsWith('nc-contact-', (string)$payload['data']['contactsUid']);
				$identityRows++;
			}
		}

		self::assertSame(9, $identityRows, '5 clients + 4 contacts are provisioned contact-first');

		// Leads and contactmomenten are linked to seeded client uuids.
		$clientUuids = array_column(
			array_filter($savedPayloads, static fn (array $p): bool => $p['schema'] === '21'),
			'uuid'
		);
		$leads = array_filter($savedPayloads, static fn (array $p): bool => $p['schema'] === '22');
		foreach ($leads as $lead) {
			self::assertContains($lead['data']['client'], $clientUuids);
		}

		// The client FK is NOT uniformly named: the contract schema calls it
		// `clientRef`. Writing it as `client` would save cleanly and leave the
		// contract unlinked, so assert the key the schema actually declares.
		$contracts = array_values(
			array_filter($savedPayloads, static fn (array $p): bool => $p['schema'] === '29')
		);
		self::assertCount(2, $contracts);
		foreach ($contracts as $contract) {
			self::assertArrayHasKey('clientRef', $contract['data']);
			self::assertArrayNotHasKey('client', $contract['data']);
			self::assertContains($contract['data']['clientRef'], $clientUuids);
		}

		// Date placeholders are resolved to concrete dates.
		$firstLead = array_values($leads)[0]['data'];
		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $firstLead['expectedCloseDate']);

		// Both ticket sections land on the one ticket schema, typed, with the
		// legacy field names mapped onto the ticket fields.
		$tickets = array_values(
			array_filter($savedPayloads, static fn (array $p): bool => $p['schema'] === self::TICKET_SCHEMA_ID)
		);
		self::assertCount(23, $tickets);

		$requests = array_values(
			array_filter($tickets, static fn (array $p): bool => $p['data']['ticketType'] === 'request')
		);
		$contactmomenten = array_values(
			array_filter($tickets, static fn (array $p): bool => $p['data']['ticketType'] === 'interaction')
		);
		$complaints = array_values(
			array_filter($tickets, static fn (array $p): bool => $p['data']['ticketType'] === 'complaint')
		);
		self::assertCount(8, $requests);
		self::assertCount(3, $complaints);
		self::assertCount(12, $contactmomenten);

		foreach ($requests as $request) {
			self::assertArrayHasKey('occurredAt', $request['data']);
			self::assertArrayNotHasKey('requestedAt', $request['data']);
		}

		foreach ($contactmomenten as $contactmoment) {
			self::assertArrayHasKey('title', $contactmoment['data']);
			self::assertArrayHasKey('description', $contactmoment['data']);
			self::assertArrayHasKey('occurredAt', $contactmoment['data']);
			self::assertArrayNotHasKey('subject', $contactmoment['data']);
			self::assertArrayNotHasKey('summary', $contactmoment['data']);
			self::assertArrayNotHasKey('contactedAt', $contactmoment['data']);
			// The contactmoment status is derived from its outcome.
			self::assertNotSame('', (string)$contactmoment['data']['status']);
		}

		// The parent-request link is written as parentTicket, pointing at a
		// seeded request ticket.
		$requestUuids = array_column($requests, 'uuid');
		$linked = array_filter($contactmomenten,
			static fn (array $p): bool => isset($p['data']['parentTicket'])
		);
		self::assertNotEmpty($linked);
		foreach ($linked as $contactmoment) {
			self::assertContains($contactmoment['data']['parentTicket'], $requestUuids);
			self::assertArrayNotHasKey('request', $contactmoment['data']);
		}
	}//end testSeedOnCleanInstallCreatesLinkedDemoSet()

	/**
	 * Re-running the seed creates no duplicates (idempotency).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/align-claims-and-first-hour/specs/first-time-setup/spec.md#requirement-req-setup-pip-008--optional-demo-data-seed
	 */
	public function testSeedIsIdempotentOnRerun(): void {
		$this->provisionConfig();

		$store = self::seededStore();
		$this->mockFindAllFromStore($store);

		$this->objectService->expects(self::never())->method('saveObject');
		$this->ticketService->expects(self::never())->method('save');

		$result = $this->service->seed();

		self::assertTrue($result['success']);
		self::assertSame(0, array_sum($result['created']));
		self::assertSame(48, array_sum($result['skipped']));
	}//end testSeedIsIdempotentOnRerun()

	/**
	 * Removal deletes exactly the seeded set — never the non-demo decoys.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/align-claims-and-first-hour/specs/first-time-setup/spec.md#requirement-req-setup-pip-008--optional-demo-data-seed
	 */
	public function testRemoveDeletesExactlyTheSeededSet(): void {
		$this->provisionConfig();

		$store = self::seededStore();
		$this->mockFindAllFromStore($store);

		$deleted = [];
		$this->objectService->method('deleteObject')
			->willReturnCallback(
				static function (string $uuid) use (&$deleted, &$store): bool {
					$deleted[] = $uuid;
					// Drop the row from the store so the removal loop terminates.
					foreach ($store as $schemaId => $rows) {
						$store[$schemaId] = array_values(
							array_filter($rows, static fn (array $r): bool => $r['id'] !== $uuid)
						);
					}

					return true;
				}
			);

		$result = $this->service->remove();

		self::assertTrue($result['success']);
		self::assertSame(48, array_sum($result['removed']));
		self::assertCount(48, $deleted);

		// The non-demo decoy rows are never deleted.
		foreach ($deleted as $uuid) {
			self::assertStringNotContainsString('real-object', $uuid);
		}

		foreach (self::SECTION_SCHEMAS as [$schemaId]) {
			self::assertCount(1, $store[$schemaId]);
		}
	}//end testRemoveDeletesExactlyTheSeededSet()

	/**
	 * Archival (append-only) schemas retain their rows instead of failing.
	 *
	 * Simulated here on the ticket schema: every section folded into it (both
	 * requests and contactmomenten) is reported as retained, and the sections
	 * on non-archival schemas still delete normally.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/align-claims-and-first-hour/specs/first-time-setup/spec.md#requirement-req-setup-pip-008--optional-demo-data-seed
	 */
	public function testRemoveRetainsArchivalSchemaRows(): void {
		$this->provisionConfig();

		$store = self::seededStore();
		$this->mockFindAllFromStore($store);

		$this->objectService->method('deleteObject')
			->willReturnCallback(
				static function (string $uuid, string|int|null $register = null, string|int|null $schema = null) use (&$store): bool {
					if ((string)$schema === self::TICKET_SCHEMA_ID) {
						throw new \Exception('SCHEMA_ARCHIVAL_IMMUTABLE: schema declares x-openregister-archival');
					}

					foreach ($store as $schemaId => $rows) {
						$store[$schemaId] = array_values(
							array_filter($rows, static fn (array $r): bool => $r['id'] !== $uuid)
						);
					}

					return true;
				}
			);

		$result = $this->service->remove();

		self::assertTrue($result['success']);
		self::assertSame(12, $result['retained']['contactmomenten']);
		self::assertSame(0, $result['removed']['contactmomenten']);
		self::assertSame(8, $result['retained']['requests']);
		self::assertSame(0, $result['removed']['requests']);
		self::assertSame(3, $result['retained']['complaints']);
		self::assertSame(0, $result['removed']['complaints']);
		// Clients (5) + contacts (4) + pipelines (2) + products (3) + leads (6)
		// + tasks (3) + contracts (2) are on their own schemas and still delete.
		self::assertSame(25, array_sum($result['removed']));
	}//end testRemoveRetainsArchivalSchemaRows()

	/**
	 * Removal skips objects that no longer exist without failing.
	 *
	 * @return void
	 */
	public function testRemoveSkipsMissingObjects(): void {
		$this->provisionConfig();

		$this->objectService->method('findAll')->willReturn([]);
		$this->objectService->expects(self::never())->method('deleteObject');

		$result = $this->service->remove();

		self::assertTrue($result['success']);
		self::assertSame(0, array_sum($result['removed']));
	}//end testRemoveSkipsMissingObjects()

	/**
	 * Seeding an unprovisioned install fails cleanly with a message.
	 *
	 * @return void
	 */
	public function testSeedFailsWhenRegisterNotProvisioned(): void {
		$this->appConfig->method('getValueString')->willReturn('');

		$result = $this->service->seed();

		self::assertFalse($result['success']);
		self::assertNotSame('', (string)$result['message']);
	}//end testSeedFailsWhenRegisterNotProvisioned()

	/**
	 * Seeding fails cleanly when Nextcloud Contacts cannot provision identities.
	 *
	 * @return void
	 */
	public function testSeedFailsWhenContactProvisioningUnavailable(): void {
		$this->provisionConfig();
		$this->objectService->method('findAll')->willReturn([]);

		// Fresh mocks: provisioning always fails.
		$failingVcard = $this->createMock(ContactVcardService::class);
		$failingVcard->method('provisionContactFromForm')->willReturn(null);

		$service = new DemoSeedService(
			appConfig: $this->appConfig,
			container: $this->container,
			contactVcardService: $failingVcard,
			ticketService: $this->ticketService,
			journeyService: $this->journeyService,
			store: $this->store,
			logger: $this->createMock(LoggerInterface::class),
		);

		$result = $service->seed();

		self::assertFalse($result['success']);
		self::assertStringContainsString('Contacts', (string)$result['message']);
	}//end testSeedFailsWhenContactProvisioningUnavailable()

	/**
	 * Demo journeys are saved through JourneyService, never switched on, and
	 * their runs name the seeded demo contact and client.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
	 */
	public function testSeedSavesDemoJourneysAndLinksTheirRuns(): void {
		$this->provisionConfig();
		$this->objectService->method('findAll')->willReturn([]);

		$sequence = 0;
		$this->objectService->method('saveObject')
			->willReturnCallback(
				static function (array $data, array $extend, string $register, string $schema) use (&$sequence): object {
					$sequence++;
					return self::savedEntity('uuid-' . $schema . '-' . $sequence);
				}
			);
		$this->ticketService->method('save')
			->willReturnCallback(
				static function () use (&$sequence): object {
					$sequence++;
					return self::savedEntity('uuid-ticket-' . $sequence);
				}
			);

		$this->journeyService->method('listJourneys')->willReturn([]);
		$journeys = [];
		$this->journeyService->method('save')
			->willReturnCallback(
				static function (array $payload) use (&$journeys): array {
					$journeys[] = $payload;
					return ['id' => 'journey-' . count($journeys)] + $payload;
				}
			);

		$runs = [];
		$this->store->method('save')
			->willReturnCallback(
				static function (string $schemaSlug, array $payload) use (&$runs): array {
					$runs[] = ['schema' => $schemaSlug, 'data' => $payload];
					return ['id' => 'run-' . count($runs)];
				}
			);

		$result = $this->service->seed();

		self::assertTrue($result['success']);
		self::assertSame(count(self::definitions()['journeys']), $result['created']['journeys']);
		self::assertSame(count($runs), $result['created']['journeyRuns']);
		self::assertNotSame(0, count($runs));

		foreach ($journeys as $journey) {
			self::assertStringStartsWith(DemoSeedService::DEMO_PREFIX, $journey['name']);
			self::assertContains($journey['status'], ['draft', 'paused'], 'A demo journey is never active');
		}

		foreach ($runs as $run) {
			self::assertSame('journeyRun', $run['schema']);
			self::assertStringStartsWith('journey-', $run['data']['journeyId']);
			self::assertStringStartsWith('uuid-' . self::SCHEMA_IDS['contact_schema'] . '-', $run['data']['contactId']);
			self::assertStringStartsWith('uuid-' . self::SCHEMA_IDS['client_schema'] . '-', $run['data']['clientId']);
			self::assertStringStartsNotWith('@', $run['data']['occurredAt']);
		}
	}//end testSeedSavesDemoJourneysAndLinksTheirRuns()

	/**
	 * A demo journey that already exists is skipped, and so are its runs.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
	 */
	public function testSeedSkipsDemoJourneysThatAlreadyExist(): void {
		$this->provisionConfig();
		$store = self::seededStore();
		$this->mockFindAllFromStore($store);

		$this->journeyService->method('listJourneys')->willReturn(
			array_map(
				static fn (array $definition): array => ['id' => $definition['key'], 'name' => $definition['data']['name']],
				self::definitions()['journeys']
			)
		);
		$this->journeyService->expects(self::never())->method('save');
		$this->store->expects(self::never())->method('save');

		$result = $this->service->seed();

		self::assertSame(0, $result['created']['journeys']);
		self::assertSame(count(self::definitions()['journeys']), $result['skipped']['journeys']);
	}//end testSeedSkipsDemoJourneysThatAlreadyExist()

	/**
	 * A journey the seed file marks active is saved as a draft, so its flow
	 * is never enabled.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
	 */
	public function testSeedHoldsAnActiveDemoJourneyToDraft(): void {
		$this->journeyService->method('listJourneys')->willReturn([]);
		$saved = [];
		$this->journeyService->method('save')
			->willReturnCallback(
				static function (array $payload) use (&$saved): array {
					$saved[] = $payload;
					return ['id' => 'journey-1'];
				}
			);

		$method = new \ReflectionMethod(DemoSeedService::class, 'seedJourneys');
		$method->invoke(
			$this->service,
			[['key' => 'active', 'data' => ['name' => '[Demo] Active', 'status' => 'active', 'trigger' => ['kind' => 'listConfirmed'], 'action' => ['kind' => 'createTask']]]],
			[]
		);

		self::assertSame('draft', $saved[0]['status']);
	}//end testSeedHoldsAnActiveDemoJourneyToDraft()

	/**
	 * Removal deletes each demo journey with its runs and its flow, and never
	 * touches a journey without the demo marker.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
	 */
	public function testRemoveDeletesDemoJourneysWithTheirRunsAndFlows(): void {
		$this->provisionConfig();
		$this->objectService->method('findAll')->willReturn([]);

		$flows = new class {
			/** @var array<int, string> */
			public array $deleted = [];

			public function delete(string $uuid): void {
				$this->deleted[] = $uuid;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')
			->willReturnCallback(
				fn (string $id): object => ($id === JourneyService::FLOW_SERVICE ? $flows : $this->objectService)
			);

		$journeys = [];
		foreach (self::definitions()['journeys'] as $i => $definition) {
			$journeys[] = ['id' => 'journey-' . $i, 'name' => $definition['data']['name'], 'flowUuid' => 'flow-' . $i];
		}
		$journeys[] = ['id' => 'journey-real', 'name' => 'Real journey', 'flowUuid' => 'flow-real'];

		$this->journeyService->method('listJourneys')->willReturn($journeys);
		$this->journeyService->method('runsFor')
			->willReturnCallback(static fn (string $journeyId): array => [['id' => 'run-of-' . $journeyId]]);

		$deleted = [];
		$this->store->method('delete')
			->willReturnCallback(
				static function (string $schemaSlug, string $id) use (&$deleted): bool {
					$deleted[] = $id;
					return true;
				}
			);

		$service = new DemoSeedService(
			appConfig: $this->appConfig,
			container: $container,
			contactVcardService: $this->contactVcardService,
			ticketService: $this->ticketService,
			journeyService: $this->journeyService,
			store: $this->store,
			logger: $this->createMock(LoggerInterface::class),
		);

		$result = $service->remove();

		$count = count(self::definitions()['journeys']);
		self::assertTrue($result['success']);
		self::assertSame($count, $result['removed']['journeys']);
		self::assertSame($count, $result['removed']['journeyRuns']);
		self::assertCount($count, $flows->deleted);
		self::assertNotContains('flow-real', $flows->deleted);
		self::assertNotContains('journey-real', $deleted);
		self::assertNotContains('run-of-journey-real', $deleted);
	}//end testRemoveDeletesDemoJourneysWithTheirRunsAndFlows()
}//end class
