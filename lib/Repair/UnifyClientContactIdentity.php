<?php

/**
 * Pipelinq UnifyClientContactIdentity.
 *
 * Idempotent, non-destructive repair step that makes the Nextcloud addressbook
 * the single person/organisation identity store for pipelinq (CROSS-APP
 * INTERFACE CONTRACT #2). For every existing `client` and `contact` object it
 * resolves an existing Nextcloud Contact (matching on email first, then KvK/ORG
 * for organisations) — or creates one from the object's existing identity
 * fields via the EXISTING ContactVcardService — and stores the resulting
 * `contactsUid` on the object. The (now denormalised) identity fields
 * (`name`/`email`/`phone`/`address`/`website`) are KEPT in place as the initial
 * mirror value; nothing is ever deleted. It then re-keys every contactmoment
 * interaction to the canonical `contactsUid` of its linked `client`, leaving
 * the contactmoment's `client` reference untouched as a soft back-reference.
 * Contactmomenten are a subtype of the unified `ticket` schema
 * (unify-ticket-supertype) and are read with a `ticketType=contactmoment`
 * filter rather than from a schema of their own.
 *
 * Provenance: one MDM `sourceRecord` (sourceSystem `pipelinq-identity-unify`) is
 * written per resolved object so the golden-record/dedup layer can later
 * collapse duplicate identities. The step never overwrites a non-empty
 * `contactsUid`, is safe to re-run, and is resumable after a partial run. It is
 * fail-safe: when OpenRegister or the Contacts manager is unavailable it logs a
 * warning and exits cleanly without marking the migration complete or deleting
 * any data.
 *
 * @category Repair
 * @package  OCA\Pipelinq\Repair
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/unify-client-contact/spec.md
 * @spec openspec/specs/unify-client-contact/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Repair;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Kernel\Contracts\ContactVcardServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\TicketServiceInterface;
use OCA\Pipelinq\Service\ContactVcardService;
use OCA\Pipelinq\Util\CastingPayloadToArray;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * One-shot, re-runnable repair step that resolves every client/contact to a
 * Nextcloud Contact (contactsUid) and re-keys contactmoment interactions.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) A cohesive idempotent migration: resolve
 *  client identities, resolve contact identities, match-or-create NC contacts, and re-key
 *  contactmomenten are one unit of work, deliberately kept in a single repair step.
 *
 * @spec openspec/specs/unify-client-contact/spec.md
 * @spec openspec/specs/unify-client-contact/spec.md
 */
class UnifyClientContactIdentity implements IRepairStep {
	use CastingPayloadToArray;

	/**
	 * Source-system tag written on every MDM sourceRecord this repair creates.
	 *
	 * @var string
	 */
	private const SOURCE_SYSTEM = 'pipelinq-identity-unify';

	/**
	 * Constructor.
	 *
	 * @param IAppManager $appManager The app manager.
	 * @param IAppConfig $appConfig The app configuration.
	 * @param ContactVcardServiceInterface $contactVcardService The existing vCard sync service (reused, no new engine).
	 * @param ContainerInterface $container The DI container (OR ObjectService + Contacts IManager).
	 * @param TicketServiceInterface $ticketService Resolver for the unified `ticket` supertype.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly IAppConfig $appConfig,
		private readonly ContactVcardServiceInterface $contactVcardService,
		private readonly ContainerInterface $container,
		private readonly TicketServiceInterface $ticketService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the name of this repair step.
	 *
	 * @return string The step name.
	 *
	 * @spec exclude repair-step display name accessor, no behavioural spec surface.
	 */
	public function getName(): string {
		return 'Unify pipelinq client/contact identity on Nextcloud contacts (idempotent, non-destructive)';
	}//end getName()

	/**
	 * Run the migration.
	 *
	 * Fail-safe: every failure is caught and reported as a warning; the upgrade
	 * is never failed. Non-destructive: no identity field is deleted, no
	 * contactmoment.client reference is removed, no non-empty contactsUid is
	 * overwritten. Re-runnable and resumable.
	 *
	 * @param IOutput $output The output interface.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/unify-client-contact/spec.md
	 */
	public function run(IOutput $output): void {
		if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
			$output->warning('OpenRegister not installed — skipping client/contact identity unification.');
			return;
		}

		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', '');
		if ($register === '') {
			$output->warning('Pipelinq register not configured — skipping client/contact identity unification.');
			return;
		}

