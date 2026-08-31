<?php

/**
 * Pipelinq DemoSeedService.
 *
 * Idempotent demo-data seed shared by the `occ pipelinq:demo:seed` command and
 * the optional `seed-demo-data` setup-wizard action (one write path, ADR-042).
 * Seeds a small coherent linked dataset — clients (person + organisation),
 * their contact persons, pipelines, queues, products, leads across pipeline
 * stages, request / complaint / contactmoment tickets, and tasks — from
 * lib/Settings/demo_seed_data.json so lists, dashboards and the 360° client
 * view render populated on a fresh install.
 *
 * Since unify-ticket-supertype the requests and contactmomenten sections both
 * seed the unified `ticket` schema, distinguished by the `ticketType`
 * discriminator and written through {@see TicketService}. The seed file keeps
 * its legacy (request / contactmoment) field names; they are mapped onto the
 * ticket field names on load — see self::TICKET_FIELD_MAP.
 *
 * Every seeded object is marked with the `[Demo]` prefix on its lookup field
 * (client.name / lead.title / ticket.title). The seed is idempotent via
 * exact-match lookup on that field (narrowed by `ticketType` for tickets), and
 * `remove()` deletes exactly the seeded set and nothing else. Client identities
 * are provisioned contact-first via ContactVcardService (the client schema
 * requires `contactsUid`); the backing Nextcloud addressbook contacts are
 * matched or created by that service and are intentionally NOT deleted by
 * `remove()` — they may be pre-existing addressbook entries the provisioning
 * matched.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
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
 * @spec openspec/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Idempotent demo-data seeding + removal against OpenRegister.
 *
 * @spec openspec/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
 * @spec openspec/changes/unify-ticket-supertype/specs/unify-ticket-supertype/spec.md#requirement-create-surfaces-write-tickets
 */
class DemoSeedService {
	/**
	 * Marker prefix carried by every seeded object's lookup field.
	 *
	 * @var string
	 */
	public const DEMO_PREFIX = '[Demo]';

	/**
	 * App-config key holding the unified ticket schema id.
	 *
	 * @var string
	 */
	private const TICKET_SCHEMA_KEY = 'ticket_schema';

	/**
	 * Entity sections in seed order: section => [schemaConfigKey, lookupField, ticketType].
	 *
	 * `ticketType` is null for sections that own their own schema, and one of
	 * the TicketService TYPE_* values for the sections folded into `ticket`.
	 *
	 * Seed order matters (clients before the objects that link to them);
	 * removal runs in reverse.
	 *
	 * The contacts / pipelines / queues / products / complaints / tasks
	 * sections were added 2026-08-06. The three that FIXED a failure are
	 * contacts, tasks and pipelines: the base register ships example objects for
	 * products (14), queues (3), clients, leads, tickets and the POS entities,
	 * but none at all for `contact`, `task` or `pipeline`. Those three index
	 * pages therefore had an empty collection on a fresh install and CnIndexPage
	 * rendered `cn-index-page__empty` instead of a data table — silently, with
	 * no console error and no failed request — which the e2e suite read as "the
	 * page does not render its table", blaming the page for a missing fixture.
	 *
	 * The queues / products / complaints rows are not repairing an empty page;
	 * they exist so the seeded set is coherent (a client has contact persons, a
	 * complaint is a first-class ticket subtype alongside requests).
	 *
	 * @var array<string, array{0: string, 1: string, 2: string|null}>
	 */
	private const SECTIONS = [
		'clients' => ['client_schema', 'name', null],
		'contacts' => ['contact_schema', 'name', null],
		'pipelines' => ['pipeline_schema', 'title', null],
		'queues' => ['queue_schema', 'title', null],
		'products' => ['product_schema', 'name', null],
		'leads' => ['lead_schema', 'title', null],
		'requests' => [self::TICKET_SCHEMA_KEY, 'title', TicketService::TYPE_REQUEST],
		'complaints' => [self::TICKET_SCHEMA_KEY, 'title', TicketService::TYPE_COMPLAINT],
		'contactmomenten' => [self::TICKET_SCHEMA_KEY, 'title', TicketService::TYPE_CONTACTMOMENT],
		'tasks' => ['task_schema', 'subject', null],
		'contracts' => ['contract_schema', 'title', null],
	];

