<?php

/**
 * Pipelinq PipelinqEvidenceSourceProvider.
 *
 * Registers pipelinq's CRM objects (client, contact, lead, request,
 * contactmoment) as an evidence source in OpenRegister's DSAR case engine
 * (ADR-047 Phase 3 / ADR-019 seam). OpenRegister's EvidenceHarvestService
 * enumerates only providers registered into its EvidenceSourceRegistry, so
 * this provider is what makes pipelinq data reachable during a data-subject
 * request; without it pipelinq contributes no evidence.
 *
 * The provider reads the case's `subjectId` / `subjectType`, matches pipelinq
 * objects that belong to that subject (own id / contactsUid, or a `client` /
 * `contact` foreign key), and returns one EvidenceItem per match with a stable
 * `contentHash` (idempotent dedupe across re-runs) and per-item status. The
 * subject identifier value is never logged.
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
 * @spec openspec/changes/consume-or-dsar/specs/avg-verzoeken-workflow/spec.md#requirement-req-avg-016--pipelinq-evidence-source-registration
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\OpenRegister\Service\Gdpr\Evidence\EvidenceItem;
use OCA\OpenRegister\Service\Gdpr\Evidence\EvidenceSourceProvider;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Pipelinq CRM evidence source for OpenRegister DSAR fulfilment.
 *
 * @spec openspec/changes/consume-or-dsar/specs/avg-verzoeken-workflow/spec.md#requirement-req-avg-016--pipelinq-evidence-source-registration
 */
class PipelinqEvidenceSourceProvider implements EvidenceSourceProvider
{
    /**
     * Stable source id recorded on every harvested item.
     *
     * @var string
     */
    public const SOURCE_ID = 'pipelinq-crm';

    /**
     * Schemas harvested, in dossier order: schema config key => the field(s)
     * that link an object to the data subject. `@self` means the object's own
     * identity (id / contactsUid) is the subject; the other entries are foreign
     * keys pointing at the subject's client/contact record.
     *
     * @var array<string, array<int, string>>
     */
    private const SUBJECT_LINKS = [
        'client_schema'        => ['@self'],
        'contact_schema'       => ['@self', 'client'],
        'lead_schema'          => ['client', 'contact'],
        'request_schema'       => ['client', 'contact'],
        'contactmoment_schema' => ['client', 'contact'],
    ];

