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

use OCA\Pipelinq\Kernel\Contracts\DemoSeedServiceInterface;
use OCA\Pipelinq\Service\Demo\DemoMarketingSeeder;
use OCA\Pipelinq\Service\Demo\DemoSeedContext;
use OCA\Pipelinq\Service\Demo\DemoSeedTarget;
use OCA\Pipelinq\Service\Demo\DemoSeedValues;
use OCA\Pipelinq\Service\Demo\DemoSeedWriter;

/**
 * Idempotent demo-data seeding + removal against OpenRegister.
 *
 * @spec openspec/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
 * @spec openspec/specs/unify-ticket-supertype/spec.md#requirement-create-surfaces-write-tickets
 */
class DemoSeedService implements DemoSeedServiceInterface {
	/**
	 * Marker prefix carried by every seeded object's lookup field.
	 *
	 * @var string
	 */
	public const DEMO_PREFIX = DemoSeedValues::DEMO_PREFIX;

	/**
	 * App-config key holding the unified ticket schema id.
	 *
	 * @var string
	 */
	private const TICKET_SCHEMA_KEY = DemoSeedContext::TICKET_SCHEMA_KEY;

	/**
	 * Reference fields a reseed re-points on an existing demo object.
	 *
	 * The seed is idempotent by lookup field, so a demo lead that already
	 * exists is reused as it is. When its pipeline was deleted and the reseed
	 * created a new demo pipeline, the lead kept the dead id and showed on no
	 * board while still counting in the open pipeline (pipelinq review F1).
	 * A reseed therefore re-points these fields at the objects it resolved.
	 */
	private const RELINK_FIELDS = ['pipeline'];

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
	 * @param DemoSeedContext     $context         Register, schemas, store and seed definitions.
	 * @param DemoSeedWriter      $writer          Contact-first identity and the one write path.
	 * @param DemoMarketingSeeder $marketingSeeder Journeys, social and Search Console demo data.
	 * @param DemoSeedValues      $values          Placeholder and reference resolution.
	 */
	public function __construct(
		private readonly DemoSeedContext $context,
		private readonly DemoSeedWriter $writer,
		private readonly DemoMarketingSeeder $marketingSeeder,
		private readonly DemoSeedValues $values,
	) {
	}//end __construct()

	/**
	 * Every answer the wizard's choice step may offer, declining included.
	 *
	 * 🔴 THE SERVER OWNS THIS LIST, AND THAT IS THE POINT. The step declares
	 * `optionsSource: datasets` and no options of its own, so what the card
	 * says is what this service will actually create.
	 *
	 * `objectCount` is 0 because this seeder BUILDS its objects rather than
	 * importing a file, so there is no honest number until it has run. The card
	 * shows no count rather than a made-up one.
	 *
	 * @return array<int, array{id: string, label: string, description: string, objectCount: integer, icon: string}> The answers.
	 *
	 * @spec exclude Demo-data choice list; ADR-111 rule 1 has no per-app behavioural spec.
	 */
	public function listChoices(): array {
		return [
			[
				'id'          => self::NONE_DATASET,
				'label'       => 'None, I will set this up myself',
				'description' => 'Nothing is seeded. You start with an empty app and add your own data.',
				'objectCount' => 0,
				'icon'        => 'CloseCircleOutline',
			],
			[
				'id'    => self::DEMO_DATASET,
				'label' => 'Example data',
				// 🔴 NAMES WHAT IT CREATES. The sections below are the ones
				// SECTIONS declares, so the sentence and the seeder cannot
				// drift apart without this list changing too.
				'description' => (
					'A worked CRM: clients and contacts, pipelines, products and leads, requests, '
					. 'complaints and contact moments, tasks and contracts, point of sale and '
					. 'bookings, marketing journeys that are not switched on, published social '
					. 'posts with their numbers, and search queries for the keyword pages. '
					. 'It shows the lists, '
					. 'detail pages and dashboards working. Safe to run more than once, and '
					. '`occ pipelinq:demo:seed --remove` takes it away again.'
				),
				'objectCount' => 0,
				'icon'        => 'DatabaseOutline',
			],
		];

	}//end listChoices()