	/**
	 * Sections whose schema requires a contact-first identity: section => objectType.
	 *
	 * `register.d/15-unify-client-contact.json` marks `contactsUid` REQUIRED on
	 * both the `client` and the `contact` schema, so a plain `saveObject()` of
	 * either is rejected by OpenRegister with "The required property
	 * (contactsUid) is missing". Both must go through ContactVcardService first,
	 * whose own ALLOWED_OBJECT_TYPES is exactly ['client', 'contact'].
	 *
	 * @var array<string, string>
	 */
	private const CONTACT_FIRST_SECTIONS = [
		'clients' => 'client',
		'contacts' => 'contact',
	];

	/**
	 * Legacy seed-file field name => ticket field name, per ticket type.
	 *
	 * The shipped demo_seed_data.json still speaks the pre-unification field
	 * names; they are renamed onto the ticket supertype at load time.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const TICKET_FIELD_MAP = [
		TicketService::TYPE_REQUEST => ['requestedAt' => 'occurredAt'],
		TicketService::TYPE_CONTACTMOMENT => [
			'subject' => 'title',
			'summary' => 'description',
			'contactedAt' => 'occurredAt',
			'agent' => 'assignee',
		],
	];

	/**
	 * Contactmoment `outcome` => unified ticket `status`.
	 *
	 * The legacy contactmoment schema had no status field; the ticket supertype
	 * does. Mirrors the mapping the MigrateToTicketSupertype repair step applied
	 * to the historical records, so seeded and migrated contactmomenten present
	 * identically in the Tickets workspace.
	 *
	 * @var array<string, string>
	 */
	private const OUTCOME_STATUS = [
		'handled' => 'resolved',
		'resolved' => 'resolved',
		'transferred' => 'closed',
		'referred' => 'closed',
		'callbackRequest' => 'in_progress',
		'followUpAction' => 'in_progress',
	];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig App config (register/schema ids).
	 * @param ContainerInterface $container Container for the OpenRegister ObjectService.
	 * @param ContactVcardService $contactVcardService Contact-first identity provisioning (client.contactsUid).
	 * @param TicketService $ticketService Unified ticket resolver + write path.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ContainerInterface $container,
		private readonly ContactVcardService $contactVcardService,
		private readonly TicketService $ticketService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Seed the demo dataset (idempotent: existing demo objects are reused).
	 *
	 * @return array{success: bool, message?: string, created: array<string, int>, skipped: array<string, int>}
	 *
	 * @spec openspec/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
	 */
	public function seed(): array {
		$context = $this->resolveContext();
		if ($context === null) {
			return [
				'success' => false,
				'message' => 'Pipelinq register or schemas are not provisioned — run the provisioning step first.',
				'created' => [],
				'skipped' => [],
			];
		}

		[$objectService, $registerId, $schemaIds, $definitions] = $context;

		$created = [];
		$skipped = [];
		// Maps section-local keys (e.g. clientKey "bakkerij") to saved uuids.
		$uuids = [];

		foreach (self::SECTIONS as $section => [$schemaKey, $lookupField, $ticketType]) {
			$created[$section] = 0;
			$skipped[$section] = 0;

			// One scan per schema: value => [uuid, ...] index for idempotency.
			$index = $this->buildLookupIndex(
				objectService: $objectService,
				registerId: $registerId,
				schemaId: $schemaIds[$schemaKey],
				lookupField: $lookupField,
				ticketType: $ticketType,
			);

			foreach (($definitions[$section] ?? []) as $definition) {
				$data = $this->resolvePlaceholders(data: $definition['data']);
				$data = $this->linkReferences(
					data: $data,
					definition: $definition,
					uuids: $uuids,
					ticketType: $ticketType,
				);

				$existingUuid = $index[(string)$data[$lookupField]][0] ?? null;

				if ($existingUuid !== null) {
					$uuids[$section . ':' . $definition['key']] = $existingUuid;
					$skipped[$section]++;
					continue;
				}

				$identityType = (self::CONTACT_FIRST_SECTIONS[$section] ?? null);
				if ($identityType !== null) {
					$data = $this->withProvisionedIdentity(data: $data, objectType: $identityType);
					if ($data === null) {
						return [
							'success' => false,
							'message' => 'Nextcloud Contacts is unavailable — cannot provision demo ' . $identityType . ' identities.',
							'created' => $created,
							'skipped' => $skipped,
						];
					}
				}

				$saved = $this->saveSeedObject(
					objectService: $objectService,
					registerId: $registerId,
					schemaId: $schemaIds[$schemaKey],
					data: $data,
					ticketType: $ticketType,
				);

				$uuids[$section . ':' . $definition['key']] = (string)$saved->getUuid();
				$created[$section]++;
			}//end foreach
		}//end foreach

		return ['success' => true, 'created' => $created, 'skipped' => $skipped];
	}//end seed()

