<?php

/**
 * Pipelinq MigrateAvgVerzoekenToOrDsar repair step.
 *
 * ADR-047 Phase 3: converts pre-existing pipelinq `avgVerzoek` objects (and
 * their bewijsItem / weigering / redactieActie satellites) into OpenRegister
 * `dataSubjectRequest` cases in OR's `data-subject-requests` register, applying
 * the field-mapping table from the change design. Lossless where the OR schema
 * has a field; NL-specific extras land in a structured JSON migration block in
 * `notes` so nothing is silently dropped.
 *
 * Idempotent: a source object already carrying a `migratedTo` marker is
 * skipped, so a re-run migrates only the remainder. A source/target count
 * verification summary is logged; the change removes the `avgVerzoek` register
 * fragment in the same release, gated on this migration having run.
 *
 * @category Repair
 * @package  OCA\Pipelinq\Repair
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
 * @spec openspec/changes/consume-or-dsar/specs/avg-verzoeken-workflow/spec.md#requirement-req-avg-017--migration-of-existing-avgverzoek-data-to-or
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Repair;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * One-time migration of pipelinq avgVerzoek objects into OR dataSubjectRequest.
 *
 * @spec                                           openspec/changes/consume-or-dsar/specs/avg-verzoeken-workflow/spec.md#requirement-req-avg-017--migration-of-existing-avgverzoek-data-to-or
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class MigrateAvgVerzoekenToOrDsar implements IRepairStep
{
    /**
     * OR's data-subject-requests register slug.
     *
     * @var string
     */
    private const OR_REGISTER = 'data-subject-requests';

    /**
     * OR's dataSubjectRequest schema slug.
     *
     * @var string
     */
    private const OR_SCHEMA = 'dataSubjectRequest';

    /**
     * Maps avgVerzoek `artikel` to dataSubjectRequest `type`.
     *
     * @var array<string, string>
     */
    private const ARTICLE_TYPE = [
        'art-15' => 'access',
        'art-16' => 'rectification',
        'art-17' => 'erasure',
        'art-18' => 'restriction',
        'art-20' => 'portability',
        'art-21' => 'objection',
    ];

    /**
     * Maps avgVerzoek `status` to dataSubjectRequest `status` (non-terminal).
     *
     * @var array<string, string>
     */
    private const STATUS_MAP = [
        'ingediend'            => 'received',
        'in-behandeling'       => 'in-progress',
        'bewijs-verzamelen'    => 'evidence-collection',
        'redactie'             => 'evidence-collection',
        'bundle-genereren'     => 'in-progress',
        'wachten-op-verzoeker' => 'verifying',
        'weigering-opgesteld'  => 'denial-drafted',
        'gearchiveerd'         => 'closed',
    ];

    /**
     * Maps avgVerzoek `uitkomst` to a terminal dataSubjectRequest `status` for
     * the `afgerond` source status.
     *
     * @var array<string, string>
     */
    private const OUTCOME_TERMINAL_STATUS = [
        'toegekend'    => 'fulfilled',
        'gedeeltelijk' => 'fulfilled',
        'geweigerd'    => 'refused',
        'ingetrokken'  => 'closed',
    ];

    /**
     * Maps pipelinq weigering `grond` to OR `denialGround` (nearest Art-23 enum).
     *
     * @var array<string, string>
     */
    private const DENIAL_GROUND = [
        'kennelijk-ongegrond'     => 'manifestly-unfounded',
        'buitensporig'            => 'excessive-request',
        'identiteit-onbevestigd'  => 'identity-unverified',
        'wettelijke-uitzondering' => 'legal-exemption',
        'rechten-van-derden'      => 'third-party-rights',
    ];

    /**
     * Constructor.
     *
     * @param IAppConfig         $appConfig App config (register/schema ids).
     * @param ContainerInterface $container Container for the OpenRegister ObjectService.
     * @param LoggerInterface    $logger    Logger.
     */
    public function __construct(
        private readonly IAppConfig $appConfig,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Repair step name.
     *
     * @return string The name.
     */
    public function getName(): string
    {
        return 'Migrate pipelinq avgVerzoek objects to OpenRegister dataSubjectRequest cases (consume-or-dsar)';
    }//end getName()

    /**
     * Migrate every not-yet-migrated avgVerzoek into an OR dataSubjectRequest.
     *
     * @param IOutput $output Repair output.
     *
     * @return void
     *
     * @spec openspec/changes/consume-or-dsar/specs/avg-verzoeken-workflow/spec.md#requirement-req-avg-017--migration-of-existing-avgverzoek-data-to-or
     */
    public function run(IOutput $output): void
    {
        $registerId    = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $verzoekSchema = $this->appConfig->getValueString(Application::APP_ID, 'avgVerzoek_schema', '');
        if ($registerId === '' || $verzoekSchema === '') {
            $output->info('Pipelinq AVG→DSAR migration: no avgVerzoek schema provisioned, nothing to migrate.');
            return;
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            $output->warning('Pipelinq AVG→DSAR migration: OpenRegister ObjectService unavailable — skipped.');
            return;
        }

        try {
            $verzoeken = $objectService->findAll(
                ['filters' => ['register' => $registerId, 'schema' => $verzoekSchema], 'limit' => 5000]
            );
        } catch (\Throwable $e) {
            $output->warning('Pipelinq AVG→DSAR migration: could not read avgVerzoek objects — '.$e->getMessage());
            return;
        }

        if (is_array($verzoeken) === false || $verzoeken === []) {
            $output->info('Pipelinq AVG→DSAR migration: no avgVerzoek objects present.');
            return;
        }

        $satellites = $this->loadSatellites(objectService: $objectService, registerId: $registerId);

        $counts = ['migrated' => 0, 'skipped' => 0, 'failed' => 0];
        foreach ($verzoeken as $row) {
            $verzoek = $this->rowToArray(row: $row);
            if ($verzoek === null) {
                continue;
            }

            $outcome = $this->migrateOne(
                objectService: $objectService,
                registerId: $registerId,
                verzoekSchema: $verzoekSchema,
                verzoek: $verzoek,
                satellites: $satellites
            );
            $counts[$outcome]++;
        }//end foreach

        $summary = sprintf(
            'Pipelinq AVG→DSAR migration: %d migrated, %d already-migrated (skipped), %d failed (of %d source objects).',
            $counts['migrated'],
            $counts['skipped'],
            $counts['failed'],
            count($verzoeken),
        );
        $output->info($summary);
        $this->logger->info($summary);

        if ($counts['failed'] > 0) {
            $output->warning(
                'Pipelinq AVG→DSAR migration: '.$counts['failed']
                .' object(s) failed — the avgVerzoek fragment must not be removed until a clean run.'
            );
        }
    }//end run()

    /**
     * Migrate one avgVerzoek object; return the outcome bucket.
     *
     * @param object               $objectService The OpenRegister ObjectService.
     * @param string               $registerId    The pipelinq register id.
     * @param string               $verzoekSchema The avgVerzoek schema id.
     * @param array<string, mixed> $verzoek       The source object.
     * @param array<string, mixed> $satellites    The indexed satellite store.
     *
     * @return string One of `migrated` | `skipped` | `failed`.
     */
    private function migrateOne(
        object $objectService,
        string $registerId,
        string $verzoekSchema,
        array $verzoek,
        array $satellites,
    ): string {
        if (($verzoek['migratedTo'] ?? '') !== '') {
            return 'skipped';
        }

        try {
            $case  = $this->mapVerzoek(verzoek: $verzoek, satellites: $satellites);
            $saved = $objectService->saveObject($case, [], self::OR_REGISTER, self::OR_SCHEMA, null);

            // Mark the source migrated (idempotency marker) on its own schema.
            $verzoek['migratedTo'] = (string) $saved->getUuid();
            $objectService->saveObject(
                $verzoek,
                [],
                $registerId,
                $verzoekSchema,
                (string) ($verzoek['id'] ?? ($verzoek['uuid'] ?? ''))
            );
            return 'migrated';
        } catch (\Throwable $e) {
            $this->logger->error(
                'Pipelinq AVG→DSAR migration: failed to migrate an avgVerzoek',
                ['id' => (string) ($verzoek['id'] ?? ''), 'exception' => $e->getMessage()]
            );
            return 'failed';
        }//end try
    }//end migrateOne()

    /**
     * Map one avgVerzoek (+ its satellites) to a dataSubjectRequest payload.
     *
     * @param array<string, mixed> $verzoek    The source object.
     * @param array<string, mixed> $satellites Satellites indexed by schema then verzoekId.
     *
     * @return array<string, mixed> The OR case payload.
     */
    private function mapVerzoek(array $verzoek, array $satellites): array
    {
        $verzoekId = (string) ($verzoek['id'] ?? ($verzoek['uuid'] ?? ''));
        $article   = (string) ($verzoek['artikel'] ?? '');

        $subjectId = (string) ($verzoek['verzoekerContact'] ?? '');
        if ($subjectId === '') {
            $subjectId = (string) ($verzoek['verzoekerBsn'] ?? ($verzoek['verzoekerNaam'] ?? 'unknown'));
        }

        $bewijs    = (array) (($satellites['bewijs'][$verzoekId] ?? []));
        $redactie  = (array) (($satellites['redactie'][$verzoekId] ?? []));
        $weigering = (array) (($satellites['weigering'][$verzoekId] ?? []));

        $dueAt = (string) ($verzoek['wettelijkeTermijnVerloopt'] ?? '');

        $subjectType = 'natural-person';
        if (($verzoek['verzoekerContact'] ?? '') !== '') {
            $subjectType = 'contact';
        }

        $type = self::ARTICLE_TYPE[$article] ?? 'access';
        if ($article === 'geen-avg') {
            $type = 'access';
        }

        $case = [
            'subjectId'    => $subjectId,
            'subjectType'  => $subjectType,
            'jurisdiction' => 'NL',
            'type'         => $type,
            'status'       => $this->mapStatus(verzoek: $verzoek),
            'receivedAt'   => (string) ($verzoek['ingediendOp'] ?? ''),
            'handler'      => (string) ($verzoek['behandelaar'] ?? ''),
            'dpiaRequired' => (bool) ($verzoek['dpiaFlag'] ?? false),
            'notes'        => $this->migrationNotes(verzoek: $verzoek, weigering: $weigering),
        ];

        if ($dueAt !== '') {
            $case['dueAt'] = $dueAt;
        }

        $this->applyExtension(case: $case, verzoek: $verzoek, dueAt: $dueAt);

        // Optional 1:1 string fields, copied only when present.
        foreach (['afgerondOp' => 'closedAt', 'uitkomst' => 'outcome', 'retentieTot' => 'retainUntil'] as $src => $dst) {
            if (($verzoek[$src] ?? '') !== '') {
                $case[$dst] = (string) $verzoek[$src];
            }
        }

        $case = $this->applyGround(case: $case, weigering: $weigering);
        $case = $this->applyCollection(case: $case, key: 'evidence', records: $this->mapEvidence(bewijs: $bewijs));
        $case = $this->applyCollection(case: $case, key: 'redactions', records: $this->mapRedactions(redactie: $redactie));

        return $case;
    }//end mapVerzoek()

    /**
     * Attach the denial ground to the case when a weigering satellite exists.
     *
     * @param array<string, mixed> $case      The case payload.
     * @param array<string, mixed> $weigering The weigering satellite.
     *
     * @return array<string, mixed> The case with denialGround set when applicable.
     */
    private function applyGround(array $case, array $weigering): array
    {
        $denialGround = $this->mapDenialGround(weigering: $weigering);
        if ($denialGround !== null) {
            $case['denialGround'] = $denialGround;
        }

        return $case;
    }//end applyGround()

    /**
     * Attach a non-empty sub-collection (evidence / redactions) to the case.
     *
     * @param array<string, mixed>              $case    The case payload.
     * @param string                            $key     The case field name.
     * @param array<int, array<string, string>> $records The mapped records.
     *
     * @return array<string, mixed> The case with the collection set when non-empty.
     */
    private function applyCollection(array $case, string $key, array $records): array
    {
        if ($records !== []) {
            $case[$key] = $records;
        }

        return $case;
    }//end applyCollection()

    /**
     * Map the source status to the OR status vocabulary.
     *
     * @param array<string, mixed> $verzoek The source object.
     *
     * @return string The OR status.
     */
    private function mapStatus(array $verzoek): string
    {
        $status = (string) ($verzoek['status'] ?? '');
        if ((string) ($verzoek['artikel'] ?? '') === 'geen-avg') {
            return 'closed';
        }

        if ($status === 'afgerond') {
            $outcome = (string) ($verzoek['uitkomst'] ?? '');
            return (self::OUTCOME_TERMINAL_STATUS[$outcome] ?? 'closed');
        }

        return (self::STATUS_MAP[$status] ?? 'received');
    }//end mapStatus()

    /**
     * Apply extension fields (extendedUntil / extensionReason) to the case.
     *
     * @param array<string, mixed> $case    The case payload (by reference).
     * @param array<string, mixed> $verzoek The source object.
     * @param string               $dueAt   The base due date.
     *
     * @return void
     */
    private function applyExtension(array &$case, array $verzoek, string $dueAt): void
    {
        $verlengdMet = (int) ($verzoek['verlengdMet'] ?? 0);
        if ($verlengdMet <= 0) {
            return;
        }

        $case['extensionReason'] = (string) ($verzoek['verlengingsgrond'] ?? '');
        if ($dueAt === '') {
            return;
        }

        try {
            $base = new DateTimeImmutable($dueAt);
            $case['extendedUntil'] = $base->modify(sprintf('+%d days', $verlengdMet))->format(DateTimeInterface::ATOM);
        } catch (\Throwable $e) {
            // Unparseable due date — leave extendedUntil unset (reason preserved).
        }
    }//end applyExtension()

    /**
     * Nearest OR denial ground for a weigering satellite, or null.
     *
     * @param array<string, mixed> $weigering The weigering satellite (may be empty).
     *
     * @return string|null The OR denial ground.
     */
    private function mapDenialGround(array $weigering): ?string
    {
        if ($weigering === []) {
            return null;
        }

        $grond = (string) ($weigering['grond'] ?? '');
        return (self::DENIAL_GROUND[$grond] ?? 'not-applicable');
    }//end mapDenialGround()

    /**
     * Map bewijsItem satellites to the OR evidence[] shape.
     *
     * @param array<mixed> $bewijs The bewijs satellites.
     *
     * @return array<int, array<string, string>> The evidence records.
     */
    private function mapEvidence(array $bewijs): array
    {
        $evidence = [];
        foreach ($bewijs as $item) {
            if (is_array($item) === false) {
                continue;
            }

            $evidence[] = [
                'sourceId'    => (string) ($item['bronApp'] ?? 'pipelinq-crm'),
                'contentHash' => (string) ($item['contentHash'] ?? ('sha256:'.hash('sha256', (string) ($item['id'] ?? '')))),
                'status'      => 'collected',
            ];
        }

        return $evidence;
    }//end mapEvidence()

    /**
     * Map redactieActie satellites to the OR redactions[] shape.
     *
     * @param array<mixed> $redactie The redactie satellites.
     *
     * @return array<int, array<string, string>> The redaction records.
     */
    private function mapRedactions(array $redactie): array
    {
        $redactions = [];
        foreach ($redactie as $item) {
            if (is_array($item) === false) {
                continue;
            }

            $redactions[] = [
                'field'  => (string) ($item['veldpad'] ?? ''),
                'before' => (string) ($item['voorWaarde'] ?? ''),
                'after'  => (string) ($item['naWaarde'] ?? ''),
                'ground' => (string) ($item['grond'] ?? ''),
            ];
        }

        return $redactions;
    }//end mapRedactions()

    /**
     * Build the structured JSON migration notes block preserving NL extras.
     *
     * @param array<string, mixed> $verzoek   The source object.
     * @param array<string, mixed> $weigering The weigering satellite (may be empty).
     *
     * @return string The JSON notes block.
     */
    private function migrationNotes(array $verzoek, array $weigering): string
    {
        $block = [
            'migratedFrom'             => 'pipelinq/avgVerzoek',
            'kenmerk'                  => ($verzoek['kenmerk'] ?? null),
            'ingediendVia'             => ($verzoek['ingediendVia'] ?? null),
            'specifiekeVraag'          => ($verzoek['specifiekeVraag'] ?? null),
            'scope'                    => ($verzoek['scope'] ?? null),
            'verzoekerBsnGeverifieerd' => ($verzoek['verzoekerBsnGeverifieerd'] ?? null),
            'fgGeinformeerd'           => ($verzoek['fgGeinformeerd'] ?? null),
            'termijnOverschreden'      => ($verzoek['termijnOverschreden'] ?? null),
            'bewijsbundel'             => ($verzoek['bewijsbundel'] ?? null),
            'oorspronkelijkArtikel'    => ($verzoek['artikel'] ?? null),
        ];

        if ($weigering !== []) {
            $block['weigeringTekst']       = ($weigering['weigering'] ?? null);
            $block['weigeringToelichting'] = ($weigering['toelichtingAvg23'] ?? null);
        }

        return (string) json_encode(array_filter($block, static fn ($v): bool => $v !== null));
    }//end migrationNotes()

    /**
     * Load bewijsItem / weigering / redactieActie satellites indexed by verzoekId.
     *
     * @param object $objectService The OpenRegister ObjectService.
     * @param string $registerId    The register id.
     *
     * @return array<string, array<int|string, mixed>> Indexed satellite store.
     */
    private function loadSatellites(object $objectService, string $registerId): array
    {
        return [
            'bewijs'    => $this->indexByVerzoek(
                objectService: $objectService,
                registerId: $registerId,
                schemaKey: 'bewijsItem_schema',
                parentField: 'verzoekId'
            ),
            'redactie'  => $this->indexByVerzoek(
                objectService: $objectService,
                registerId: $registerId,
                schemaKey: 'redactieActie_schema',
                parentField: 'verzoekId'
            ),
            'weigering' => $this->indexFirstByVerzoek(
                objectService: $objectService,
                registerId: $registerId,
                schemaKey: 'weigering_schema',
                parentField: 'verzoekId'
            ),
        ];
    }//end loadSatellites()

    /**
     * Group a satellite schema's objects into lists keyed by their parent verzoekId.
     *
     * @param object $objectService The OpenRegister ObjectService.
     * @param string $registerId    The register id.
     * @param string $schemaKey     The satellite schema config key.
     * @param string $parentField   The field holding the parent verzoek id.
     *
     * @return array<string, array<int, array<string, mixed>>> The grouped store.
     */
    private function indexByVerzoek(object $objectService, string $registerId, string $schemaKey, string $parentField): array
    {
        $index = [];
        foreach ($this->readSchema(objectService: $objectService, registerId: $registerId, schemaKey: $schemaKey) as $row) {
            $data = $this->rowToArray(row: $row);
            if ($data === null) {
                continue;
            }

            $parent = (string) ($data[$parentField] ?? '');
            if ($parent === '') {
                continue;
            }

            $index[$parent][] = $data;
        }

        return $index;
    }//end indexByVerzoek()

    /**
     * Group a satellite schema keeping the first object per parent verzoekId.
     *
     * @param object $objectService The OpenRegister ObjectService.
     * @param string $registerId    The register id.
     * @param string $schemaKey     The satellite schema config key.
     * @param string $parentField   The field holding the parent verzoek id.
     *
     * @return array<string, array<string, mixed>> The first-per-parent store.
     */
    private function indexFirstByVerzoek(object $objectService, string $registerId, string $schemaKey, string $parentField): array
    {
        $index = [];
        foreach ($this->readSchema(objectService: $objectService, registerId: $registerId, schemaKey: $schemaKey) as $row) {
            $data = $this->rowToArray(row: $row);
            if ($data === null) {
                continue;
            }

            $parent = (string) ($data[$parentField] ?? '');
            if ($parent === '' || isset($index[$parent]) === true) {
                continue;
            }

            $index[$parent] = $data;
        }

        return $index;
    }//end indexFirstByVerzoek()

    /**
     * Read a schema's objects by config key, tolerating an absent schema.
     *
     * @param object $objectService The OpenRegister ObjectService.
     * @param string $registerId    The register id.
     * @param string $schemaKey     The schema config key.
     *
     * @return array<int, mixed> The rows (empty when the schema is unprovisioned/unreadable).
     */
    private function readSchema(object $objectService, string $registerId, string $schemaKey): array
    {
        $schemaId = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');
        if ($schemaId === '') {
            return [];
        }

        try {
            $rows = $objectService->findAll(
                ['filters' => ['register' => $registerId, 'schema' => $schemaId], 'limit' => 10000]
            );
        } catch (\Throwable $e) {
            return [];
        }

        if (is_array($rows) === true) {
            return $rows;
        }

        return [];
    }//end readSchema()

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
