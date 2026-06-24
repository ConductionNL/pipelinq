<?php

/**
 * Pipelinq AvgRequestService.
 *
 * Lifecycle service for AVG (GDPR) data-subject requests: server-authoritative
 * intake (article classification, reference + legal-deadline computation,
 * receipt TermijnEvent), listing with access scoping, status updates, archive
 * (sets the 5-year retention date) and the retention-guarded delete. Monetary /
 * legal figures are never trusted from the client.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Avg
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#2.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Avg;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use OCA\Pipelinq\Service\Lifecycle\SchemaLifecycleGraph;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Service for the AVG request lifecycle.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Aggregates the collaborators a
 *  request-lifecycle service legitimately needs (repository, deadline logic,
 *  access-control, notifications, logger).
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)     The public surface is the small
 *  set of lifecycle verbs (intake/list/get/update/archive/delete/flagDpia),
 *  each single-purpose and unit-tested.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The class aggregates the
 *  whole request lifecycle (intake + classification + listing/scoping + the
 *  retention-guarded delete + small validators) as many small single-purpose
 *  methods; the cohesion is intentional and splitting it would scatter one
 *  concern across several classes.
 *
 * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#2.1
 */
class AvgRequestService
{
    /**
     * Retention period for the request dossier itself, in years (RvIG guideline).
     *
     * @var int
     */
    public const RETENTION_YEARS = 5;

    /**
     * Allowed GDPR article values, mapped from intake free-text intents.
     *
     * @var array<string, string>
     */
    private const INTENT_TO_ARTICLE = [
        'inzage'        => 'art-15-inzage',
        'rectificatie'  => 'art-16-rectificatie',
        'correctie'     => 'art-16-rectificatie',
        'wissing'       => 'art-17-wissing',
        'verwijdering'  => 'art-17-wissing',
        'vergetelheid'  => 'art-17-wissing',
        'beperking'     => 'art-18-beperking',
        'portabiliteit' => 'art-20-portabiliteit',
        'overdracht'    => 'art-20-portabiliteit',
    ];

    /**
     * The set of valid article identifiers.
     *
     * @var string[]
     */
    private const VALID_ARTICLES = [
        'art-15-inzage',
        'art-16-rectificatie',
        'art-17-wissing',
        'art-18-beperking',
        'art-20-portabiliteit',
        'geen-avg',
    ];

    /**
     * Map a Dutch AVG-article identifier onto OpenRegister's generic
     * `dataSubjectRequest.type` vocabulary.
     *
     * Pipelinq's avgVerzoek keeps its jurisdiction-specific `artikel` field as
     * the Dutch overlay, but data-subject-rights fulfilment is now routed through
     * OR's generic capability, which is keyed on the EU-generic request type. This
     * map is the canonical bridge between the two and is recorded in
     * openspec/changes/pipelinq-avg-adopt-or-gdpr/design.md.
     *
     * @var array<string, string>
     */
    private const ARTICLE_TO_OR_TYPE = [
        'art-15-inzage'        => 'access',
        'art-16-rectificatie'  => 'rectification',
        'art-17-wissing'       => 'erasure',
        'art-18-beperking'     => 'restriction',
        'art-20-portabiliteit' => 'portability',
    ];

    /**
     * Schema slug whose `x-openregister-lifecycle` declares the request status graph.
     *
     * @var string
     */
    private const VERZOEK_SCHEMA_SLUG = 'avgVerzoek';

    /**
     * The two terminal (read-only) statuses. A request in either state may not be
     * edited at all — enforced ahead of the graph check so the existing
     * "afgerond" error contract is preserved verbatim.
     *
     * @var string[]
     */
    private const READ_ONLY_STATUSES = [
        'afgerond',
        'gearchiveerd',
    ];