	/**
	 * Remove exactly the seeded demo set (lookup-field exact match, [Demo] prefix guarded).
	 *
	 * Archival (append-only) schemas forbid user-driven deletes by design; their
	 * demo rows are reported as `retained` and expire via the OpenRegister
	 * ArchivalRetentionTask cron instead.
	 *
	 * @return array{success: bool, message?: string, removed: array<string, int>, retained: array<string, int>}
	 *
	 * @spec openspec/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
	 */
	public function remove(): array {
		$context = $this->resolveContext();
		if ($context === null) {
			return [
				'success' => false,
				'message' => 'Pipelinq register or schemas are not provisioned — nothing to remove.',
				'removed' => [],
				'retained' => [],
			];
		}

		[$objectService, $registerId, $schemaIds, $definitions] = $context;

		$removed = [];
		$retained = [];
		$sections = array_reverse(self::SECTIONS, true);

		foreach ($sections as $section => [$schemaKey, $lookupField, $ticketType]) {
			$removed[$section] = 0;
			$retained[$section] = 0;

			// One scan per schema: value => [uuid, ...] index. Multiple uuids
			// per value guard against duplicates from historic runs.
			$index = $this->buildLookupIndex(
				objectService: $objectService,
				registerId: $registerId,
				schemaId: $schemaIds[$schemaKey],
				lookupField: $lookupField,
				ticketType: $ticketType,
			);

			foreach (($definitions[$section] ?? []) as $definition) {
				$lookupValue = (string)($definition['data'][$lookupField] ?? '');
				// Guard: only ever delete objects carrying the demo marker.
				if (str_starts_with($lookupValue, self::DEMO_PREFIX) === false) {
					continue;
				}

				foreach (($index[$lookupValue] ?? []) as $uuid) {
					try {
						$objectService->deleteObject($uuid, $registerId, $schemaIds[$schemaKey]);
						$removed[$section]++;
					} catch (\Throwable $e) {
						if (str_contains($e->getMessage(), 'SCHEMA_ARCHIVAL_IMMUTABLE') === true) {
							// Append-only schema: rows expire via retention cron.
							$retained[$section]++;
							break;
						}

						throw $e;
					}
				}
			}//end foreach
		}//end foreach

		return ['success' => true, 'removed' => $removed, 'retained' => $retained];
	}//end remove()

	/**
	 * Persist one seeded demo object and return the saved entity.
	 *
	 * Ticket sections write the unified schema through TicketService, which
	 * forces the `ticketType` discriminator on; every other section writes the
	 * section's own schema directly. Exactly one write happens per call.
	 *
	 * @param object $objectService The OpenRegister object service.
	 * @param string $registerId The register to write into.
	 * @param string $schemaId The schema for this section.
	 * @param array<string, mixed> $data The object payload.
	 * @param string|null $ticketType The ticket discriminator, or null for a plain write.
	 *
	 * @return object The saved entity.
	 *
	 * @spec openspec/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
	 */
	private function saveSeedObject(
		object $objectService,
		string $registerId,
		string $schemaId,
		array $data,
		?string $ticketType,
	): object {
		if ($ticketType !== null) {
			return $this->ticketService->save(
				ticketType: $ticketType,
				payload: $data,
			);
		}

		return $objectService->saveObject(
			$data,
			[],
			$registerId,
			$schemaId,
			null
		);
	}//end saveSeedObject()

