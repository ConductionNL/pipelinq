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
use OCA\OpenRegister\Service\ObjectService;
use OCA\Pipelinq\Service\ContactVcardService;
use OCA\Pipelinq\Service\DemoSeedService;
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
		'queue_schema' => '26',
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
		'queues' => ['26', 'title', 'title', null],
		'products' => ['27', 'name', 'name', null],
		'leads' => ['22', 'title', 'title', null],
		'requests' => [self::TICKET_SCHEMA_ID, 'title', 'title', 'request'],
		'complaints' => [self::TICKET_SCHEMA_ID, 'title', 'title', 'complaint'],
		'contactmomenten' => [self::TICKET_SCHEMA_ID, 'subject', 'title', 'contactmoment'],
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
	 * @return object Object exposing getUuid().
	 */
	private static function savedEntity(string $uuid): object {
		return new class($uuid) {
			/**
			 * @param string $uuid The uuid to expose.
			 */
			public function __construct(
				private string $uuid,
			) {
			}

			/**
			 * @return string The uuid.
			 */
			public function getUuid(): string {
				return $this->uuid;
			}
		};
	}//end savedEntity()

	/**
	 * Seeding a clean install creates the full linked demo set.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/align-claims-and-first-hour/specs/first-time-setup/spec.md#requirement-req-setup-pip-008--optional-demo-data-seed
	 */
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
		self::assertSame(3, $result['created']['queues']);
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
			array_filter($tickets, static fn (array $p): bool => $p['data']['ticketType'] === 'contactmoment')
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
		$linked = array_filter(
			$contactmomenten,
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
		self::assertSame(51, array_sum($result['skipped']));
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
		self::assertSame(51, array_sum($result['removed']));
		self::assertCount(51, $deleted);

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
		// Clients (5) + contacts (4) + pipelines (2) + queues (3) + products (3)
		// + leads (6) + tasks (3) + contracts (2) are on their own schemas and
		// still delete.
		self::assertSame(28, array_sum($result['removed']));
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
			logger: $this->createMock(LoggerInterface::class),
		);

		$result = $service->seed();

		self::assertFalse($result['success']);
		self::assertStringContainsString('Contacts', (string)$result['message']);
	}//end testSeedFailsWhenContactProvisioningUnavailable()
}//end class