    /**
     * Constructor.
     *
     * @param IAppConfig         $appConfig App config (register/schema ids).
     * @param ContainerInterface $container Container for the OpenRegister ObjectService.
     * @param LoggerInterface    $logger    Logger (never receives the subject id value).
     */
    public function __construct(
        private readonly IAppConfig $appConfig,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * The stable pipelinq source id.
     *
     * @return string `pipelinq-crm`.
     *
     * @spec openspec/changes/consume-or-dsar/specs/avg-verzoeken-workflow/spec.md#requirement-req-avg-016--pipelinq-evidence-source-registration
     */
    public function getSourceId(): string
    {
        return self::SOURCE_ID;
    }//end getSourceId()

    /**
     * Whether pipelinq can harvest now: register + all subject schemas resolve
     * and OpenRegister's ObjectService is reachable.
     *
     * @return bool True when harvesting is possible.
     *
     * @spec openspec/changes/consume-or-dsar/specs/avg-verzoeken-workflow/spec.md#requirement-req-avg-016--pipelinq-evidence-source-registration
     */
    public function isEnabled(): bool
    {
        return $this->resolveContext() !== null;
    }//end isEnabled()

    /**
     * Harvest evidence items for a DSAR case from pipelinq's CRM schemas.
     *
     * @param string               $caseUuid The case object uuid.
     * @param array<string, mixed> $case     The case payload (carries subjectId / subjectType).
     *
     * @return EvidenceItem[] One item per matching pipelinq object (possibly empty).
     *
     * @spec openspec/changes/consume-or-dsar/specs/avg-verzoeken-workflow/spec.md#requirement-req-avg-016--pipelinq-evidence-source-registration
     */
    public function harvest(string $caseUuid, array $case): array
    {
        $subjectId = (string) ($case['subjectId'] ?? '');
        if ($subjectId === '') {
            return [];
        }

        $context = $this->resolveContext();
        if ($context === null) {
            return [];
        }

        [$objectService, $registerId, $schemaIds] = $context;

        $items = [];
        foreach (self::SUBJECT_LINKS as $schemaKey => $linkFields) {
            $schemaId = $schemaIds[$schemaKey];

            $rows = $this->safeFindAll(
                objectService: $objectService,
                registerId: $registerId,
                schemaId: $schemaId
            );

            foreach ($rows as $row) {
                $data = $this->rowToArray(row: $row);
                if ($data === null) {
                    continue;
                }

                if ($this->matchesSubject(data: $data, subjectId: $subjectId, linkFields: $linkFields) === false) {
                    continue;
                }

                $items[] = new EvidenceItem(
                    self::SOURCE_ID,
                    $this->contentHash(schemaKey: $schemaKey, data: $data),
                    EvidenceItem::STATUS_COLLECTED,
                    [
                        'schema'   => $this->schemaSlug(schemaKey: $schemaKey),
                        'objectId' => (string) ($data['id'] ?? ($data['uuid'] ?? '')),
                        'caseUuid' => $caseUuid,
                    ]
                );
            }//end foreach
        }//end foreach

        $this->logger->info(
            'Pipelinq DSAR evidence harvest complete',
            ['case' => $caseUuid, 'items' => count($items)]
        );

        return $items;
    }//end harvest()

    /**
     * Whether an object belongs to the subject via own identity or a FK link.
     *
     * @param array<string, mixed> $data       The object data.
     * @param string               $subjectId  The case subject identifier.
     * @param array<int, string>   $linkFields The link fields (`@self` or FK names).
     *
     * @return bool True when the object belongs to the subject.
     */
    private function matchesSubject(array $data, string $subjectId, array $linkFields): bool
    {
        foreach ($linkFields as $field) {
            if ($field === '@self') {
                $ownId       = (string) ($data['id'] ?? ($data['uuid'] ?? ''));
                $contactsUid = (string) ($data['contactsUid'] ?? '');
                if ($ownId === $subjectId || ($contactsUid !== '' && $contactsUid === $subjectId)) {
                    return true;
                }

                continue;
            }

            if ((string) ($data[$field] ?? '') === $subjectId) {
                return true;
            }
        }

        return false;
    }//end matchesSubject()

    /**
     * Stable content hash for idempotent dedupe (schema + object id).
     *
     * @param string               $schemaKey The schema config key.
     * @param array<string, mixed> $data      The object data.
     *
     * @return string A `sha256:` prefixed hash.
     */
    private function contentHash(string $schemaKey, array $data): string
    {
        $objectId = (string) ($data['id'] ?? ($data['uuid'] ?? ''));
        return 'sha256:'.hash('sha256', $this->schemaSlug(schemaKey: $schemaKey).':'.$objectId);
    }//end contentHash()

    /**
     * The schema slug for a schema config key (`client_schema` -> `client`).
     *
     * @param string $schemaKey The schema config key.
     *
     * @return string The schema slug.
     */
    private function schemaSlug(string $schemaKey): string
    {
        return str_replace('_schema', '', $schemaKey);
    }//end schemaSlug()

    /**
     * Resolve the ObjectService, register id and subject schema ids.
     *
     * @return array{0: object, 1: string, 2: array<string, string>}|null
     *         Null when unprovisioned or OR is unavailable.
     */
    private function resolveContext(): ?array
    {
        $registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        if ($registerId === '') {
            return null;
        }

        $schemaIds = [];
        foreach (array_keys(self::SUBJECT_LINKS) as $schemaKey) {
            $schemaId = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');
            if ($schemaId === '') {
                return null;
            }

            $schemaIds[$schemaKey] = $schemaId;
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            return null;
        }

        return [$objectService, $registerId, $schemaIds];
    }//end resolveContext()

    /**
     * Query a schema's objects, tolerating an OR read failure.
     *
     * @param object $objectService The OpenRegister ObjectService.
     * @param string $registerId    Register id.
     * @param string $schemaId      Schema id.
     *
     * @return array<int, mixed> The rows (empty on failure).
     */
    private function safeFindAll(object $objectService, string $registerId, string $schemaId): array
    {
        try {
            $rows = $objectService->findAll(
                [
                    'filters' => [
                        'register' => $registerId,
                        'schema'   => $schemaId,
                    ],
                    'limit'   => 5000,
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Pipelinq DSAR evidence harvest: schema read failed',
                ['schema' => $schemaId, 'exception' => $e->getMessage()]
            );
            return [];
        }

        if (is_array($rows) === true) {
            return $rows;
        }

        return [];
    }//end safeFindAll()

    /**
     * Normalise a findAll row (ObjectEntity or rendered array) to an array.
     *
     * @param mixed $row The row.
     *
     * @return array<string, mixed>|null The data, or null when unreadable.
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
}//end class