	/**
	 * Resolve the ObjectService, register id, schema ids and seed definitions.
	 *
	 * @return array{0: object, 1: string, 2: array<string, string>, 3: array<string, array<int, array<string, mixed>>>}|null
	 *         Null when the register/schemas are not provisioned, or the seed
	 *         file is unreadable.
	 */
	private function resolveContext(): ?array {
		$registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		if ($registerId === '') {
			return null;
		}

		$schemaIds = [];
		foreach (self::SECTIONS as [$schemaKey]) {
			if (isset($schemaIds[$schemaKey]) === true) {
				continue;
			}

			$schemaId = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');
			if ($schemaKey === self::TICKET_SCHEMA_KEY) {
				// The ticket schema has one resolution point (TicketService).
				$schemaId = $this->ticketService->getSchemaId();
			}

			if ($schemaId === '') {
				return null;
			}

			$schemaIds[$schemaKey] = $schemaId;
		}

		$definitions = $this->loadDefinitions();
		if ($definitions === null) {
			return null;
		}

		$definitions = $this->mapDefinitionsToTickets(definitions: $definitions);

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (\Throwable $e) {
			$this->logger->error('Pipelinq demo seed: OpenRegister ObjectService unavailable', ['exception' => $e->getMessage()]);
			return null;
		}

		return [$objectService, $registerId, $schemaIds, $definitions];
	}//end resolveContext()

	/**
	 * Load and decode lib/Settings/demo_seed_data.json.
	 *
	 * @return array<string, array<int, array<string, mixed>>>|null Null when missing or invalid.
	 */
	private function loadDefinitions(): ?array {
		$path = dirname(__DIR__) . '/Settings/demo_seed_data.json';
		if (is_readable($path) === false) {
			$this->logger->error('Pipelinq demo seed: demo_seed_data.json missing', ['path' => $path]);
			return null;
		}

		$decoded = json_decode((string)file_get_contents($path), true);
		if (is_array($decoded) === false || $decoded === []) {
			$this->logger->error('Pipelinq demo seed: demo_seed_data.json invalid JSON', ['path' => $path]);
			return null;
		}

		return $decoded;
	}//end loadDefinitions()

	/**
	 * Scan a schema once and index it by exact lookup-field value.
	 *
	 * Deliberately scans and matches in PHP instead of pushing an equality
	 * filter down: magic-table filterability is schema-dependent (e.g.
	 * lead.title equality filters silently match nothing on a live install),
	 * and a false negative here would break idempotency. For the same reason
	 * the `ticketType` narrowing is applied in PHP over the scanned rows.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $registerId Register id.
	 * @param string $schemaId Schema id.
	 * @param string $lookupField Field used as the stable demo identifier.
	 * @param string|null $ticketType Ticket subtype to narrow on, or null for own-schema sections.
	 *
	 * @return array<string, array<int, string>> Map of lookup value => uuids.
	 */
	private function buildLookupIndex(
		object $objectService,
		string $registerId,
		string $schemaId,
		string $lookupField,
		?string $ticketType = null,
	): array {
		$existing = $objectService->findAll(
			[
				'filters' => [
					'register' => $registerId,
					'schema' => $schemaId,
				],
				'limit' => 2000,
			]
		);

		if (is_array($existing) === false) {
			return [];
		}

		$index = [];
		foreach ($existing as $row) {
			$data = $this->rowToArray(row: $row);
			if ($data === null) {
				continue;
			}

			if ($ticketType !== null && ($data['ticketType'] ?? null) !== $ticketType) {
				continue;
			}

			$value = (string)($data[$lookupField] ?? '');
			$uuid = (string)($data['id'] ?? ($data['uuid'] ?? ''));
			if ($value === '' || $uuid === '') {
				continue;
			}

			$index[$value][] = $uuid;
		}

		return $index;
	}//end buildLookupIndex()