	/**
	 * Seed the demo dataset (idempotent: existing demo objects are reused).
	 *
	 * @return array{success: bool, message?: string, created: array<string, int>, skipped: array<string, int>, relinked?: array<string, int>}
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

		// `uuids` maps section-local keys (e.g. clientKey "bakkerij") to saved uuids.
		$run = ['created' => [], 'skipped' => [], 'relinked' => [], 'uuids' => []];
		foreach (self::SECTIONS as $section => [$schemaKey, $lookupField, $ticketType]) {
			$target = new DemoSeedTarget(objectService: $objectService, registerId: $registerId, schemaId: $schemaIds[$schemaKey], ticketType: $ticketType);
			$run = $this->seedSection(
				section: ['name' => $section, 'lookupField' => $lookupField, 'target' => $target, 'definitions' => ($definitions[$section] ?? [])],
				run: $run
			);
			if (isset($run['failure']) === true) {
				return $run['failure'];
			}
		}

		$marketing = $this->marketingSeeder->seed(definitions: $definitions, uuids: $run['uuids']);

		return [
			'success' => true,
			'created' => array_merge($run['created'], $marketing['created']),
			'skipped' => array_merge($run['skipped'], $marketing['skipped']),
			'relinked' => $run['relinked'],
		];
	}//end seed()

	/**
	 * Seed one section: one scan of its schema (value => [uuid, ...] for
	 * idempotency, plus the scanned rows by uuid for the relink check), then
	 * every definition.
	 *
	 * @param array<string, mixed> $section The section's name, lookup field, target and definitions.
	 * @param array<string, array<string, mixed>> $run The counts per section and the uuids so far.
	 *
	 * @return array<string, mixed> The run, with a `failure` answer when the section could not be seeded.
	 */
	private function seedSection(array $section, array $run): array {
		$name = $section['name'];
		$run['created'][$name] = 0;
		$run['skipped'][$name] = 0;
		$run['relinked'][$name] = 0;
		$section += $this->buildLookupIndex(target: $section['target'], lookupField: $section['lookupField']);

		foreach ($section['definitions'] as $definition) {
			$run = $this->seedDefinition(definition: $definition, scope: ['section' => $section, 'run' => $run]);
			if (isset($run['failure']) === true) {
				return $run;
			}
		}

		return $run;
	}//end seedSection()

	/**
	 * Seed one definition: reuse (and relink) the demo object that already
	 * exists, or create it, provisioning its contact-first identity first.
	 *
	 * @param array<string, mixed> $definition The seed definition.
	 * @param array<string, array<string, mixed>> $scope The section (with its index and rows) and the run.
	 *
	 * @return array<string, mixed> The run, with a `failure` answer when Contacts is unavailable.
	 */
	private function seedDefinition(array $definition, array $scope): array {
		['section' => $section, 'run' => $run] = $scope;
		$name = $section['name'];
		$target = $section['target'];
		$data = $this->values->resolvePlaceholders(data: $definition['data']);
		$data = $this->linkReferences(data: $data, definition: $definition, uuids: $run['uuids'], ticketType: $target->ticketType);

		$existingUuid = $section['index'][(string)$data[$section['lookupField']]][0] ?? null;
		if ($existingUuid !== null) {
			return $this->reuseExisting(found: ['uuid' => $existingUuid, 'key' => $definition['key'], 'data' => $data], scope: $scope);
		}

		$identityType = (self::CONTACT_FIRST_SECTIONS[$name] ?? null);
		if ($identityType !== null) {
			$data = $this->withProvisionedIdentity(data: $data, objectType: $identityType);
			if ($data === null) {
				$run['failure'] = [
					'success' => false,
					'message' => 'Nextcloud Contacts is unavailable — cannot provision demo ' . $identityType . ' identities.',
					'created' => $run['created'],
					'skipped' => $run['skipped'],
				];
				return $run;
			}
		}

		$saved = $this->writer->save(target: $target, data: $data);
		$run['uuids'][$name . ':' . $definition['key']] = (string)$saved->getUuid();
		$run['created'][$name]++;
		return $run;
	}//end seedDefinition()

	/**
	 * Count an existing demo object as skipped, remember its uuid, and relink it.
	 *
	 * @param array<string, mixed> $found The stored object's uuid, the definition key and the resolved payload.
	 * @param array<string, array<string, mixed>> $scope The section (with its index and rows) and the run.
	 *
	 * @return array<string, mixed> The run.
	 */
	private function reuseExisting(array $found, array $scope): array {
		['section' => $section, 'run' => $run] = $scope;
		$name = $section['name'];
		$run['uuids'][$name . ':' . $found['key']] = $found['uuid'];
		$run['skipped'][$name]++;
		$existing = ($section['rows'][$found['uuid']] ?? []);
		if ($this->relinkExisting(target: $section['target'], existing: $existing, data: $found['data'], uuid: $found['uuid']) === true) {
			$run['relinked'][$name]++;
		}

		return $run;
	}//end reuseExisting()