    /**
     * Fallback status-transition adjacency map, used only when the bundled schema
     * declaration is unreadable. The canonical source of truth is the avgVerzoek
     * schema's `x-openregister-lifecycle` annotation (ADR-031), which OpenRegister's
     * LifecycleValidationListener also enforces on save. This constant MUST mirror it:
     * each of the seven working states may move to any status; the two terminal
     * states (afgerond/gearchiveerd) have no outgoing transitions (read-only).
     *
     * @var array<string, array<int, string>>
     */
    private const FALLBACK_TRANSITIONS = [
        'ingediend'            => self::ALL_STATUSES,
        'in-behandeling'       => self::ALL_STATUSES,
        'bewijs-verzamelen'    => self::ALL_STATUSES,
        'redactie'             => self::ALL_STATUSES,
        'bundle-genereren'     => self::ALL_STATUSES,
        'wachten-op-verzoeker' => self::ALL_STATUSES,
        'weigering-opgesteld'  => self::ALL_STATUSES,
        'afgerond'             => [],
        'gearchiveerd'         => [],
    ];

    /**
     * The full status enum, the target set every working state may move to.
     *
     * @var string[]
     */
    private const ALL_STATUSES = [
        'ingediend',
        'in-behandeling',
        'bewijs-verzamelen',
        'redactie',
        'bundle-genereren',
        'wachten-op-verzoeker',
        'weigering-opgesteld',
        'afgerond',
        'gearchiveerd',
    ];

    /**
     * Constructor.
     *
     * @param AvgRepository             $repository     The AVG OR repository.
     * @param DeadlineService           $deadline       The deadline computation service.
     * @param AvgAccessService          $access         The access-control service.
     * @param AvgEventService           $events         The TermijnEvent recorder.
     * @param LoggerInterface           $logger         The logger.
     * @param SchemaLifecycleGraph|null $lifecycleGraph Reads the declarative status
     *                                                  graph from the bundled schema
     *                                                  (defaults to the shipped helper).
     */
    public function __construct(
        private AvgRepository $repository,
        private DeadlineService $deadline,
        private AvgAccessService $access,
        private AvgEventService $events,
        private LoggerInterface $logger,
        private ?SchemaLifecycleGraph $lifecycleGraph=null,
    ) {
        if ($this->lifecycleGraph === null) {
            $this->lifecycleGraph = new SchemaLifecycleGraph();
        }
    }//end __construct()

    /**
     * Classify a GDPR article from an explicit choice or free-text intent.
     *
     * An explicit valid article wins. Otherwise the free text is scanned for a
     * known intent keyword. Returns null when the text matches more than one
     * distinct article (the handler must then disambiguate, REQ-AVG-001 sc.2).
     *
     * @param string|null $explicitArticle The explicitly chosen article, if any.
     * @param string      $freeText        The citizen's free-text request.
     *
     * @return string|null The classified article, or null when ambiguous/unknown.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#2.1
     */
    public function classifyArticle(?string $explicitArticle, string $freeText): ?string
    {
        if ($explicitArticle !== null && in_array($explicitArticle, self::VALID_ARTICLES, true) === true) {
            return $explicitArticle;
        }

        $lower   = mb_strtolower($freeText);
        $matched = [];
        foreach (self::INTENT_TO_ARTICLE as $keyword => $article) {
            if (mb_strpos($lower, $keyword) !== false) {
                $matched[$article] = true;
            }
        }

        if (count($matched) === 1) {
            return (string) array_key_first($matched);
        }

        return null;
    }//end classifyArticle()

    /**
     * Map a Dutch AVG-article identifier to OR's generic data-subject-request type.
     *
     * Returns null when the article has no generic counterpart (e.g. `geen-avg`),
     * so the caller can skip routing a non-AVG request through OR's capability.
     *
     * @param string $article The avgVerzoek article identifier.
     *
     * @return string|null The OR `dataSubjectRequest.type`, or null when unmapped.
     *
     * @spec openspec/changes/pipelinq-avg-adopt-or-gdpr/design.md
     */
    public function orRequestTypeFor(string $article): ?string
    {
        return (self::ARTICLE_TO_OR_TYPE[$article] ?? null);
    }//end orRequestTypeFor()