	/**
	 * Rewrite the ticket sections of the seed definitions onto ticket fields.
	 *
	 * The shipped seed file still carries the pre-unification field names; this
	 * renames them (self::TICKET_FIELD_MAP), derives the contactmoment `status`
	 * from its `outcome`, and stamps the `ticketType` discriminator so both the
	 * seed and the removal lookup see one ticket-shaped payload.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $definitions Raw seed definitions.
	 *
	 * @return array<string, array<int, array<string, mixed>>> Ticket-shaped definitions.
	 */
	private function mapDefinitionsToTickets(array $definitions): array {
		foreach (self::SECTIONS as $section => [, , $ticketType]) {
			if ($ticketType === null || isset($definitions[$section]) === false) {
				continue;
			}

			foreach ($definitions[$section] as $i => $definition) {
				$definitions[$section][$i]['data'] = $this->toTicketData(
					data: ($definition['data'] ?? []),
					ticketType: $ticketType,
				);
			}
		}

		return $definitions;
	}//end mapDefinitionsToTickets()

	/**
	 * Map one legacy seed payload onto the ticket supertype fields.
	 *
	 * @param array<string, mixed> $data Raw definition data (legacy field names).
	 * @param string $ticketType One of the TicketService TYPE_* values.
	 *
	 * @return array<string, mixed> Ticket-shaped payload.
	 */
	private function toTicketData(array $data, string $ticketType): array {
		foreach ((self::TICKET_FIELD_MAP[$ticketType] ?? []) as $legacy => $field) {
			if (array_key_exists($legacy, $data) === false) {
				continue;
			}

			$data[$field] = $data[$legacy];
			unset($data[$legacy]);
		}

		// The legacy contactmoment had no status; the ticket supertype does.
		if ($ticketType === TicketService::TYPE_CONTACTMOMENT && isset($data['status']) === false) {
			$outcome = (string)($data['outcome'] ?? '');
			$data['status'] = (self::OUTCOME_STATUS[$outcome] ?? 'new');
		}

		$data['ticketType'] = $ticketType;

		return $data;
	}//end toTicketData()

	/**
	 * Normalise a findAll row (ObjectEntity or rendered array) to an array.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array<string, mixed>|null The rendered data, or null when unreadable.
	 */
	private function rowToArray(mixed $row): ?array {
		if (is_array($row) === true) {
			return $row;
		}

		if ($row instanceof \JsonSerializable === true) {
			$data = $row->jsonSerialize();
			if (is_array($data) === true) {
				return $data;
			}
		}

		return null;
	}//end rowToArray()

	/**
	 * Resolve `@days:N` / `@datetime:N` placeholders to concrete dates.
	 *
	 * @param array<string, mixed> $data Raw definition data.
	 *
	 * @return array<string, mixed> Data with date placeholders resolved.
	 */
	private function resolvePlaceholders(array $data): array {
		foreach ($data as $field => $value) {
			if (is_string($value) === false) {
				continue;
			}

			if (preg_match('/^@(days|datetime):(-?\d+)$/', $value, $matches) !== 1) {
				continue;
			}

			$offset = (int)$matches[2];
			$moment = (new DateTimeImmutable())->modify(sprintf('%+d days', $offset));

			$data[$field] = $moment->format(DateTimeInterface::ATOM);
			if ($matches[1] === 'days') {
				$data[$field] = $moment->format('Y-m-d');
			}
		}

		return $data;
	}//end resolvePlaceholders()

	/**
	 * Resolve the contact-first identity for a client/contact payload.
	 *
	 * Contact-first unification (register.d/15-unify-client-contact.json): BOTH
	 * the client and the contact schema mark `contactsUid` REQUIRED — it is a
	 * foreign key to the authoritative Nextcloud addressbook contact and is
	 * never minted locally — so the NC contact is provisioned BEFORE the object
	 * is saved, through the same path the create surface uses. Matching an
	 * existing contact by email keeps re-seeding after `--remove` duplicate-free
	 * on the addressbook side too.
	 *
	 * Callers gate this on self::CONTACT_FIRST_SECTIONS, whose values are
	 * exactly ContactVcardService::ALLOWED_OBJECT_TYPES, so no third section can
	 * silently take this path.
	 *
	 * @param array<string, mixed> $data The payload about to be saved.
	 * @param string $objectType 'client' or 'contact'.
	 *
	 * @return array<string, mixed>|null The payload with `contactsUid` set, or
	 *                                   null when Nextcloud Contacts is
	 *                                   unavailable and no identity can be
	 *                                   resolved.
	 *
	 * @spec openspec/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
	 */
	private function withProvisionedIdentity(array $data, string $objectType): ?array {
		$provision = $this->contactVcardService->provisionContactFromForm(
			form: $data,
			objectType: $objectType
		);

		if ($provision === null) {
			return null;
		}

		$data['contactsUid'] = $provision['contactsUid'];

		return $data;
	}//end withProvisionedIdentity()