	/**
	 * Re-point an existing demo object's references at this run's objects.
	 *
	 * Only the fields in self::RELINK_FIELDS are compared, and only when this
	 * run resolved a value for them. Everything else the user changed on the
	 * demo object stays as it is.
	 *
	 * @param DemoSeedTarget $target Where the object lives.
	 * @param array<string, mixed> $existing The stored object, as scanned.
	 * @param array<string, mixed> $data The resolved seed payload.
	 * @param string $uuid The stored object's uuid.
	 *
	 * @return bool True when the object was re-pointed.
	 *
	 * @spec openspec/specs/lead-management/spec.md#requirement-a-reseed-re-links-demo-leads-to-the-new-pipeline-req-raf-010
	 */
	private function relinkExisting(DemoSeedTarget $target, array $existing, array $data, string $uuid): bool {
		$patch = $this->relinkPatch(existing: $existing, data: $data);
		if ($patch === [] || $existing === []) {
			return false;
		}

		$payload = $existing;
		unset($payload['@self']);
		$this->writer->save(target: $target, data: array_merge($payload, $patch), uuid: $uuid);
		return true;
	}//end relinkExisting()

	/**
	 * The reference fields this run resolved that differ on the stored object.
	 *
	 * @param array<string, mixed> $existing The stored object, as scanned.
	 * @param array<string, mixed> $data     The resolved seed payload.
	 *
	 * @return array<string, string>
	 */
	private function relinkPatch(array $existing, array $data): array {
		$patch = [];
		foreach (self::RELINK_FIELDS as $field) {
			$wanted = (string)($data[$field] ?? '');
			if ($wanted !== '' && (string)($existing[$field] ?? '') !== $wanted) {
				$patch[$field] = $wanted;
			}
		}

		return $patch;
	}//end relinkPatch()

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

		$removed = $this->marketingSeeder->remove(definitions: $definitions);
		$retained = [];
		$sections = array_reverse(self::SECTIONS, true);

		foreach ($sections as $section => [$schemaKey, $lookupField, $ticketType]) {
			$target = new DemoSeedTarget(objectService: $objectService, registerId: $registerId, schemaId: $schemaIds[$schemaKey], ticketType: $ticketType);
			[$removed[$section], $retained[$section]] = $this->removeSection(
				target: $target,
				lookupField: $lookupField,
				definitions: ($definitions[$section] ?? [])
			);
		}

