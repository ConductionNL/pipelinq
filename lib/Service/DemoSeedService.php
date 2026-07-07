<?php

/**
 * Pipelinq DemoSeedService.
 *
 * Idempotent demo-data seed shared by the `occ pipelinq:demo:seed` command and
 * the optional `seed-demo-data` setup-wizard action (one write path, ADR-042).
 * Seeds a small coherent linked dataset — clients (person + organisation),
 * leads across pipeline stages, requests across statuses, and contactmomenten
 * across channels — from lib/Settings/demo_seed_data.json so lists, dashboards
 * and the 360° client view render populated on a fresh install.
 *
 * Every seeded object is marked with the `[Demo]` prefix on its lookup field
 * (client.name / lead.title / request.title / contactmoment.subject). The seed
 * is idempotent via exact-match lookup on that field, and `remove()` deletes
 * exactly the seeded set and nothing else. Client identities are provisioned
 * contact-first via ContactVcardService (the client schema requires
 * `contactsUid`); the backing Nextcloud addressbook contacts are matched or
 * created by that service and are intentionally NOT deleted by `remove()` —
 * they may be pre-existing addressbook entries the provisioning matched.
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
 * @spec openspec/changes/align-claims-and-first-hour/specs/first-time-setup/spec.md#requirement-req-setup-pip-008--optional-demo-data-seed
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
 * @spec openspec/changes/align-claims-and-first-hour/specs/first-time-setup/spec.md#requirement-req-setup-pip-008--optional-demo-data-seed
 */
class DemoSeedService
{
    /**
     * Marker prefix carried by every seeded object's lookup field.
     *
     * @var string
     */
    public const DEMO_PREFIX = '[Demo]';