	/**
	 * Wire clientKey / contactKey / pipelineKey / requestKey references onto
	 * the payload as uuids.
	 *
	 * On a ticket the parent-request link is `parentTicket` (the legacy
	 * contactmoment field was `request`).
	 *
	 * @param array<string, mixed> $data Definition data.
	 * @param array<string, mixed> $definition Full definition (may carry clientKey/contactKey/pipelineKey/requestKey).
	 * @param array<string, string> $uuids Already-seeded uuid map (section:key => uuid).
	 * @param string|null $ticketType Ticket subtype, or null for own-schema sections.
	 *
	 * @return array<string, mixed> Data with relation fields set.
	 */
	private function linkReferences(array $data, array $definition, array $uuids, ?string $ticketType = null): array {
		// The FK field name is not uniform across the register: the contract
		// schema calls it `clientRef` ("UUID reference to the existing client
		// object. Client identity is never duplicated into the contract."),
		// everything else calls it `client`. Writing the wrong key is silent —
		// the object saves, the FK is simply absent — so the map is explicit
		// rather than assumed.
		$data = $this->linkOneReference(
			data: $data,
			definition: $definition,
			uuids: $uuids,
			keyName: 'clientKey',
			section: 'clients',
			field: ($definition['clientField'] ?? 'client'),
		);

		$data = $this->linkOneReference(
			data: $data,
			definition: $definition,
			uuids: $uuids,
			keyName: 'contactKey',
			section: 'contacts',
			field: 'contact',
		);

		// Without this a seeded lead / ticket carries a `stage` name but no
		// `pipeline`, so it belongs to no board and every stage column counts
		// zero — the demo data cannot demonstrate the pipeline it was written
		// for. `pipelines` is seeded before `leads`, so the uuid is available.
		$data = $this->linkOneReference(
			data: $data,
			definition: $definition,
			uuids: $uuids,
			keyName: 'pipelineKey',
			section: 'pipelines',
			field: 'pipeline',
		);

		// On a ticket the parent-request link is `parentTicket` (the legacy
		// contactmoment field was `request`).
		$requestField = 'request';
		if ($ticketType !== null) {
			$requestField = 'parentTicket';
		}

		$data = $this->linkOneReference(
			data: $data,
			definition: $definition,
			uuids: $uuids,
			keyName: 'requestKey',
			section: 'requests',
			field: $requestField,
		);

		return $data;
	}//end linkReferences()

	/**
	 * Set one relation field from the seeded uuid map, when the definition
	 * names a key and that key has already been seeded.
	 *
	 * Extracted from linkReferences() so its four call sites stop each
	 * contributing two branches to a single method: phpmd measured the
	 * combined cyclomatic complexity at 10 against a threshold of 10.
	 *
	 * @param array<string, mixed> $data Definition data.
	 * @param array<string, mixed> $definition Full definition.
	 * @param array<string, string> $uuids Already-seeded uuid map (section:key => uuid).
	 * @param string $keyName Definition key naming the target (e.g. 'clientKey').
	 * @param string $section Uuid-map section the key lives in (e.g. 'clients').
	 * @param string $field Field on $data to set.
	 *
	 * @return array<string, mixed> Data with the relation field set when resolvable.
	 */
	private function linkOneReference(array $data, array $definition, array $uuids, string $keyName, string $section, string $field): array {
		if (isset($definition[$keyName]) === false) {
			return $data;
		}

		$uuid = ($uuids[$section . ':' . $definition[$keyName]] ?? null);
		if ($uuid !== null) {
			$data[$field] = $uuid;
		}

		return $data;
	}//end linkOneReference()
}//end class
