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
 * mirror value; nothing is ever deleted. It then re-keys every `contactmoment`
 * interaction to the canonical `contactsUid` of its linked `client`, leaving
 * `contactmoment.client` untouched as a soft back-reference.
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
 * @spec openspec/changes/pipelinq-unify-client-contact/specs/unify-client-contact/spec.md#REQ-PUCC-005
 * @spec openspec/changes/pipelinq-unify-client-contact/specs/unify-client-contact/spec.md#REQ-PUCC-006
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Repair;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\ContactVcardService;
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
 * @spec openspec/changes/pipelinq-unify-client-contact/specs/unify-client-contact/spec.md#REQ-PUCC-005
 * @spec openspec/changes/pipelinq-unify-client-contact/specs/unify-client-contact/spec.md#REQ-PUCC-006
 */
class UnifyClientContactIdentity implements IRepairStep
{
    /**
     * Source-system tag written on every MDM sourceRecord this repair creates.
     *
     * @var string
     */
    private const SOURCE_SYSTEM = 'pipelinq-identity-unify';

    /**
     * Constructor.
     *
     * @param IAppManager         $appManager          The app manager.
     * @param IAppConfig          $appConfig           The app configuration.
     * @param ContactVcardService $contactVcardService The existing vCard sync service (reused, no new engine).
     * @param ContainerInterface  $container           The DI container (OR ObjectService + Contacts IManager).
     * @param LoggerInterface     $logger              The logger.
     */
    public function __construct(
        private readonly IAppManager $appManager,
        private readonly IAppConfig $appConfig,
        private readonly ContactVcardService $contactVcardService,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get the name of this repair step.
     *
     * @return string The step name.
     */
    public function getName(): string
    {
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
     * @spec openspec/changes/pipelinq-unify-client-contact/specs/unify-client-contact/spec.md#REQ-PUCC-005
     */
    public function run(IOutput $output): void
    {
        if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
            $output->warning('OpenRegister not installed — skipping client/contact identity unification.');
            return;
        }

        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
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
                .'%d contactmomenten re-keyed, %d unresolved. No identity field, contactmoment.client reference, '
                .'or existing contactsUid was deleted or overwritten.',
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
     * @param string $register      The register id/slug.
     *
     * @return array<string,mixed> Counters + auditMap.
     *
     * @spec openspec/changes/pipelinq-unify-client-contact/specs/unify-client-contact/spec.md#REQ-PUCC-005
     * @spec openspec/changes/pipelinq-unify-client-contact/specs/unify-client-contact/spec.md#REQ-PUCC-006
     */
    public function migrate(object $objectService, string $register): array
    {
        $clientSchema  = $this->appConfig->getValueString(Application::APP_ID, 'client_schema', '');
        $contactSchema = $this->appConfig->getValueString(Application::APP_ID, 'contact_schema', '');
        $momentSchema  = $this->appConfig->getValueString(Application::APP_ID, 'contactmoment_schema', '');

        $clientsLinked  = 0;
        $contactsLinked = 0;
        $skipped        = 0;
        $unresolved     = 0;
        $momentsRekeyed = 0;

        // Maps the client's own id/uuid/slug => its resolved contactsUid, so
        // contactmoment.client (a client UUID back-reference) can be re-keyed.
        $clientUidByRef = [];
        $auditMap       = [];

        if ($clientSchema !== '') {
            foreach ($this->readAll(objectService: $objectService, register: $register, schema: $clientSchema) as $client) {
                $outcome = $this->resolveObject(
                    objectService: $objectService,
                    register: $register,
                    schema: $clientSchema,
                    entityType: 'account',
                    objectType: 'client',
                    object: $client
                );

                $uid = $outcome['contactsUid'];
                if ($uid !== null) {
                    foreach ($this->refsOf(object: $client) as $ref) {
                        $clientUidByRef[$ref] = $uid;
                    }

                    $auditMap[(string) ($client['id'] ?? $client['uuid'] ?? '')] = $uid;
                }

                if ($outcome['status'] === 'linked') {
                    $clientsLinked++;
                } else if ($outcome['status'] === 'skipped') {
                    $skipped++;
                } else {
                    $unresolved++;
                }
            }//end foreach
        }//end if

        if ($contactSchema !== '') {
            foreach ($this->readAll(objectService: $objectService, register: $register, schema: $contactSchema) as $contact) {
                $outcome = $this->resolveObject(
                    objectService: $objectService,
                    register: $register,
                    schema: $contactSchema,
                    entityType: 'contact',
                    objectType: 'contact',
                    object: $contact
                );

                if ($outcome['contactsUid'] !== null) {
                    $auditMap[(string) ($contact['id'] ?? $contact['uuid'] ?? '')] = $outcome['contactsUid'];
                }

                if ($outcome['status'] === 'linked') {
                    $contactsLinked++;
                } else if ($outcome['status'] === 'skipped') {
                    $skipped++;
                } else {
                    $unresolved++;
                }
            }//end foreach
        }//end if

        if ($momentSchema !== '' && $clientUidByRef !== []) {
            $momentsRekeyed = $this->rekeyContactmomenten(
                objectService: $objectService,
                register: $register,
                schema: $momentSchema,
                clientUidByRef: $clientUidByRef
            );
        }

        return [
            'clientsLinked'  => $clientsLinked,
            'contactsLinked' => $contactsLinked,
            'skipped'        => $skipped,
            'unresolved'     => $unresolved,
            'momentsRekeyed' => $momentsRekeyed,
            'auditMap'       => $auditMap,
        ];
    }//end migrate()

    /**
     * Resolve (or create) the NC contact for one client/contact and persist its
     * contactsUid. Idempotent: a non-empty contactsUid is never overwritten.
     * Non-destructive: identity fields are kept as the mirror.
     *
     * @param object              $objectService The OR ObjectService.
     * @param string              $register      The register id/slug.
     * @param string              $schema        The schema id/slug to save back to.
     * @param string              $entityType    MDM entityType ('account' or 'contact').
     * @param string              $objectType    ContactVcardService object type ('client' or 'contact').
     * @param array<string,mixed> $object        The pipelinq object.
     *
     * @return array{status:string, contactsUid:?string} status one of linked/skipped/unresolved.
     */
    private function resolveObject(
        object $objectService,
        string $register,
        string $schema,
        string $entityType,
        string $objectType,
        array $object,
    ): array {
        $existingUid = (string) ($object['contactsUid'] ?? '');
        if ($existingUid !== '') {
            // Already linked on a prior run — no-op (never overwrite).
            return ['status' => 'skipped', 'contactsUid' => $existingUid];
        }

        $objectId = (string) ($object['id'] ?? $object['uuid'] ?? '');
        if ($objectId === '') {
            return ['status' => 'unresolved', 'contactsUid' => null];
        }

        // 1) Try to match an EXISTING NC contact (email first, then KvK/ORG).
        $contactsUid = $this->matchExistingContact(object: $object);

        if ($contactsUid !== null) {
            $this->persistContactsUid(
                objectService: $objectService,
                register: $register,
                schema: $schema,
                object: $object,
                contactsUid: $contactsUid
            );
        } else {
            // 2) No match — create one from the existing identity fields via the
            // EXISTING ContactVcardService::syncToContacts (reuse, no new engine).
            // It writes the vCard AND stores contactsUid back on the object.
            $contactsUid = $this->createContactViaSync(objectType: $objectType, objectId: $objectId);
        }

        if ($contactsUid === null || $contactsUid === '') {
            return ['status' => 'unresolved', 'contactsUid' => null];
        }

        $this->writeSourceRecord(
            objectService: $objectService,
            register: $register,
            entityType: $entityType,
            nativeId: $objectId,
            currentMasterEntity: $contactsUid,
            object: $object
        );

        return ['status' => 'linked', 'contactsUid' => $contactsUid];
    }//end resolveObject()

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
     */
    private function matchExistingContact(array $object): ?string
    {
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
        $email       = (string) ($object['email'] ?? '');
        if ($email !== '') {
            $searchTerms[] = ['term' => $email, 'props' => ['EMAIL', 'UID']];
        }

        if ((string) ($object['type'] ?? '') === 'organization') {
            $name = (string) ($object['name'] ?? '');
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
                $uid = (string) ($hit['UID'] ?? '');
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
     * @param string $objectId   The OR object id/uuid.
     *
     * @return string|null The new contactsUid, or null on failure.
     */
    private function createContactViaSync(string $objectType, string $objectId): ?string
    {
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
     * Persist a matched contactsUid back on the object, keeping every existing
     * identity field in place as the mirror (non-destructive).
     *
     * @param object              $objectService The OR ObjectService.
     * @param string              $register      The register id/slug.
     * @param string              $schema        The schema id/slug.
     * @param array<string,mixed> $object        The pipelinq object.
     * @param string              $contactsUid   The resolved contactsUid.
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
        $objectId = (string) ($object['id'] ?? $object['uuid'] ?? '');
        $payload  = $object;
        $payload['contactsUid'] = $contactsUid;
        unset($payload['@self']);

        try {
            $objectService
                ->setRegister($register)
                ->setSchema($schema)
                ->saveObject(
                    object: $payload,
                    extend: [],
                    register: $register,
                    schema: $schema,
                    uuid: $objectId
                );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Identity unify: failed to persist contactsUid on object',
                ['objectId' => $objectId, 'exception' => $e->getMessage()]
            );
        }
    }//end persistContactsUid()

    /**
     * Re-key every contactmoment whose `client` UUID points at a client that now
     * has a contactsUid. Sets contactmoment.contactsUid; leaves
     * contactmoment.client untouched (soft back-reference). Idempotent: skips a
     * moment whose contactsUid is already set.
     *
     * @param object               $objectService  The OR ObjectService.
     * @param string               $register       The register id/slug.
     * @param string               $schema         The contactmoment schema id/slug.
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
        $rekeyed = 0;
        foreach ($this->readAll(objectService: $objectService, register: $register, schema: $schema) as $moment) {
            if ((string) ($moment['contactsUid'] ?? '') !== '') {
                // Already re-keyed on a prior run — no-op.
                continue;
            }

            $clientRef = (string) ($moment['client'] ?? '');
            if ($clientRef === '' || isset($clientUidByRef[$clientRef]) === false) {
                continue;
            }

            $momentId = (string) ($moment['id'] ?? $moment['uuid'] ?? '');
            $payload  = $moment;
            $payload['contactsUid'] = $clientUidByRef[$clientRef];
            unset($payload['@self']);

            try {
                $objectService
                    ->setRegister($register)
                    ->setSchema($schema)
                    ->saveObject(
                        object: $payload,
                        extend: [],
                        register: $register,
                        schema: $schema,
                        uuid: $momentId
                    );
                $rekeyed++;
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'Identity unify: failed to re-key contactmoment',
                    ['momentId' => $momentId, 'exception' => $e->getMessage()]
                );
            }
        }//end foreach

        return $rekeyed;
    }//end rekeyContactmomenten()

    /**
     * Write one MDM sourceRecord (sourceSystem pipelinq-identity-unify) for a
     * resolved object so the golden-record layer can collapse duplicate
     * identities. Provenance write failure is non-fatal.
     *
     * @param object              $objectService       The OR ObjectService.
     * @param string              $register            The register id/slug.
     * @param string              $entityType          MDM entityType ('account'|'contact').
     * @param string              $nativeId            The pipelinq object id/uuid.
     * @param string              $currentMasterEntity The resolved contactsUid.
     * @param array<string,mixed> $object              The raw object (provenance rawAttributes).
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
        $sourceRecordSchema = $this->appConfig->getValueString(Application::APP_ID, 'sourceRecord_schema', '');
        if ($sourceRecordSchema === '') {
            // MDM source-record schema not configured; provenance is best-effort.
            return;
        }

        $raw = $object;
        unset($raw['@self']);
        $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

        try {
            $objectService
                ->setRegister($register)
                ->setSchema($sourceRecordSchema)
                ->saveObject(
                    object: [
                        'sourceRecordId'      => self::SOURCE_SYSTEM.':'.$nativeId,
                        'sourceSystem'        => self::SOURCE_SYSTEM,
                        'nativeId'            => $nativeId,
                        'entityType'          => $entityType,
                        'currentMasterEntity' => $currentMasterEntity,
                        'rawAttributes'       => $raw,
                        'mappedAttributes'    => ['contactsUid' => $currentMasterEntity],
                        'firstSeen'           => $now,
                        'lastSeen'            => $now,
                        'lastChange'          => $now,
                        'linkageMethod'       => 'system-auto-merge',
                        'linkageConfidence'   => 1.0,
                        'confidence'          => 1.0,
                    ],
                    extend: [],
                    register: $register,
                    schema: $sourceRecordSchema,
                    uuid: null
                );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Identity unify: sourceRecord provenance write failed',
                ['nativeId' => $nativeId, 'exception' => $e->getMessage()]
            );
        }//end try
    }//end writeSourceRecord()

    /**
     * Read every object of a schema in the register (setRegister->setSchema->findAll).
     *
     * @param object $objectService The OR object service.
     * @param string $register      The register id/slug.
     * @param string $schema        The schema id/slug.
     *
     * @return array<int,array<string,mixed>> The objects as plain arrays.
     */
    private function readAll(object $objectService, string $register, string $schema): array
    {
        try {
            $rows = $objectService
                ->setRegister($register)
                ->setSchema($schema)
                ->findAll([]);
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
    private function refsOf(array $object): array
    {
        $refs = [
            (string) ($object['id'] ?? ''),
            (string) ($object['uuid'] ?? ''),
            (string) ($object['@self']['id'] ?? ''),
            (string) ($object['@self']['uuid'] ?? ''),
            (string) ($object['@self']['slug'] ?? ''),
        ];

        return array_values(array_unique(array_filter($refs, static fn ($r) => $r !== '')));
    }//end refsOf()

    /**
     * Resolve the OpenRegister ObjectService, or null when unavailable.
     *
     * @return object|null The object service, or null.
     */
    private function resolveObjectService(): ?object
    {
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

    /**
     * Normalise an OR object (entity or array) into a plain array.
     *
     * @param mixed $object The OR object.
     *
     * @return array<string,mixed> The object as an array.
     */
    private function toArray(mixed $object): array
    {
        if (is_array($object) === true) {
            return $object;
        }

        if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
            $serialized = $object->jsonSerialize();
            if (is_array($serialized) === true) {
                return $serialized;
            }
        }

        if (is_object($object) === true && method_exists($object, 'getObject') === true) {
            $data = $object->getObject();
            if (is_array($data) === true) {
                return $data;
            }
        }

        return (array) $object;
    }//end toArray()
}//end class