    /**
     * Register a new AVG request (intake).
     *
     * Server-authoritative: computes the reference and the legal deadline, sets
     * the initial status, records the receipt TermijnEvent. The article must be
     * explicitly supplied or unambiguously derivable; an ambiguous free-text
     * intent is rejected so the handler picks the article (REQ-AVG-001).
     *
     * @param array<string, mixed> $input  The validated intake input.
     * @param string               $userId The acting handler UID.
     *
     * @return array<string, mixed> The created request.
     *
     * @throws OCSBadRequestException When the article is missing or ambiguous.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#2.1
     */
    public function intake(array $input, string $userId): array
    {
        $explicitArticle = null;
        if (isset($input['artikel']) === true) {
            $explicitArticle = (string) $input['artikel'];
        }

        $article = $this->classifyArticle(
            explicitArticle: $explicitArticle,
            freeText: (string) ($input['specifiekeVraag'] ?? '')
        );

        if ($article === null) {
            throw new OCSBadRequestException(
                'Kies een AVG-artikel: het verzoek is niet eenduidig te classificeren.'
            );
        }

        $now      = $this->now();
        $deadline = $this->deadline->computeDeadline(submittedAt: $now);

        $request = [
            'kenmerk'                   => $this->generateReference(now: $now),
            'ingediendOp'               => $now->format(DateTimeInterface::ATOM),
            'ingediendVia'              => $this->sanitizeChannel(channel: (string) ($input['ingediendVia'] ?? 'handmatig')),
            'verzoekerContact'          => (string) ($input['verzoekerContact'] ?? ''),
            'verzoekerNaam'             => (string) ($input['verzoekerNaam'] ?? ''),
            'verzoekerBsn'              => $this->normalizeBsn(bsn: (string) ($input['verzoekerBsn'] ?? '')),
            'verzoekerBsnGeverifieerd'  => (bool) ($input['verzoekerBsnGeverifieerd'] ?? false),
            'artikel'                   => $article,
            'specifiekeVraag'           => (string) ($input['specifiekeVraag'] ?? ''),
            'scope'                     => $this->normalizeScope(scope: $input['scope'] ?? []),
            'wettelijkeTermijnVerloopt' => $deadline->format(DateTimeInterface::ATOM),
            'status'                    => 'in-behandeling',
            'behandelaar'               => $userId,
            'fgGeinformeerd'            => false,
            'dpiaFlag'                  => (bool) ($input['dpiaFlag'] ?? false),
            'termijnOverschreden'       => false,
        ];

        $saved = $this->repository->save(schemaKey: AvgRepository::SCHEMA_VERZOEK, object: $request);
        $id    = $this->repository->idOf($saved);

        $this->events->record(
            verzoekId: $id,
            type: 'ontvangstbevestiging-verstuurd',
            deadline: $deadline->format(DateTimeInterface::ATOM),
            details: 'Ontvangstbevestiging aangemaakt; verzoeker geinformeerd over de wettelijke termijn.'
        );

        $this->logger->info('Pipelinq AVG: request intake', ['id' => $id, 'artikel' => $article, 'userId' => $userId]);

        return $saved;
    }//end intake()

    /**
     * List requests visible to the acting user, with optional filtering.
     *
     * Access scoping (ADR-005): handlers see only the requests they handle; team
     * leads and the FG/DPO see all. The filter is applied server-side after the
     * visibility scope so a handler can never widen their view.
     *
     * @param array<string, mixed> $filters The list filters (status/artikel/behandelaar/dpiaFlag).
     * @param string               $userId  The acting user UID.
     *
     * @return array<int, array<string, mixed>> The visible requests.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#2.1
     */
    public function list(array $filters, string $userId): array
    {
        $all     = $this->repository->findAll(schemaKey: AvgRepository::SCHEMA_VERZOEK);
        $visible = [];

        foreach ($all as $request) {
            if ($this->access->canView(request: $request, userId: $userId) === false) {
                continue;
            }

            if ($this->matchesFilters(request: $request, filters: $filters) === false) {
                continue;
            }

            $visible[] = $request;
        }

        return $visible;
    }//end list()

    /**
     * Fetch a single request the user is allowed to view.
     *
     * @param string $id     The request UUID.
     * @param string $userId The acting user UID.
     *
     * @return array<string, mixed> The request.
     *
     * @throws OCSNotFoundException  When the request does not exist in this app's schema.
     * @throws OCSForbiddenException When the user may not view it (IDOR guard).
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#2.1
     */
    public function get(string $id, string $userId): array
    {
        $request = $this->repository->find(schemaKey: AvgRepository::SCHEMA_VERZOEK, id: $id);

        if ($this->access->canView(request: $request, userId: $userId) === false) {
            throw new OCSForbiddenException('Geen toegang tot dit AVG-verzoek.');
        }

        return $request;
    }//end get()