		$objectService = $this->resolveObjectService();
		if ($objectService === null) {
			$output->warning('OpenRegister ObjectService unavailable — skipping client/contact identity unification.');
			return;
		}

		$result = $this->migrate(objectService: $objectService, register: $register);

		$output->info(
			sprintf(
				'Client/contact identity unification: %d clients linked, %d contacts linked, %d already-linked skipped, '
				. '%d contactmomenten re-keyed, %d unresolved. No identity field, contactmoment.client reference, '
				. 'or existing contactsUid was deleted or overwritten.',
				$result['clientsLinked'],
				$result['contactsLinked'],
				$result['skipped'],
				$result['momentsRekeyed'],
				$result['unresolved']
			)
		);
		$this->logger->info('Client/contact identity unification completed', $result);
	}//end run()

	/**
	 * Perform the full unification. Returns a counters summary plus the
	 * {objectId => contactsUid} audit map under the `auditMap` key.
	 *
	 * Directly callable from a unit test with a stubbed ObjectService.
	 *
	 * @param object $objectService The OR ObjectService (setRegister->setSchema->findAll/saveObject).
	 * @param string $register The register id/slug.
	 *
	 * @return array<string,mixed> Counters + auditMap.
	 *
	 * @spec openspec/specs/unify-client-contact/spec.md
	 * @spec openspec/specs/unify-client-contact/spec.md
	 */
	public function migrate(object $objectService, string $register): array {
		$clientSchema = $this->appConfig->getValueString(AppIdentity::APP_ID, 'client_schema', '');
		$contactSchema = $this->appConfig->getValueString(AppIdentity::APP_ID, 'contact_schema', '');

		// Contactmomenten live in the unified `ticket` schema and are narrowed by
		// the `ticketType` discriminator (unify-ticket-supertype).
		$momentSchema = $this->ticketService->getSchemaId();

		$clients = new IdentityCollection(objectService: $objectService, register: $register, schema: $clientSchema);
		$contacts = new IdentityCollection(objectService: $objectService, register: $register, schema: $contactSchema);
		$tally = new IdentityTally();
		$this->resolveCollection(pass: new IdentityPass(collection: $clients, kind: IdentityKind::client()), tally: $tally);
		$this->resolveCollection(pass: new IdentityPass(collection: $contacts, kind: IdentityKind::contact()), tally: $tally);

		$moments = new IdentityCollection(objectService: $objectService, register: $register, schema: $momentSchema);
		return $tally->summary(momentsRekeyed: $this->rekeyClientMoments(moments: $moments, tally: $tally));
	}//end migrate()

	/**
	 * Resolve every object of one collection and tally the outcomes; an
	 * unconfigured collection is skipped.
	 *
	 * @param IdentityPass  $pass  The collection and the kind of identity in it.
	 * @param IdentityTally $tally The tally of this migrate() call.
	 *
	 * @return void
	 */
	private function resolveCollection(IdentityPass $pass, IdentityTally $tally): void {
		$collection = $pass->collection;
		if ($collection->schema === '') {
			return;
		}

		foreach ($this->readAll(objectService: $collection->objectService, register: $collection->register, schema: $collection->schema) as $object) {
			$outcome = $this->resolveObject(pass: $pass, object: $object);
			$uid = $outcome['contactsUid'];
			if ($uid !== null) {
				$tally->mapClientRefs(refs: $this->clientRefsOf(pass: $pass, object: $object), contactsUid: $uid);
				$tally->audit(object: $object, contactsUid: $uid);
			}

			$tally->count(status: $outcome['status'], kind: $pass->kind);
		}
	}//end resolveCollection()

	/**
	 * The references a contactmoment may use for a client object; none for
	 * a contact, whose contactmomenten are not re-keyed.
	 *
	 * @param IdentityPass        $pass   The collection and its kind.
	 * @param array<string,mixed> $object The object.
	 *
	 * @return array<int,string> The references.
	 */
	private function clientRefsOf(IdentityPass $pass, array $object): array {
		if ($pass->kind->isClient() === false) {
			return [];
		}

		return $this->refsOf(object: $object);
	}//end clientRefsOf()

	/**
	 * Re-key the contactmomenten of the clients this pass linked; nothing
	 * when there is no ticket schema or no client was linked.
	 *
	 * @param IdentityCollection $moments The ticket schema collection.
	 * @param IdentityTally      $tally   The tally holding the linked client references.
	 *
	 * @return int The number of contactmomenten re-keyed.
	 */
	private function rekeyClientMoments(IdentityCollection $moments, IdentityTally $tally): int {
		if ($moments->schema === '' || $tally->clientUidByRef() === []) {
			return 0;
		}

		return $this->rekeyContactmomenten(
			objectService: $moments->objectService,
			register: $moments->register,
			schema: $moments->schema,
			clientUidByRef: $tally->clientUidByRef()
		);
	}//end rekeyClientMoments()

	/**
	 * Resolve (or create) the NC contact for one client/contact and persist its
	 * contactsUid. Idempotent: a non-empty contactsUid is never overwritten.
	 * Non-destructive: identity fields are kept as the mirror.
	 *
	 * @param IdentityPass        $pass   The collection to save back to and the kind of identity.
	 * @param array<string,mixed> $object The pipelinq object.
	 *
	 * @return array{status:string, contactsUid:?string} status one of linked/skipped/unresolved.
	 */
	private function resolveObject(IdentityPass $pass, array $object): array {
		$existingUid = (string)($object['contactsUid'] ?? '');
		if ($existingUid !== '') {
			// Already linked on a prior run — no-op (never overwrite).
			return ['status' => 'skipped', 'contactsUid' => $existingUid];
		}

		if ($this->objectIdOf(object: $object) === '') {
			return ['status' => 'unresolved', 'contactsUid' => null];
		}

		return $this->linkObject(pass: $pass, object: $object);
	}//end resolveObject()

	/**
	 * Link an object with an id to a Nextcloud contact and write its
	 * provenance record.
	 *
	 * @param IdentityPass        $pass   The collection and the kind of identity.
	 * @param array<string,mixed> $object The pipelinq object.
	 *
	 * @return array{status:string, contactsUid:?string} linked, or unresolved when no contact could be found or made.
	 */
	private function linkObject(IdentityPass $pass, array $object): array {
		$contactsUid = $this->resolveContactsUid(pass: $pass, object: $object);
		if ($contactsUid === null || $contactsUid === '') {
			return ['status' => 'unresolved', 'contactsUid' => null];
		}

		$this->writeSourceRecord(
			objectService: $pass->collection->objectService,
			register: $pass->collection->register,
			entityType: $pass->kind->entityType,
			nativeId: $this->objectIdOf(object: $object),
			currentMasterEntity: $contactsUid,
			object: $object
		);

		return ['status' => 'linked', 'contactsUid' => $contactsUid];
	}//end linkObject()

	/**
	 * The contactsUid of an object: an existing NC contact, persisted on the
	 * object, or else a new one made through ContactVcardService.
	 *
	 * @param IdentityPass        $pass   The collection and the kind of identity.
	 * @param array<string,mixed> $object The pipelinq object.
	 *
	 * @return string|null The contactsUid, or null when none could be found or made.
	 */
	private function resolveContactsUid(IdentityPass $pass, array $object): ?string {
		// 1) Try to match an EXISTING NC contact (email first, then KvK/ORG).
		$contactsUid = $this->matchExistingContact(object: $object);
		if ($contactsUid !== null) {
			$this->persistContactsUid(
				objectService: $pass->collection->objectService,
				register: $pass->collection->register,
				schema: $pass->collection->schema,
				object: $object,
				contactsUid: $contactsUid
			);
			return $contactsUid;
		}

		// 2) No match — create one from the existing identity fields via the
		// EXISTING ContactVcardService::syncToContacts (reuse, no new engine).
		// It writes the vCard AND stores contactsUid back on the object.
		return $this->createContactViaSync(objectType: $pass->kind->objectType, objectId: $this->objectIdOf(object: $object));
	}//end resolveContactsUid()

	/**
	 * The id of a pipelinq object: its id, else its uuid, else empty.
	 *
	 * @param array<string,mixed> $object The object.
	 *
	 * @return string The id.
	 */
	private function objectIdOf(array $object): string {
		return (string)($object['id'] ?? $object['uuid'] ?? '');
	}//end objectIdOf()

	/**
	 * Match an existing Nextcloud Contact for an object by email first, then by
	 * KvK/ORG for organisations. Returns the matched UID or null.
	 *
	 * Uses the Contacts IManager search (positional args) the same way the
	 * existing contact-sync/import flow does. The OR `contacts-actions`
	 * integration provider fronts the same IManager surface; this resolution
	 * stays inside that boundary and never issues a hard-coded cross-app HTTP
	 * call (ADR-022).
	 *
	 * @param array<string,mixed> $object The pipelinq object.
	 *
	 * @return string|null The matched contactsUid, or null when none matches.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) IManager availability guard, prioritised
	 *   email/ORG search-term assembly, and a per-term search loop with per-hit UID extraction —
	 *   a cohesive best-effort match wrapped in fault-tolerant try/catch blocks.
	 * @SuppressWarnings(PHPMD.NPathComplexity)      Same rationale: independent guards and the
	 *   search/hit loops inflate the theoretical path count.
	 */
	private function matchExistingContact(array $object): ?string {
		try {
			$contactsManager = $this->container->get('OCP\Contacts\IManager');
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Identity unify: Contacts IManager not resolvable',
				['exception' => $e->getMessage()]
			);
			return null;
		}

		if (method_exists($contactsManager, 'isEnabled') === true && $contactsManager->isEnabled() === false) {
			return null;
		}

		// Match priority: email, then ORG name for organisations (KvK is carried
		// as the ORG/X-KVK vCard property by the property builder).
		$searchTerms = [];
		$email = (string)($object['email'] ?? '');
		if ($email !== '') {
			$searchTerms[] = ['term' => $email, 'props' => ['EMAIL', 'UID']];
		}

		if ((string)($object['type'] ?? '') === 'organization') {
			$name = (string)($object['name'] ?? '');
			if ($name !== '') {
				$searchTerms[] = ['term' => $name, 'props' => ['ORG', 'X-KVK', 'UID']];
			}
		}

		foreach ($searchTerms as $search) {
			try {
				// Positional args — IManager::search($pattern, $searchProperties, $options).
				$results = $contactsManager->search($search['term'], $search['props'], []);
			} catch (\Throwable $e) {
				$this->logger->warning(
					'Identity unify: contact search failed',
					['term' => $search['term'], 'exception' => $e->getMessage()]
				);
				continue;
			}

			foreach (($results ?? []) as $hit) {
				$uid = (string)($hit['UID'] ?? '');
				if ($uid !== '') {
					return $uid;
				}
			}
		}

		return null;
	}//end matchExistingContact()

	/**
	 * Create a Nextcloud Contact from the object's existing identity fields by
	 * delegating to the EXISTING ContactVcardService::syncToContacts, which
	 * writes the vCard and stores the new contactsUid back on the object.
	 *
	 * @param string $objectType The object type ('client' or 'contact').
	 * @param string $objectId The OR object id/uuid.
	 *
	 * @return string|null The new contactsUid, or null on failure.
	 */
	private function createContactViaSync(string $objectType, string $objectId): ?string {
		try {
			$uid = $this->contactVcardService->syncToContacts(objectType: $objectType, objectId: $objectId);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Identity unify: contact creation via ContactVcardService failed',
				['objectType' => $objectType, 'objectId' => $objectId, 'exception' => $e->getMessage()]
			);
			return null;
		}

		if ($uid === null || $uid === '') {
			return null;
		}

		return $uid;
	}//end createContactViaSync()

	/**
	 * Execute an OpenRegister write under the scoped system-operation context.
	 *
	 * Repair steps run without a user session (web-triggered upgrades, webcron),
	 * so OpenRegister RBAC denies every write as 'Anonymous'
	 * (NotAuthorizedException). ObjectService::runAsSystem scopes trusted-system
	 * elevation to the callable; older OpenRegister versions without it fall
	 * back to the direct call.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param callable $operation The write operation to execute.
	 *
	 * @return mixed Whatever the operation returns.
	 *
	 * @spec exclude system-context adoption — back-compat elevation shim around OR writes, no behavioural spec surface.
	 */
	private function execAsSystem(object $objectService, callable $operation): mixed {
		if (method_exists($objectService, 'runAsSystem') === true) {
			return $objectService->runAsSystem($operation);
		}

		return $operation();
	}//end execAsSystem()

	/**
	 * Persist a matched contactsUid back on the object, keeping every existing
	 * identity field in place as the mirror (non-destructive).
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param string $register The register id/slug.
	 * @param string $schema The schema id/slug.
	 * @param array<string,mixed> $object The pipelinq object.
	 * @param string $contactsUid The resolved contactsUid.
	 *
	 * @return void
	 */
	private function persistContactsUid(
		object $objectService,
		string $register,
		string $schema,
		array $object,
		string $contactsUid,
	): void {
		$objectId = (string)($object['id'] ?? $object['uuid'] ?? '');
		$payload = $object;
		$payload['contactsUid'] = $contactsUid;
		unset($payload['@self']);

		try {
			$this->execAsSystem(
				objectService: $objectService,
				operation: static fn () => $objectService
					->setRegister($register)
					->setSchema($schema)
					->saveObject(
						object: $payload,
						extend: [],
						register: $register,
						schema: $schema,
						uuid: $objectId
					)
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Identity unify: failed to persist contactsUid on object',
				['objectId' => $objectId, 'exceptionClass' => get_class($e), 'exception' => $e->getMessage()]
			);
		}
	}//end persistContactsUid()

	/**
	 * Re-key every contactmoment whose `client` UUID points at a client that now
	 * has a contactsUid. Sets the contactmoment's contactsUid; leaves its `client`
	 * reference untouched (soft back-reference). Idempotent: skips a moment whose
	 * contactsUid is already set.
	 *
	 * Contactmomenten are read from the unified `ticket` schema with a
	 * `ticketType=contactmoment` filter; the saved payload carries the row's own
	 * `ticketType` through unchanged.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param string $register The register id/slug.
	 * @param string $schema The ticket schema id/slug.
	 * @param array<string,string> $clientUidByRef Map of client ref => contactsUid.
	 *
	 * @return int The number of contactmomenten re-keyed.
	 */
	private function rekeyContactmomenten(
		object $objectService,
		string $register,
		string $schema,
		array $clientUidByRef,
	): int {
		$moments = $this->readAll(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			ticketType: TicketServiceInterface::TYPE_CONTACTMOMENT
		);

		$rekeyed = 0;
		foreach ($moments as $moment) {
			if ((string)($moment['contactsUid'] ?? '') !== '') {
				// Already re-keyed on a prior run — no-op.
				continue;
			}

			$clientRef = (string)($moment['client'] ?? '');
			if ($clientRef === '' || isset($clientUidByRef[$clientRef]) === false) {
				continue;
			}

			$momentId = (string)($moment['id'] ?? $moment['uuid'] ?? '');
			$payload = $moment;
			$payload['contactsUid'] = $clientUidByRef[$clientRef];
			unset($payload['@self']);

			try {
				$this->execAsSystem(
					objectService: $objectService,
					operation: static fn () => $objectService
						->setRegister($register)
						->setSchema($schema)
						->saveObject(
							object: $payload,
							extend: [],
							register: $register,
							schema: $schema,
							uuid: $momentId
						)
				);
				$rekeyed++;
			} catch (\Throwable $e) {
				$this->logger->warning(
					'Identity unify: failed to re-key contactmoment',
					['momentId' => $momentId, 'exceptionClass' => get_class($e), 'exception' => $e->getMessage()]
				);
			}//end try
		}//end foreach

		return $rekeyed;
	}//end rekeyContactmomenten()

	/**
	 * Write one MDM sourceRecord (sourceSystem pipelinq-identity-unify) for a
	 * resolved object so the golden-record layer can collapse duplicate
	 * identities. Provenance write failure is non-fatal.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param string $register The register id/slug.
	 * @param string $entityType MDM entityType ('account'|'contact').
	 * @param string $nativeId The pipelinq object id/uuid.
	 * @param string $currentMasterEntity The resolved contactsUid.
	 * @param array<string,mixed> $object The raw object (provenance rawAttributes).
	 *
	 * @return void
	 */
	private function writeSourceRecord(
		object $objectService,
		string $register,
		string $entityType,
		string $nativeId,
		string $currentMasterEntity,
		array $object,
	): void {
		$sourceRecordSchema = $this->appConfig->getValueString(AppIdentity::APP_ID, 'sourceRecord_schema', '');
		if ($sourceRecordSchema === '') {
			// MDM source-record schema not configured; provenance is best-effort.
			return;
		}

		$raw = $object;
		unset($raw['@self']);
		$now = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);

		try {
			$this->execAsSystem(
				objectService: $objectService,
				operation: static fn () => $objectService
					->setRegister($register)
					->setSchema($sourceRecordSchema)
					->saveObject(
						object: [
							'sourceRecordId' => self::SOURCE_SYSTEM . ':' . $nativeId,
							'sourceSystem' => self::SOURCE_SYSTEM,
							'nativeId' => $nativeId,
							'entityType' => $entityType,
							'currentMasterEntity' => $currentMasterEntity,
							'rawAttributes' => $raw,
							'mappedAttributes' => ['contactsUid' => $currentMasterEntity],
							'firstSeen' => $now,
							'lastSeen' => $now,
							'lastChange' => $now,
							'linkageMethod' => 'system-auto-merge',
							'linkageConfidence' => 1.0,
							'confidence' => 1.0,
						],
						extend: [],
						register: $register,
						schema: $sourceRecordSchema,
						uuid: null
					)
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Identity unify: sourceRecord provenance write failed',
				['nativeId' => $nativeId, 'exceptionClass' => get_class($e), 'exception' => $e->getMessage()]
			);
		}//end try
	}//end writeSourceRecord()

	/**
	 * Read every object of a schema in the register (setRegister->setSchema->findAll).
	 *
	 * A ticket-backed surface narrows the shared `ticket` schema with the
	 * `ticketType` discriminator so only its own subtype is read.
	 *
	 * @param object $objectService The OR object service.
	 * @param string $register The register id/slug.
	 * @param string $schema The schema id/slug.
	 * @param string|null $ticketType The ticket subtype, or null for a plain schema.
	 *
	 * @return array<int,array<string,mixed>> The objects as plain arrays.
	 */
	private function readAll(
		object $objectService,
		string $register,
		string $schema,
		?string $ticketType = null,
	): array {
		$config = [];
		if ($ticketType !== null) {
			$config = ['filters' => ['ticketType' => $ticketType]];
		}

		try {
			$rows = $objectService
				->setRegister($register)
				->setSchema($schema)
				->findAll($config);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Identity unify: failed to read objects',
				['schema' => $schema, 'exception' => $e->getMessage()]
			);
			return [];
		}

		$out = [];
		foreach (($rows ?? []) as $row) {
			$out[] = $this->toArray(object: $row);
		}

		return $out;
	}//end readAll()

	/**
	 * Every reference under which a client may be linked by contactmoment.client
	 * (id, uuid, slug), so the re-key lookup hits regardless of which form was
	 * stored.
	 *
	 * @param array<string,mixed> $object The client object.
	 *
	 * @return array<int,string> The non-empty reference values.
	 */
	private function refsOf(array $object): array {
		$refs = [
			(string)($object['id'] ?? ''),
			(string)($object['uuid'] ?? ''),
			(string)($object['@self']['id'] ?? ''),
			(string)($object['@self']['uuid'] ?? ''),
			(string)($object['@self']['slug'] ?? ''),
		];

		return array_values(array_unique(array_filter($refs, static fn ($r) => $r !== '')));
	}//end refsOf()

	/**
	 * Resolve the OpenRegister ObjectService, or null when unavailable.
	 *
	 * @return object|null The object service, or null.
	 */
	private function resolveObjectService(): ?object {
		try {
			return $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Identity unify: OpenRegister ObjectService not resolvable',
				['exception' => $e->getMessage()]
			);
			return null;
		}
	}//end resolveObjectService()
}//end class
