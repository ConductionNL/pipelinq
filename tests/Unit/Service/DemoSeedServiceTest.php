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
use OCA\Pipelinq\Service\Demo\DemoJourneySeeder;
use OCA\Pipelinq\Service\Demo\DemoMarketingSeeder;
use OCA\Pipelinq\Service\Demo\DemoSearchSeeder;
use OCA\Pipelinq\Service\Demo\DemoSeedValues;
use OCA\Pipelinq\Service\Demo\DemoSocialSeeder;
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

		$this->service = $this->buildService(container: $this->container, contactVcardService: $this->contactVcardService);
	}//end setUp()

	/**
	 * Build the service with the real demo seeders over the mocked journey
	 * service and object store.
	 *
	 * @param ContainerInterface $container The container (ObjectService, flow engine).
	 * @param ContactVcardService $contactVcardService Contact-first identity provisioning.
	 *
	 * @return DemoSeedService
	 */
	private function buildService(ContainerInterface $container, ContactVcardService $contactVcardService): DemoSeedService {
		$logger = $this->createMock(LoggerInterface::class);
		$values = new DemoSeedValues();

		return new DemoSeedService(
			appConfig: $this->appConfig,
			container: $container,
			contactVcardService: $contactVcardService,
			ticketService: $this->ticketService,
			marketingSeeder: new DemoMarketingSeeder(
				journeySeeder: new DemoJourneySeeder(
					journeyService: $this->journeyService,
					store: $this->store,
					container: $container,
					values: $values,
					logger: $logger,
				),
				socialSeeder: new DemoSocialSeeder(store: $this->store, values: $values),
				searchSeeder: new DemoSearchSeeder(store: $this->store, values: $values),
			),
			values: $values,
			logger: $logger,
		);
	}//end buildService()

	/**
	 * The journey seeder over the mocked journey service and object store.
	 *
	 * @return DemoJourneySeeder
	 */
	private function journeySeeder(): DemoJourneySeeder {
		return new DemoJourneySeeder(
			journeyService: $this->journeyService,
			store: $this->store,
			container: $this->container,
			values: new DemoSeedValues(),
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end journeySeeder()

	/**
	 * The social seeder over the mocked object store.
	 *
	 * @return DemoSocialSeeder
	 */
	private function socialSeeder(): DemoSocialSeeder {
		return new DemoSocialSeeder(store: $this->store, values: new DemoSeedValues());
	}//end socialSeeder()

	/**
	 * The Search Console seeder over the mocked object store.
	 *
	 * @return DemoSearchSeeder
	 */
	private function searchSeeder(): DemoSearchSeeder {
		return new DemoSearchSeeder(store: $this->store, values: new DemoSeedValues());
	}//end searchSeeder()

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

				// A consistent store: every seeded object already points at the
				// seeded pipeline its definition names.
				if (isset($definition['pipelineKey']) === true) {
					$row['pipeline'] = self::seededPipelineUuid(key: (string)$definition['pipelineKey']);
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
	 * The uuid seededStore() gives the demo pipeline with the given key.
	 *
	 * @param string $key The pipeline definition key.
	 *
	 * @return string The uuid.
	 */
	private static function seededPipelineUuid(string $key): string {
		foreach (self::definitions()['pipelines'] as $i => $definition) {
			if ($definition['key'] === $key) {
				return 'pipelines-uuid-' . $i;
			}
		}

		return '';
	}//end seededPipelineUuid()

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
	 * A reseed re-points demo leads whose pipeline was deleted at the demo
	 * pipeline it resolved, and touches nothing else (pipelinq review F1).
	 *
	 * On the old code the lead was reused as it was, so it kept the dead id:
	 * saveObject was never called and this test fails.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/review-audit-fixes-b/specs/lead-management/spec.md#requirement-a-reseed-re-links-demo-leads-to-the-new-pipeline-req-raf-010
	 */
	public function testReseedRelinksDemoLeadsOnADeletedPipeline(): void {
		$this->provisionConfig();

		$store = self::seededStore();
		$deadPipeline = '44c997c9-dead-pipeline';
		$leadSchema = self::SECTION_SCHEMAS['leads'][0];
		$orphaned = 0;
		foreach ($store[$leadSchema] as $i => $row) {
			if (isset($row['pipeline']) === true) {
				$store[$leadSchema][$i]['pipeline'] = $deadPipeline;
				$store[$leadSchema][$i]['stage'] = 'Qualified';
				$orphaned++;
			}
		}

		self::assertGreaterThan(0, $orphaned);
		$this->mockFindAllFromStore($store);

		$saved = [];
		$this->objectService->method('saveObject')->willReturnCallback(
			static function (array $object, ...$rest) use (&$saved): ObjectEntity {
				$saved[] = ['object' => $object, 'schema' => $rest[2] ?? null, 'uuid' => $rest[3] ?? null];
				return self::savedEntity((string)($rest[3] ?? 'new'));
			}
		);
		$this->ticketService->expects(self::never())->method('save');

		$result = $this->service->seed();

		self::assertTrue($result['success']);
		self::assertSame(0, array_sum($result['created']));
		self::assertSame($orphaned, $result['relinked']['leads']);
		self::assertCount($orphaned, $saved);
		foreach ($saved as $write) {
			self::assertSame($leadSchema, $write['schema']);
			self::assertStringStartsWith('leads-uuid-', (string)$write['uuid']);
			self::assertStringStartsWith('pipelines-uuid-', $write['object']['pipeline']);
			// The rest of the stored lead is kept as it was.
			self::assertSame('Qualified', $write['object']['stage']);
		}
	}//end testReseedRelinksDemoLeadsOnADeletedPipeline()

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

		$service = $this->buildService(container: $this->container, contactVcardService: $failingVcard);

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

		// The social section saves through the same store; only runs matter here.
		$runs = array_values(array_filter($runs, static fn (array $save): bool => $save['schema'] === 'journeyRun'));

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
		$schemas = [];
		$this->store->method('save')->willReturnCallback(
			static function (string $schemaSlug) use (&$schemas): array {
				$schemas[] = $schemaSlug;
				return ['id' => 'saved-' . count($schemas)];
			}
		);

		$result = $this->service->seed();

		self::assertSame(0, $result['created']['journeys']);
		self::assertSame(count(self::definitions()['journeys']), $result['skipped']['journeys']);
		self::assertNotContains('journeyRun', $schemas, 'a skipped journey writes no runs');
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

		$this->journeySeeder()->seed(
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

		$service = $this->buildService(container: $container, contactVcardService: $this->contactVcardService);

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

	/**
	 * The social section seeds inactive accounts, published posts and
	 * publications linked to them, none of which the daily jobs will act on.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
	 */
	public function testSeedSocialLinksPublicationsToTheSeededPostsAndAccounts(): void {
		$saves = [];
		$this->store->method('findAll')->willReturn([]);
		$this->store->method('save')->willReturnCallback(
			static function (string $schemaSlug, array $payload) use (&$saves): array {
				$saves[] = ['schema' => $schemaSlug, 'data' => $payload];
				return ['id' => $schemaSlug . '-' . count($saves)];
			}
		);

		$social = self::definitions()['social'];
		$counts = $this->socialSeeder()->seed($social);

		self::assertSame(count($social['accounts']), $counts['accounts']);
		self::assertSame(count($social['posts']), $counts['posts']);
		self::assertSame(count($social['publications']), $counts['publications']);

		$networks = [];
		foreach ($saves as $index => $save) {
			$id = $save['schema'] . '-' . ($index + 1);
			if ($save['schema'] === 'socialAccount') {
				self::assertStringStartsWith(DemoSeedService::DEMO_PREFIX, $save['data']['displayName']);
				self::assertFalse($save['data']['active'], 'the daily follower refresh must skip a demo account');
				self::assertArrayNotHasKey('credentialRef', $save['data']);
				$networks[$id] = $save['data']['network'];
			}

			if ($save['schema'] === 'socialPost') {
				self::assertSame('published', $save['data']['status'], 'the publish job only acts on scheduled posts');
				self::assertNotEmpty($save['data']['accountIds']);
			}

			if ($save['schema'] === 'socialPublication') {
				self::assertStringStartsWith('socialPost-', $save['data']['postId']);
				self::assertSame($networks[$save['data']['accountId']], $save['data']['network']);
				self::assertArrayNotHasKey('externalId', $save['data'], 'the daily metrics pull must skip a demo publication');
				self::assertStringStartsNotWith('@', $save['data']['publishedAt']);
			}
		}
	}//end testSeedSocialLinksPublicationsToTheSeededPostsAndAccounts()

	/**
	 * A demo post that already exists is skipped with its publications, so a
	 * re-run does not double the ranking.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
	 */
	public function testSeedSocialSkipsAnExistingPostAndItsPublications(): void {
		$social = self::definitions()['social'];
		$this->store->method('findAll')->willReturnCallback(
			static function (string $schemaSlug) use ($social): array {
				if ($schemaSlug !== 'socialPost') {
					return [];
				}

				return array_map(
					static fn (array $definition): array => ['id' => $definition['key'], 'title' => $definition['data']['title']],
					$social['posts']
				);
			}
		);
		$this->store->method('save')->willReturnCallback(
			static fn (string $schemaSlug): array => ['id' => $schemaSlug . '-new']
		);

		$counts = $this->socialSeeder()->seed($social);

		self::assertSame(0, $counts['posts']);
		self::assertSame(0, $counts['publications']);
		self::assertSame(count($social['posts']), $counts['skipped']);
	}//end testSeedSocialSkipsAnExistingPostAndItsPublications()

	/**
	 * Removal deletes the publications of demo posts, then the posts, then the
	 * accounts, and leaves every row without the demo marker alone.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
	 */
	public function testRemoveSocialDeletesOnlyTheDemoSet(): void {
		$social = self::definitions()['social'];
		$rows = [
			'socialPost' => array_merge(
				array_map(
					static fn (array $definition): array => ['id' => $definition['key'], 'title' => $definition['data']['title']],
					$social['posts']
				),
				[['id' => 'real-post', 'title' => 'Real post']]
			),
			'socialAccount' => array_merge(
				array_map(
					static fn (array $definition): array => ['id' => $definition['key'], 'displayName' => $definition['data']['displayName']],
					$social['accounts']
				),
				[['id' => 'real-account', 'displayName' => 'Real account']]
			),
			'socialPublication' => [
				['id' => 'pub-demo', 'postId' => $social['posts'][0]['key']],
				['id' => 'pub-real', 'postId' => 'real-post'],
			],
		];
		$this->store->method('findAll')->willReturnCallback(
			static fn (string $schemaSlug): array => ($rows[$schemaSlug] ?? [])
		);

		$deleted = [];
		$this->store->method('delete')->willReturnCallback(
			static function (string $schemaSlug, string $id) use (&$deleted): bool {
				$deleted[] = $schemaSlug . ':' . $id;
				return true;
			}
		);

		$counts = $this->socialSeeder()->remove($social);

		self::assertSame(1, $counts['publications']);
		self::assertSame(count($social['posts']), $counts['posts']);
		self::assertSame(count($social['accounts']), $counts['accounts']);
		self::assertSame('socialPublication:pub-demo', $deleted[0], 'publications go before the posts they name');
		self::assertNotContains('socialPublication:pub-real', $deleted);
		self::assertNotContains('socialPost:real-post', $deleted);
		self::assertNotContains('socialAccount:real-account', $deleted);
	}//end testRemoveSocialDeletesOnlyTheDemoSet()

	/**
	 * The search section writes rows the way the import does, marked by the
	 * demo property and source, with the click rate derived from the counts.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
	 */
	public function testSeedSearchWritesMarkedRowsWithADerivedClickRate(): void {
		$saves = [];
		$this->store->method('findAll')->willReturn([]);
		$this->store->method('save')->willReturnCallback(
			static function (string $schemaSlug, array $payload) use (&$saves): array {
				$saves[] = ['schema' => $schemaSlug, 'data' => $payload];
				return ['id' => 'saved-' . count($saves)];
			}
		);

		$search = self::definitions()['search'];
		$counts = $this->searchSeeder()->seed($search);

		self::assertSame(count($search['rows']), $counts['rows']);
		self::assertSame(count($search['targets']), $counts['targets']);

		foreach ($saves as $save) {
			self::assertSame($search['property'], $save['data']['property']);
			if ($save['schema'] !== 'searchQueryDaily') {
				continue;
			}

			$row = $save['data'];
			self::assertSame($search['source'], $row['source']);
			self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $row['date']);
			self::assertSame(round($row['clicks'] / $row['impressions'], 4), $row['ctr']);
		}
	}//end testSeedSearchWritesMarkedRowsWithADerivedClickRate()

	/**
	 * The rows are one set: when any demo row exists, a re-run writes none.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
	 */
	public function testSeedSearchWritesNoRowsWhenTheDemoSetExists(): void {
		$search = self::definitions()['search'];
		$this->store->method('findAll')->willReturnCallback(
			static fn (string $schemaSlug): array => ($schemaSlug === 'searchQueryDaily'
				? [['id' => 'row-1', 'property' => $search['property'], 'source' => $search['source']]]
				: [['id' => 'target-1', 'notes' => $search['targets'][0]['data']['notes']]])
		);
		$this->store->expects(self::never())->method('save');

		$counts = $this->searchSeeder()->seed($search);

		self::assertSame(0, $counts['rows']);
		self::assertSame(0, $counts['targets']);
	}//end testSeedSearchWritesNoRowsWhenTheDemoSetExists()

	/**
	 * Removal deletes rows with both demo markers and demo-noted targets, and
	 * never a real import or a real target.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
	 */
	public function testRemoveSearchDeletesOnlyTheDemoRowsAndTargets(): void {
		$search = self::definitions()['search'];
		$this->store->method('findAll')->willReturnCallback(
			static function (string $schemaSlug, array $filters = []) use ($search): array {
				if ($schemaSlug === 'searchQueryDaily') {
					// The store narrows by the filters it is given.
					self::assertSame(['property' => $search['property'], 'source' => $search['source']], $filters);
					return [['id' => 'demo-row', 'property' => $search['property'], 'source' => $search['source']]];
				}

				return [
					['id' => 'demo-target', 'property' => $search['property'], 'notes' => '[Demo] seeded'],
					['id' => 'real-target', 'property' => $search['property'], 'notes' => 'A real decision'],
					['id' => 'other-property', 'property' => 'https://real.example/', 'notes' => '[Demo] elsewhere'],
				];
			}
		);

		$deleted = [];
		$this->store->method('delete')->willReturnCallback(
			static function (string $schemaSlug, string $id) use (&$deleted): bool {
				$deleted[] = $id;
				return true;
			}
		);

		$counts = $this->searchSeeder()->remove($search);

		self::assertSame(1, $counts['rows']);
		self::assertSame(1, $counts['targets']);
		self::assertSame(['demo-row', 'demo-target'], $deleted);
	}//end testRemoveSearchDeletesOnlyTheDemoRowsAndTargets()
}//end class