    /**
     * Update mutable handler fields on a request (behandelaar, status, notities).
     *
     * Only a permitted set of fields may be changed; legal fields (deadline,
     * reference, retention) are never client-writable here. A request that is
     * already resolved/archived is read-only.
     *
     * @param string               $id     The request UUID.
     * @param array<string, mixed> $patch  The requested changes.
     * @param string               $userId The acting user UID.
     *
     * @return array<string, mixed> The updated request.
     *
     * @throws OCSForbiddenException When the user may not edit it.
     * @throws OCSBadRequestException When the request is read-only.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#2.1
     */
    public function update(string $id, array $patch, string $userId): array
    {
        $request = $this->get(id: $id, userId: $userId);

        if ($this->access->canEdit(request: $request, userId: $userId) === false) {
            throw new OCSForbiddenException('Geen rechten om dit AVG-verzoek te wijzigen.');
        }

        $currentStatus = (string) ($request['status'] ?? '');
        if (in_array($currentStatus, self::READ_ONLY_STATUSES, true) === true) {
            throw new OCSBadRequestException('Een afgerond verzoek kan niet meer worden gewijzigd.');
        }

        // When the patch moves the status, validate the transition against the
        // declarative graph (avgVerzoek `x-openregister-lifecycle`, ADR-031) —
        // the single source of truth that OpenRegister's LifecycleValidationListener
        // also enforces on save. Only the transition GRAPH validation lives here;
        // the side-effecting legal computations (deadline, retention, the
        // retention-guarded delete, the allowed-FIELDS enforcement below) stay in PHP.
        if (array_key_exists('status', $patch) === true) {
            $this->assertStatusTransitionAllowed(from: $currentStatus, to: (string) $patch['status']);
        }

        $allowed = ['behandelaar', 'status', 'scope', 'specifiekeVraag', 'verzoekerNaam', 'verzoekerContact'];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $patch) === true) {
                $request[$field] = $patch[$field];
            }
        }

        if (array_key_exists('scope', $patch) === true) {
            $request['scope'] = $this->normalizeScope(scope: $patch['scope']);
        }

        return $this->repository->save(schemaKey: AvgRepository::SCHEMA_VERZOEK, object: $request, id: $id);
    }//end update()

    /**
     * Assert that a request status transition is permitted by the declared graph.
     *
     * The allowed-transition set is read from the avgVerzoek schema's
     * `x-openregister-lifecycle` declaration (ADR-031) rather than a hardcoded
     * PHP copy, so the schema is the single source of truth. The same illegal
     * transition is rejected here (HTTP 400, {@see OCSBadRequestException}) as is
     * rejected at the persistence boundary by OpenRegister's
     * LifecycleValidationListener — declared once, enforced twice.
     *
     * @param string $from The current status.
     * @param string $to   The requested target status.
     *
     * @return void
     *
     * @throws OCSBadRequestException When the target status is unknown or the
     *                                from->to transition is not declared.
     *
     * @spec openspec/changes/pipelinq-avg-lifecycle-to-or/specs/openregister-integration/spec.md
     */
    public function assertStatusTransitionAllowed(string $from, string $to): void
    {
        // A no-op status change (status present in the patch but unchanged) is
        // always permitted — it mirrors the prior behaviour where an unchanged
        // status field passed straight through the allowed-fields copy.
        if ($from === $to) {
            return;
        }

        $graph = $this->allowedTransitions();

        if (in_array($to, self::ALL_STATUSES, true) === false) {
            throw new OCSBadRequestException(
                sprintf('Onbekende AVG-status: %s.', $to)
            );
        }

        $targets = ($graph[$from] ?? []);
        if (in_array($to, $targets, true) === false) {
            throw new OCSBadRequestException(
                sprintf('Ongeldige statusovergang: %s -> %s.', $from, $to)
            );
        }
    }//end assertStatusTransitionAllowed()

    /**
     * The avgVerzoek status-transition adjacency map.
     *
     * Derived from the avgVerzoek schema's `x-openregister-lifecycle` declaration
     * (ADR-031) — the single source of truth that OpenRegister's
     * LifecycleValidationListener also enforces on save. `fullAdjacencyFor()` seeds
     * a key for every declared state, so the two terminal states
     * (afgerond/gearchiveerd) appear with an empty target list. Falls back to the
     * mirrored constant only when the bundled declaration is unreadable, so the
     * guard never regresses.
     *
     * @return array<string, array<int, string>> The `from => [to, ...]` map.
     *
     * @spec openspec/changes/pipelinq-avg-lifecycle-to-or/specs/openregister-integration/spec.md
     */
    private function allowedTransitions(): array
    {
        $graph = $this->lifecycleGraph->fullAdjacencyFor(schemaSlug: self::VERZOEK_SCHEMA_SLUG);
        if ($graph === []) {
            return self::FALLBACK_TRANSITIONS;
        }

        return $graph;
    }//end allowedTransitions()

    /**
     * Manually flag a request for DPIA review (handler / FG).
     *
     * @param string $id     The request UUID.
     * @param string $userId The acting user UID.
     *
     * @return array<string, mixed> The updated request.
     *
     * @throws OCSForbiddenException When the user may not edit it.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#3.4
     */
    public function flagDpia(string $id, string $userId): array
    {
        $request = $this->get(id: $id, userId: $userId);
        if ($this->access->canEdit(request: $request, userId: $userId) === false) {
            throw new OCSForbiddenException('Geen rechten om dit AVG-verzoek te wijzigen.');
        }

        $request['dpiaFlag']       = true;
        $request['fgGeinformeerd'] = true;

        return $this->repository->save(schemaKey: AvgRepository::SCHEMA_VERZOEK, object: $request, id: $id);
    }//end flagDpia()

    /**
     * Archive a resolved request and stamp the 5-year retention date.
     *
     * @param string $id     The request UUID.
     * @param string $userId The acting user UID.
     *
     * @return array<string, mixed> The archived request.
     *
     * @throws OCSForbiddenException When the user may not edit it.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#2.7
     */
    public function archive(string $id, string $userId): array
    {
        $request = $this->get(id: $id, userId: $userId);
        if ($this->access->canEdit(request: $request, userId: $userId) === false) {
            throw new OCSForbiddenException('Geen rechten om dit AVG-verzoek te archiveren.');
        }

        $now = $this->now();
        $request['status'] = 'gearchiveerd';
        if (($request['afgerondOp'] ?? '') === '') {
            $request['afgerondOp'] = $now->format(DateTimeInterface::ATOM);
        }

        $request['retentieTot'] = $now
            ->add(new DateInterval('P'.self::RETENTION_YEARS.'Y'))
            ->setTime(0, 0, 0)
            ->format(DateTimeInterface::ATOM);

        return $this->repository->save(schemaKey: AvgRepository::SCHEMA_VERZOEK, object: $request, id: $id);
    }//end archive()

    /**
     * Delete a request, refusing while the retention window is active.
     *
     * Server-authoritative: the active retention window blocks deletion for every
     * caller (REQ-AVG-009 sc.3). A privileged override (FG/DPO) is required to
     * destroy a dossier early.
     *
     * @param string $id     The request UUID.
     * @param string $userId The acting user UID.
     * @param bool   $isDpo  Whether the caller is an FG/DPO with override rights.
     *
     * @return void
     *
     * @throws OCSForbiddenException When retention is active and the caller lacks override.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#2.1
     */
    public function delete(string $id, string $userId, bool $isDpo): void
    {
        $request = $this->get(id: $id, userId: $userId);
        $now     = $this->now();

        if ($this->isRetentionActive(request: $request, now: $now) === true && $isDpo === false) {
            $until = (string) ($request['retentieTot'] ?? '');
            throw new OCSForbiddenException(
                'Vroegtijdige vernietiging is niet toegestaan: retentie loopt tot '.$until.' (RvIG-richtlijn).'
            );
        }

        $this->repository->delete(schemaKey: AvgRepository::SCHEMA_VERZOEK, id: $id);
        $this->logger->info('Pipelinq AVG: request deleted', ['id' => $id, 'userId' => $userId, 'dpoOverride' => $isDpo]);
    }//end delete()

    /**
     * Whether the 5-year retention window for a request is still active.
     *
     * A request with no retention date set (not yet archived) is treated as
     * retention-active to fail safe.
     *
     * @param array<string, mixed> $request The request.
     * @param DateTimeInterface    $now     The reference time.
     *
     * @return bool True while retention is active.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#3.6
     */
    public function isRetentionActive(array $request, DateTimeInterface $now): bool
    {
        $until = (string) ($request['retentieTot'] ?? '');
        if ($until === '') {
            return true;
        }

        try {
            $untilDate = new DateTimeImmutable($until);
        } catch (\Throwable $e) {
            return true;
        }

        return ($untilDate->getTimestamp() > $now->getTimestamp());
    }//end isRetentionActive()

    /**
     * Whether a request matches the supplied list filters.
     *
     * @param array<string, mixed> $request The request.
     * @param array<string, mixed> $filters The filters.
     *
     * @return bool True when the request matches every present filter.
     */
    private function matchesFilters(array $request, array $filters): bool
    {
        foreach (['status', 'artikel', 'behandelaar'] as $key) {
            if (isset($filters[$key]) === true && $filters[$key] !== ''
                && (string) ($request[$key] ?? '') !== (string) $filters[$key]
            ) {
                return false;
            }
        }

        if (isset($filters['dpiaFlag']) === true && $filters['dpiaFlag'] !== '') {
            $want = filter_var($filters['dpiaFlag'], FILTER_VALIDATE_BOOLEAN);
            if ((bool) ($request['dpiaFlag'] ?? false) !== $want) {
                return false;
            }
        }

        return true;
    }//end matchesFilters()

    /**
     * Generate a human-readable request reference (AVG-YYYY-NNNN).
     *
     * The sequence component is derived from a count of this year's requests, so
     * references are monotonic per year without a separate counter store.
     *
     * @param DateTimeInterface $now The intake time.
     *
     * @return string The reference.
     */
    private function generateReference(DateTimeInterface $now): string
    {
        $year     = $now->format('Y');
        $existing = $this->repository->findAll(schemaKey: AvgRepository::SCHEMA_VERZOEK);
        $seq      = 0;
        foreach ($existing as $request) {
            if (str_starts_with((string) ($request['kenmerk'] ?? ''), 'AVG-'.$year.'-') === true) {
                $seq++;
            }
        }

        return sprintf('AVG-%s-%04d', $year, ($seq + 1));
    }//end generateReference()

    /**
     * Normalise a scope input into a list of trimmed non-empty strings.
     *
     * @param mixed $scope The raw scope input.
     *
     * @return array<int, string> The normalised scope list.
     */
    private function normalizeScope(mixed $scope): array
    {
        if (is_array($scope) === false) {
            return [];
        }

        $clean = [];
        foreach ($scope as $entry) {
            $value = trim((string) $entry);
            if ($value !== '') {
                $clean[] = $value;
            }
        }

        return array_values(array_unique($clean));
    }//end normalizeScope()

    /**
     * Constrain the intake channel to the allowed set.
     *
     * @param string $channel The raw channel.
     *
     * @return string The validated channel.
     */
    private function sanitizeChannel(string $channel): string
    {
        $allowed = ['webformulier', 'handmatig', 'email', 'balie', 'post'];
        if (in_array($channel, $allowed, true) === true) {
            return $channel;
        }

        return 'handmatig';
    }//end sanitizeChannel()

    /**
     * Keep only the digits of a BSN (defensive normalisation; validation lives in
     * the controller / BSN validator).
     *
     * @param string $bsn The raw BSN.
     *
     * @return string The digit-only BSN.
     */
    private function normalizeBsn(string $bsn): string
    {
        return preg_replace('/\D+/', '', $bsn) ?? '';
    }//end normalizeBsn()

    /**
     * The current time as an immutable instant.
     *
     * @return DateTimeImmutable The current time.
     */
    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }//end now()
}//end class