    /**
     * Entity sections in seed order: section => [schemaConfigKey, lookupField].
     *
     * Seed order matters (clients before the objects that link to them);
     * removal runs in reverse.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const SECTIONS = [
        'clients'         => ['client_schema', 'name'],
        'leads'           => ['lead_schema', 'title'],
        'requests'        => ['request_schema', 'title'],
        'contactmomenten' => ['contactmoment_schema', 'subject'],
    ];

    /**
     * Constructor.
     *
     * @param IAppConfig          $appConfig           App config (register/schema ids).
     * @param ContainerInterface  $container           Container for the OpenRegister ObjectService.
     * @param ContactVcardService $contactVcardService Contact-first identity provisioning (client.contactsUid).
     * @param LoggerInterface     $logger              Logger.
     */
    public function __construct(
        private readonly IAppConfig $appConfig,
        private readonly ContainerInterface $container,
        private readonly ContactVcardService $contactVcardService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Seed the demo dataset (idempotent: existing demo objects are reused).
     *
     * @return array{success: bool, message?: string, created: array<string, int>, skipped: array<string, int>}
     *
     * @spec openspec/changes/align-claims-and-first-hour/specs/first-time-setup/spec.md#requirement-req-setup-pip-008--optional-demo-data-seed
     */
    public function seed(): array
    {
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

        foreach (self::SECTIONS as $section => [$schemaKey, $lookupField]) {
            $created[$section] = 0;
            $skipped[$section] = 0;

            // One scan per schema: value => [uuid, ...] index for idempotency.
            $index = $this->buildLookupIndex(
                objectService: $objectService,
                registerId: $registerId,
                schemaId: $schemaIds[$schemaKey],
                lookupField: $lookupField,
            );

            foreach (($definitions[$section] ?? []) as $definition) {
                $data = $this->resolvePlaceholders(data: $definition['data']);
                $data = $this->linkReferences(data: $data, definition: $definition, uuids: $uuids);

                $existingUuid = $index[(string) $data[$lookupField]][0] ?? null;

                if ($existingUuid !== null) {
                    $uuids[$section.':'.$definition['key']] = $existingUuid;
                    $skipped[$section]++;
                    continue;
                }

                // Contact-first unification: the client schema marks contactsUid
                // REQUIRED (FK to the authoritative Nextcloud addressbook contact,
                // never minted locally), so provision the NC contact before the
                // object is saved — same path the create surface uses. Matching an
                // existing contact by email keeps re-seeding after --remove
                // duplicate-free on the addressbook side too.
                if ($section === 'clients') {
                    $provision = $this->contactVcardService->provisionContactFromForm(
                        form: $data,
                        objectType: 'client'
                    );

                    if ($provision === null) {
                        return [
                            'success' => false,
                            'message' => 'Nextcloud Contacts is unavailable — cannot provision demo client identities.',
                            'created' => $created,
                            'skipped' => $skipped,
                        ];
                    }

                    $data['contactsUid'] = $provision['contactsUid'];
                }

                $saved = $objectService->saveObject(
                    $data,
                    [],
                    $registerId,
                    $schemaIds[$schemaKey],
                    null
                );

                $uuids[$section.':'.$definition['key']] = (string) $saved->getUuid();
                $created[$section]++;
            }//end foreach
        }//end foreach

        return ['success' => true, 'created' => $created, 'skipped' => $skipped];
    }//end seed()

    /**
     * Remove exactly the seeded demo set (lookup-field exact match, [Demo] prefix guarded).
     *
     * Archival (append-only) schemas — contactmoment declares
     * x-openregister-archival — forbid user-driven deletes by design; their
     * demo rows are reported as `retained` and expire via the OpenRegister
     * ArchivalRetentionTask cron instead.
     *
     * @return array{success: bool, message?: string, removed: array<string, int>, retained: array<string, int>}
     *
     * @spec openspec/changes/align-claims-and-first-hour/specs/first-time-setup/spec.md#requirement-req-setup-pip-008--optional-demo-data-seed
     */
    public function remove(): array
    {
        $context = $this->resolveContext();
        if ($context === null) {
            return [
                'success'  => false,
                'message'  => 'Pipelinq register or schemas are not provisioned — nothing to remove.',
                'removed'  => [],
                'retained' => [],
            ];
        }

        [$objectService, $registerId, $schemaIds, $definitions] = $context;

        $removed  = [];
        $retained = [];
        $sections = array_reverse(self::SECTIONS, true);

        foreach ($sections as $section => [$schemaKey, $lookupField]) {
            $removed[$section]  = 0;
            $retained[$section] = 0;

            // One scan per schema: value => [uuid, ...] index. Multiple uuids
            // per value guard against duplicates from historic runs.
            $index = $this->buildLookupIndex(
                objectService: $objectService,
                registerId: $registerId,
                schemaId: $schemaIds[$schemaKey],
                lookupField: $lookupField,
            );

            foreach (($definitions[$section] ?? []) as $definition) {
                $lookupValue = (string) ($definition['data'][$lookupField] ?? '');
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
     * Resolve the ObjectService, register id, schema ids and seed definitions.
     *
     * @return array{0: object, 1: string, 2: array<string, string>, 3: array<string, array<int, array<string, mixed>>>}|null
     *         Null when the register/schemas are not provisioned or the seed file is unreadable.
     */
    private function resolveContext(): ?array
    {
        $registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        if ($registerId === '') {
            return null;
        }

        $schemaIds = [];
        foreach (self::SECTIONS as [$schemaKey]) {
            $schemaId = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');
            if ($schemaId === '') {
                return null;
            }

            $schemaIds[$schemaKey] = $schemaId;
        }

        $definitions = $this->loadDefinitions();
        if ($definitions === null) {
            return null;
        }

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
    private function loadDefinitions(): ?array
    {
        $path = dirname(__DIR__).'/Settings/demo_seed_data.json';
        if (is_readable($path) === false) {
            $this->logger->error('Pipelinq demo seed: demo_seed_data.json missing', ['path' => $path]);
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
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
     * and a false negative here would break idempotency.
     *
     * @param object $objectService The OpenRegister ObjectService.
     * @param string $registerId    Register id.
     * @param string $schemaId      Schema id.
     * @param string $lookupField   Field used as the stable demo identifier.
     *
     * @return array<string, array<int, string>> Map of lookup value => uuids.
     */
    private function buildLookupIndex(
        object $objectService,
        string $registerId,
        string $schemaId,
        string $lookupField,
    ): array {
        $existing = $objectService->findAll(
            [
                'filters' => [
                    'register' => $registerId,
                    'schema'   => $schemaId,
                ],
                'limit'   => 2000,
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

            $value = (string) ($data[$lookupField] ?? '');
            $uuid  = (string) ($data['id'] ?? ($data['uuid'] ?? ''));
            if ($value === '' || $uuid === '') {
                continue;
            }

            $index[$value][] = $uuid;
        }

        return $index;
    }//end buildLookupIndex()

    /**
     * Normalise a findAll row (ObjectEntity or rendered array) to an array.
     *
     * @param mixed $row The row.
     *
     * @return array<string, mixed>|null The rendered data, or null when unreadable.
     */
    private function rowToArray(mixed $row): ?array
    {
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
    private function resolvePlaceholders(array $data): array
    {
        foreach ($data as $field => $value) {
            if (is_string($value) === false) {
                continue;
            }

            if (preg_match('/^@(days|datetime):(-?\d+)$/', $value, $matches) !== 1) {
                continue;
            }

            $offset = (int) $matches[2];
            $moment = (new DateTimeImmutable())->modify(sprintf('%+d days', $offset));

            $data[$field] = $moment->format(DateTimeInterface::ATOM);
            if ($matches[1] === 'days') {
                $data[$field] = $moment->format('Y-m-d');
            }
        }

        return $data;
    }//end resolvePlaceholders()

    /**
     * Wire clientKey / requestKey references onto the payload as uuids.
     *
     * @param array<string, mixed>  $data       Definition data.
     * @param array<string, mixed>  $definition Full definition (may carry clientKey/requestKey).
     * @param array<string, string> $uuids      Already-seeded uuid map (section:key => uuid).
     *
     * @return array<string, mixed> Data with relation fields set.
     */
    private function linkReferences(array $data, array $definition, array $uuids): array
    {
        if (isset($definition['clientKey']) === true) {
            $clientUuid = $uuids['clients:'.$definition['clientKey']] ?? null;
            if ($clientUuid !== null) {
                $data['client'] = $clientUuid;
            }
        }

        if (isset($definition['requestKey']) === true) {
            $requestUuid = $uuids['requests:'.$definition['requestKey']] ?? null;
            if ($requestUuid !== null) {
                $data['request'] = $requestUuid;
            }
        }

        return $data;
    }//end linkReferences()
}//end class