		return ['success' => true, 'removed' => $removed, 'retained' => $retained];
	}//end remove()

	/**
	 * Delete one section's demo objects.
	 *
	 * One scan per schema: value => [uuid, ...] index. Multiple uuids per
	 * value guard against duplicates from historic runs.
	 *
	 * @param DemoSeedTarget $target The section's register and schema.
	 * @param string $lookupField The field carrying the demo marker.
	 * @param array<int, array<string, mixed>> $definitions The section's demo definitions.
	 *
	 * @return array{0: int, 1: int} The removed and retained counts.
	 */
	private function removeSection(DemoSeedTarget $target, string $lookupField, array $definitions): array {
		$index = $this->buildLookupIndex(target: $target, lookupField: $lookupField)['index'];
		$removed = 0;
		$retained = 0;

		foreach ($definitions as $definition) {
			$lookupValue = (string)($definition['data'][$lookupField] ?? '');
			// Guard: only ever delete objects carrying the demo marker.
			if (str_starts_with($lookupValue, self::DEMO_PREFIX) === false) {
				continue;
			}

			[$deleted, $kept] = $this->deleteDemoObjects(target: $target, uuids: ($index[$lookupValue] ?? []));
			$removed += $deleted;
			$retained += $kept;
		}

		return [$removed, $retained];
	}//end removeSection()

	/**
	 * Delete the objects of one demo value; an append-only schema keeps them (counted as retained).
	 *
	 * @param DemoSeedTarget $target The section's register and schema.
	 * @param array<int, string> $uuids The object uuids carrying the demo value.
	 *
	 * @return array{0: int, 1: int} The removed and retained counts.
	 *
	 * @throws \Throwable When a delete fails for another reason.
	 */
	private function deleteDemoObjects(DemoSeedTarget $target, array $uuids): array {
		$removed = 0;
		$retained = 0;
		foreach ($uuids as $uuid) {
			try {
				$target->objectService->deleteObject($uuid, $target->registerId, $target->schemaId);
				$removed++;
			} catch (\Throwable $e) {
				if (str_contains($e->getMessage(), 'SCHEMA_ARCHIVAL_IMMUTABLE') === true) {
					// Append-only schema: rows expire via retention cron.
					$retained++;
					break;
				}

				throw $e;
			}
		}

		return [$removed, $retained];
	}//end deleteDemoObjects()

	/**
	 * Resolve the ObjectService, register id, schema ids and seed definitions.
	 *
	 * @return array{0: object, 1: string, 2: array<string, string>, 3: array<string, mixed>}|null
	 *         Null when the register/schemas are not provisioned, or the seed
	 *         file is unreadable.
	 */
	private function resolveContext(): ?array {
		$context = $this->context->resolve(schemaKeys: array_values(array_unique(array_column(self::SECTIONS, 0))));
		if ($context === null) {
			return null;
		}

		$context[3] = $this->mapDefinitionsToTickets(definitions: $context[3]);
		return $context;
	}//end resolveContext()

	/**
	 * Scan a schema once and index it by exact lookup-field value.
	 *
	 * Deliberately scans and matches in PHP instead of pushing an equality
	 * filter down: magic-table filterability is schema-dependent (e.g.
	 * lead.title equality filters silently match nothing on a live install),
	 * and a false negative here would break idempotency. For the same reason
	 * the `ticketType` narrowing is applied in PHP over the scanned rows.
	 *
	 * @param DemoSeedTarget $target The store, register, schema and ticket subtype (null for own-schema sections).
	 * @param string $lookupField Field used as the stable demo identifier.
	 *
	 * @return array{index: array<string, array<int, string>>, rows: array<string, array<string, mixed>>}
	 *         Map of lookup value => uuids, and the scanned rows by uuid.
	 */
	private function buildLookupIndex(DemoSeedTarget $target, string $lookupField): array {
		$existing = $target->objectService->findAll(
			[
				'filters' => [
					'register' => $target->registerId,
					'schema' => $target->schemaId,
				],
				'limit' => 2000,
			]
		);

		$index = [];
		$rows = [];
		if (is_array($existing) === false) {
			return ['index' => $index, 'rows' => $rows];
		}

		$lookup = ['field' => $lookupField, 'ticketType' => $target->ticketType];
		foreach ($existing as $row) {
			$entry = $this->lookupEntry(row: $row, lookup: $lookup);
			if ($entry !== null) {
				$index[$entry['value']][] = $entry['uuid'];
				$rows[$entry['uuid']] = $entry['data'];
			}
		}

		return ['index' => $index, 'rows' => $rows];
	}//end buildLookupIndex()

	/**
	 * The lookup value, uuid and data of one scanned row, or null when the row
	 * is unreadable, of another ticket subtype, or lacks a value or a uuid.
	 *
	 * @param mixed $row The scanned row.
	 * @param array{field: string, ticketType: string|null} $lookup The lookup field and the ticket subtype.
	 *
	 * @return array{value: string, uuid: string, data: array<string, mixed>}|null
	 */
	private function lookupEntry(mixed $row, array $lookup): ?array {
		$data = $this->rowToArray(row: $row);
		if ($data === null) {
			return null;
		}

		if ($lookup['ticketType'] !== null && ($data['ticketType'] ?? null) !== $lookup['ticketType']) {
			return null;
		}

		$value = (string)($data[$lookup['field']] ?? '');
		$uuid = (string)($data['id'] ?? ($data['uuid'] ?? ''));
		if ($value === '' || $uuid === '') {
			return null;
		}

		return ['value' => $value, 'uuid' => $uuid, 'data' => $data];
	}//end lookupEntry()

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
		return $this->writer->withIdentity(data: $data, objectType: $objectType);
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
		$data = $this->values->linkReference(
			data: $data,
			definition: $definition,
			uuids: $uuids,
			keyName: 'clientKey',
			section: 'clients',
			field: ($definition['clientField'] ?? 'client'),
		);

		$data = $this->values->linkReference(
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
		$data = $this->values->linkReference(
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

		$data = $this->values->linkReference(
			data: $data,
			definition: $definition,
			uuids: $uuids,
			keyName: 'requestKey',
			section: 'requests',
			field: $requestField,
		);

		return $data;
	}//end linkReferences()
}//end class
