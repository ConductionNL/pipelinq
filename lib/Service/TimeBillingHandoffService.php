<?php

/**
 * Pipelinq TimeBillingHandoffService.
 *
 * Real emit side of the shillinq time-approval-workflow handoff
 * (time-billing-handoff-emit). Groups approved, un-billed time entries per
 * client + period into an idempotent intake batch and posts it to shillinq's
 * `POST /apps/shillinq/api/billing/time-intake` (shillinq's
 * time-expense-invoice-intake change), same-instance, in the acting user's
 * session, via the app's established container-resolved cross-app pattern
 * (the same `ContainerInterface->get(FQCN)` seam used for OpenRegister's
 * ObjectService — never a `use` import of a shillinq class, so this class
 * loads cleanly whether or not shillinq is installed).
 *
 * Idempotency: the batch id is a deterministic UUIDv5 over (client UUID,
 * period start/end, sorted entry UUIDs) — the same selection always produces
 * the same batchId, so a re-send after an ambiguous failure replays
 * (`duplicated:true`) instead of double-billing. This mirrors the codebase's
 * deterministic-key precedent, `PosBookkeepingService::computeIdempotencyKey()`
 * (`SHA256(zReport.uuid + reportDate)`).
 *
 * Outcome handling: entries are marked `pending` and stamped with the batchId
 * BEFORE the call. On success every entry becomes `synced` with the returned
 * `billingInvoiceId` (whether or not the response was a `duplicated:true`
 * replay — the same status is stored either way). A 409 (batch in-flight)
 * leaves the entries `pending` for a later retry. A 422 (unresolvable
 * organisationRef/rateRef) is terminal-actionable — surfaced with the
 * unmapped client's name, never blind-retried, and the entries also stay
 * `pending` so a corrected mapping can be re-sent under the same
 * deterministic batchId. A transport/5xx/malformed-payload failure marks the
 * entries `failed` and notifies administrators
 * ({@see BillingHandoffNotifier}); per the orchestrator's binding ruling for
 * this slice, the periodic {@see \OCA\Pipelinq\BackgroundJob\BillingHandoffRetryJob}
 * only re-notifies (it never re-attempts the call itself, since it runs
 * without a Nextcloud session and the intake resolves its tenant from one) —
 * the guaranteed re-send path is the manual "Send to billing" action,
 * re-triggered in session context, which recomputes the identical
 * deterministic batchId for the still-unbilled entries.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/time-approval-workflow/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Batches approved time entries and posts them to shillinq's time-intake.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Thin cross-app emit service:
 *  OR ObjectService + shillinq's two services, resolved loosely via the
 *  container, plus the notifier/config/session collaborators.
 *
 * @spec openspec/specs/time-approval-workflow/spec.md
 */
class TimeBillingHandoffService
{
    /**
     * Fixed namespace UUID (RFC 4122 URL namespace) seeding the deterministic
     * UUIDv5 batch id. Any fixed constant works — it only needs to be stable
     * across calls, never re-derived from anything shillinq-owned.
     *
     * @var string
     */
    private const BATCH_NAMESPACE_UUID = '6ba7b811-9dad-11d1-80b4-00c04fd430c8';

    /**
     * The billing model this slice supports (matches shillinq's contract).
     *
     * @var string
     */
    private const BILLING_MODEL = 't_and_m';

    /**
     * The FQCN of shillinq's intake service, resolved loosely via the
     * container so this class never `use`-imports a shillinq class.
     *
     * @var string
     */
    private const SHILLINQ_INTAKE_SERVICE = 'OCA\\Shillinq\\Service\\TimeIntakeService';

    /**
     * The FQCN of shillinq's tenant-context resolver, resolved loosely via
     * the container (mirrors {@see \OCA\Shillinq\Controller\BillingIntakeController::resolveAdministrationId()}).
     *
     * @var string
     */
    private const SHILLINQ_ADMINISTRATION_CONTEXT_SERVICE = 'OCA\\Shillinq\\Service\\AdministrationContextService';

    /**
     * Constructor.
     *
     * @param IAppConfig             $appConfig   The app config.
     * @param IAppManager            $appManager  The app manager (shillinq install/enabled detection).
     * @param IUserSession           $userSession The user session (acting user id).
     * @param ContainerInterface     $container   The DI container (OR ObjectService + shillinq seam lookup).
     * @param BillingHandoffNotifier $notifier    The admin failure notifier.
     * @param LoggerInterface        $logger      The logger.
     */
    public function __construct(
        private IAppConfig $appConfig,
        private IAppManager $appManager,
        private IUserSession $userSession,
        private ContainerInterface $container,
        private BillingHandoffNotifier $notifier,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Whether the shillinq time-intake handoff is available right now.
     *
     * True only when the `shillinq_time_intake_enabled` flag is on AND the
     * shillinq app is installed and enabled for the acting user. When false,
     * callers keep offering today's deep-link handoff (`shillinq_app_url`)
     * unchanged.
     *
     * @return bool Whether the intake emit may be attempted.
     *
     * @spec openspec/specs/time-approval-workflow/spec.md
     */
    public function handoffAvailable(): bool
    {
        $flag = $this->appConfig->getValueString(Application::APP_ID, 'shillinq_time_intake_enabled', 'false');
        if ($flag !== 'true') {
            return false;
        }

        try {
            return $this->appManager->isEnabledForUser('shillinq');
        } catch (Throwable $e) {
            return false;
        }
    }//end handoffAvailable()

    /**
     * Select the approved, un-billed time entries for a client + period.
     *
     * Only `status = approved` entries for the given client, with no
     * `billingInvoiceId` yet, and a `date` inside `[periodStart, periodEnd]`
     * (inclusive, ISO 8601 date strings) are selected. Sorted by id so the
     * selection — and therefore the derived batchId — is deterministic.
     *
     * @param string $clientId    The client UUID.
     * @param string $periodStart The period start date (ISO 8601, inclusive).
     * @param string $periodEnd   The period end date (ISO 8601, inclusive).
     *
     * @return array<int, array<string, mixed>> The selected time entries.
     *
     * @spec openspec/specs/time-approval-workflow/spec.md
     */
    public function selectBatch(string $clientId, string $periodStart, string $periodEnd): array
    {
        if ($clientId === '') {
            return [];
        }

        $objectService = $this->objectService();
        $register      = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema        = $this->appConfig->getValueString(Application::APP_ID, 'timeEntry_schema', '');
        if ($objectService === null || $register === '' || $schema === '') {
            return [];
        }

        try {
            $rows = $objectService->findAll(
                config: [
                    'filters' => [
                        'register' => $register,
                        'schema'   => $schema,
                    ],
                ]
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'TimeBillingHandoffService: failed to read time entries',
                ['exception' => $e->getMessage()]
            );
            return [];
        }

        $selected = [];
        foreach (($rows ?? []) as $row) {
            $data = $this->toArray(value: $row);
            if ((string) ($data['status'] ?? '') !== 'approved') {
                continue;
            }

            if ((string) ($data['client'] ?? '') !== $clientId) {
                continue;
            }

            if ((string) ($data['billingInvoiceId'] ?? '') !== '') {
                continue;
            }

            $date = (string) ($data['date'] ?? '');
            if ($date === '' || $date < $periodStart || $date > $periodEnd) {
                continue;
            }

            $selected[] = $data;
        }//end foreach

        usort(
            $selected,
            static fn (array $a, array $b): int => strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? ''))
        );

        return $selected;
    }//end selectBatch()

    /**
     * Send a client's approved, un-billed entries for a period to billing.
     *
     * Returns a status array (never throws to the caller):
     *   - `not-available` — shillinq absent/disabled or the flag is off.
     *   - `empty`         — nothing approved and un-billed in the period.
     *   - `synced`        — the batch was accepted (`invoiceId`/`invoiceNumber`/`duplicated`/`entryCount`).
     *   - `conflict`      — 409, batch in-flight; entries stay `pending`.
     *   - `unmapped`      — 422, actionable message naming the unmapped client/rate; entries stay `pending`.
     *   - `failed`        — transport/5xx/malformed payload; entries marked `failed`, admins notified.
     *
     * @param string $clientId    The client UUID.
     * @param string $periodStart The period start date (ISO 8601, inclusive).
     * @param string $periodEnd   The period end date (ISO 8601, inclusive).
     *
     * @return array<string, mixed> The outcome.
     *
     * @spec openspec/specs/time-approval-workflow/spec.md
     */
    public function sendToBilling(string $clientId, string $periodStart, string $periodEnd): array
    {
        if ($this->handoffAvailable() === false) {
            return ['status' => 'not-available'];
        }

        $entries = $this->selectBatch(clientId: $clientId, periodStart: $periodStart, periodEnd: $periodEnd);
        if (empty($entries) === true) {
            return ['status' => 'empty'];
        }

        return $this->dispatchBatch(
            clientId: $clientId,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            entries: $entries
        );
    }//end sendToBilling()

    /**
     * Notify administrators about every batch currently `failed`.
     *
     * The periodic retry job's ONLY job (orchestrator ruling): it never
     * re-attempts the intake call itself (no Nextcloud session in job
     * context, and the intake resolves its tenant from one) — it re-notifies
     * so the failure is not forgotten, grouped once per `billingBatchId` per
     * run. The guaranteed re-send is the manual "Send to billing" action.
     *
     * @return array<int, string> The batch ids that were re-notified.
     *
     * @spec openspec/specs/time-approval-workflow/spec.md
     */
    public function notifyPendingFailures(): array
    {
        $objectService = $this->objectService();
        $register      = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema        = $this->appConfig->getValueString(Application::APP_ID, 'timeEntry_schema', '');
        if ($objectService === null || $register === '' || $schema === '') {
            return [];
        }

        try {
            $rows = $objectService->findAll(
                config: [
                    'filters' => [
                        'register' => $register,
                        'schema'   => $schema,
                    ],
                ]
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'TimeBillingHandoffService: failed to read failed batches',
                ['exception' => $e->getMessage()]
            );
            return [];
        }

        $byBatch = [];
        foreach (($rows ?? []) as $row) {
            $data = $this->toArray(value: $row);
            if ((string) ($data['billingSyncStatus'] ?? '') !== 'failed') {
                continue;
            }

            $batchId  = (string) ($data['billingBatchId'] ?? '');
            $clientId = (string) ($data['client'] ?? '');
            if ($batchId === '' || $clientId === '') {
                continue;
            }

            if (isset($byBatch[$batchId]) === false) {
                $byBatch[$batchId] = $clientId;
            }
        }//end foreach

        $notified = [];
        foreach ($byBatch as $batchId => $clientId) {
            $client     = $this->fetchClient(clientId: $clientId);
            $clientName = (string) ($client['name'] ?? $clientId);
            $this->notifier->notifyFailure(clientName: $clientName, clientId: $clientId, batchId: $batchId);
            $notified[] = $batchId;
        }

        return $notified;
    }//end notifyPendingFailures()

    /**
     * Build the batch payload and dispatch it to shillinq's intake.
     *
     * @param string                           $clientId    The client UUID.
     * @param string                           $periodStart The period start date.
     * @param string                           $periodEnd   The period end date.
     * @param array<int, array<string, mixed>> $entries     The selected time entries.
     *
     * @return array<string, mixed> The outcome.
     */
    private function dispatchBatch(string $clientId, string $periodStart, string $periodEnd, array $entries): array
    {
        $client          = $this->fetchClient(clientId: $clientId);
        $clientName      = (string) ($client['name'] ?? $clientId);
        $organisationRef = (string) ($client['shillinqOrganisationRef'] ?? '');

        $batchId = $this->computeBatchId(
            clientId: $clientId,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            entries: $entries
        );

        // Mark pending + stamp the batchId BEFORE the call (mark-then-act,
        // the TimeApprovalListener pattern) so a mid-call failure is never
        // ambiguous with "never attempted".
        foreach ($entries as &$entry) {
            $entry['billingSyncStatus'] = 'pending';
            $entry['billingBatchId']    = $batchId;
            $this->persistEntry(entry: $entry);
        }

        unset($entry);

        $intakeService = $this->resolveIntakeService();
        if ($intakeService === null) {
            $this->markFailed(entries: $entries);
            $this->notifier->notifyFailure(clientName: $clientName, clientId: $clientId, batchId: $batchId);
            return [
                'status'  => 'failed',
                'batchId' => $batchId,
                'message' => 'The shillinq time-intake service could not be resolved.',
            ];
        }

        $payload = $this->buildPayload(
            batchId: $batchId,
            organisationRef: $organisationRef,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            entries: $entries
        );

        try {
            $result = $intakeService->ingest(
                administrationId: $this->resolveAdministrationId(),
                personId: $this->actingUserId(),
                body: $payload
            );
        } catch (\InvalidArgumentException $e) {
            // Malformed payload — a bug on our side, not an admin-fixable
            // data-mapping issue. Idempotent replay makes a blind retry
            // harmless, so it is treated the same as a transport failure.
            $this->markFailed(entries: $entries);
            $this->notifier->notifyFailure(clientName: $clientName, clientId: $clientId, batchId: $batchId);
            return ['status' => 'failed', 'batchId' => $batchId, 'message' => $e->getMessage()];
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
            if (str_starts_with($message, 'Conflict:') === true) {
                // 409 — batch in-flight. Entries stay `pending` from the
                // mark-then-act above; retried later, never a new batchId.
                return ['status' => 'conflict', 'batchId' => $batchId, 'message' => $message];
            }

            // 422 — unresolvable organisationRef/rateRef. Terminal-actionable,
            // never blind-retried; entries stay `pending` so a corrected
            // mapping can be re-sent under the same deterministic batchId.
            return [
                'status'  => 'unmapped',
                'batchId' => $batchId,
                'message' => sprintf('%s (client: %s)', $message, $clientName),
            ];
        } catch (Throwable $e) {
            $this->markFailed(entries: $entries);
            $this->notifier->notifyFailure(clientName: $clientName, clientId: $clientId, batchId: $batchId);
            return ['status' => 'failed', 'batchId' => $batchId, 'message' => $e->getMessage()];
        }//end try

        $invoiceId     = (string) ($result['invoiceId'] ?? '');
        $invoiceNumber = (string) ($result['invoiceNumber'] ?? '');
        $duplicated    = (bool) ($result['duplicated'] ?? false);

        foreach ($entries as $entry) {
            $entry['billingSyncStatus'] = 'synced';
            $entry['billingInvoiceId']  = $invoiceId;
            $this->persistEntry(entry: $entry);
        }

        return [
            'status'        => 'synced',
            'batchId'       => $batchId,
            'invoiceId'     => $invoiceId,
            'invoiceNumber' => $invoiceNumber,
            'duplicated'    => $duplicated,
            'entryCount'    => count($entries),
        ];
    }//end dispatchBatch()

    /**
     * Build the shillinq time-intake request body for a batch.
     *
     * @param string                           $batchId         The deterministic batch id.
     * @param string                           $organisationRef The client's shillinq organisation reference.
     * @param string                           $periodStart     The period start date.
     * @param string                           $periodEnd       The period end date.
     * @param array<int, array<string, mixed>> $entries         The selected time entries.
     *
     * @return array<string, mixed> The intake request body.
     */
    private function buildPayload(
        string $batchId,
        string $organisationRef,
        string $periodStart,
        string $periodEnd,
        array $entries
    ): array {
        $currency = $this->appConfig->getValueString(Application::APP_ID, 'currency', 'EUR');

        $entryPayloads = [];
        foreach ($entries as $entry) {
            $projectRef = (string) ($entry['project'] ?? '');
            if ($projectRef === '') {
                $projectRef = null;
            }

            $entryPayloads[] = [
                'externalId'  => (string) ($entry['id'] ?? ''),
                'date'        => (string) ($entry['date'] ?? ''),
                'minutes'     => (int) round(((float) ($entry['hours'] ?? 0)) * 60),
                'description' => (string) ($entry['description'] ?? ($entry['title'] ?? '')),
                'hourlyRate'  => null,
                'rateRef'     => null,
                'projectRef'  => $projectRef,
            ];
        }

        return [
            'batchId'         => $batchId,
            'organisationRef' => $organisationRef,
            'currency'        => $currency,
            'billingModel'    => self::BILLING_MODEL,
            'period'          => [
                'start' => $periodStart,
                'end'   => $periodEnd,
            ],
            // Always null in the MVP — shillinq's rate cards resolve
            // defaults (design.md, deferred question resolved provisionally).
            'rateCardId'      => null,
            // Batching is per client + period, not per project, so no single
            // batch-level project applies; each entry carries its own.
            'projectRef'      => null,
            'notes'           => '',
            'entries'         => $entryPayloads,
            // Expenses are accepted-and-ignored by this slice (proposal.md).
            'expenses'        => [],
        ];
    }//end buildPayload()

    /**
     * Compute the deterministic UUIDv5 batch id.
     *
     * @param string                           $clientId    The client UUID.
     * @param string                           $periodStart The period start date.
     * @param string                           $periodEnd   The period end date.
     * @param array<int, array<string, mixed>> $entries     The selected time entries.
     *
     * @return string The deterministic batch id.
     */
    private function computeBatchId(string $clientId, string $periodStart, string $periodEnd, array $entries): string
    {
        $entryIds = array_map(
            static fn (array $entry): string => (string) ($entry['id'] ?? ''),
            $entries
        );
        sort($entryIds);

        $name = sprintf(
            'pipelinq:billing-batch:%s:%s:%s:%s',
            $clientId,
            $periodStart,
            $periodEnd,
            implode(',', $entryIds)
        );

        return $this->uuidV5(namespace: self::BATCH_NAMESPACE_UUID, name: $name);
    }//end computeBatchId()

    /**
     * Compute an RFC 4122 UUIDv5 (name-based, SHA-1) without an external
     * dependency (the app has no ramsey/uuid requirement).
     *
     * @param string $namespace The namespace UUID.
     * @param string $name      The name to hash within the namespace.
     *
     * @return string The UUIDv5 string.
     */
    private function uuidV5(string $namespace, string $name): string
    {
        $namespaceHex = str_replace(['-', '{', '}'], '', $namespace);
        $namespaceBin = '';
        for ($i = 0, $length = strlen($namespaceHex); $i < $length; $i += 2) {
            $namespaceBin .= chr((int) hexdec($namespaceHex[$i].$namespaceHex[$i + 1]));
        }

        $hash = sha1($namespaceBin.$name);

        return sprintf(
            '%08s-%04s-%04x-%04x-%12s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            (hexdec(substr($hash, 12, 4)) & 0x0fff) | 0x5000,
            (hexdec(substr($hash, 16, 4)) & 0x3fff) | 0x8000,
            substr($hash, 20, 12)
        );
    }//end uuidV5()

    /**
     * Mark every entry in a batch `failed` and persist it.
     *
     * @param array<int, array<string, mixed>> $entries The batch entries.
     *
     * @return void
     */
    private function markFailed(array $entries): void
    {
        foreach ($entries as $entry) {
            $entry['billingSyncStatus'] = 'failed';
            $this->persistEntry(entry: $entry);
        }
    }//end markFailed()

    /**
     * Persist a mutated time entry back to OpenRegister.
     *
     * @param array<string, mixed> $entry The mutated time entry data.
     *
     * @return void
     */
    private function persistEntry(array $entry): void
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, 'timeEntry_schema', '');
        $uuid     = (string) ($entry['id'] ?? '');
        if ($register === '' || $schema === '' || $uuid === '') {
            return;
        }

        $objectService = $this->objectService();
        if ($objectService === null) {
            return;
        }

        try {
            $objectService->saveObject(
                object: $entry,
                extend: [],
                register: $register,
                schema: $schema,
                uuid: $uuid
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'TimeBillingHandoffService: failed to persist time entry billing status',
                ['exception' => $e->getMessage(), 'uuid' => $uuid]
            );
        }//end try
    }//end persistEntry()

    /**
     * Fetch a client's data by UUID.
     *
     * @param string $clientId The client UUID.
     *
     * @return array<string, mixed>|null The client data, or null when not found.
     */
    private function fetchClient(string $clientId): ?array
    {
        if ($clientId === '') {
            return null;
        }

        $objectService = $this->objectService();
        $register      = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema        = $this->appConfig->getValueString(Application::APP_ID, 'client_schema', '');
        if ($objectService === null || $register === '' || $schema === '') {
            return null;
        }

        try {
            $entity = $objectService->find(id: $clientId, register: $register, schema: $schema);
        } catch (Throwable $e) {
            return null;
        }

        if ($entity === null) {
            return null;
        }

        return $this->toArray(value: $entity);
    }//end fetchClient()

    /**
     * Resolve the OpenRegister ObjectService loosely.
     *
     * @return object|null The service, or null when unavailable.
     */
    private function objectService(): ?object
    {
        try {
            $service = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
        } catch (Throwable $e) {
            return null;
        }

        if (is_object($service) === false) {
            return null;
        }

        return $service;
    }//end objectService()

    /**
     * Resolve shillinq's time-intake service loosely via the container.
     *
     * Never `use`-imports the shillinq class, so this class (and its unit
     * tests) load cleanly whether or not shillinq is installed.
     *
     * @return object|null The intake service, or null when unavailable.
     */
    private function resolveIntakeService(): ?object
    {
        try {
            $service = $this->container->get(self::SHILLINQ_INTAKE_SERVICE);
        } catch (Throwable $e) {
            return null;
        }

        if (is_object($service) === false || method_exists($service, 'ingest') === false) {
            return null;
        }

        return $service;
    }//end resolveIntakeService()

    /**
     * Resolve the shillinq administration (tenant) id server-side, same as
     * {@see \OCA\Shillinq\Controller\BillingIntakeController::resolveAdministrationId()}
     * — never client-supplied.
     *
     * @return string The resolved administration id, or `default` when
     *                shillinq's context service is unavailable.
     */
    private function resolveAdministrationId(): string
    {
        try {
            $contextService = $this->container->get(self::SHILLINQ_ADMINISTRATION_CONTEXT_SERVICE);
            if (is_object($contextService) === true && method_exists($contextService, 'buildContext') === true) {
                $context   = $contextService->buildContext();
                $candidate = (string) ($context['activeAdministrationId'] ?? '');
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        } catch (Throwable $e) {
            // Fall through to the default below.
        }

        return 'default';
    }//end resolveAdministrationId()

    /**
     * The acting user's UID (empty when there is no session — e.g. CLI/job
     * context, where the manual-send codepath is never invoked).
     *
     * @return string The acting user UID.
     */
    private function actingUserId(): string
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return '';
        }

        return $user->getUID();
    }//end actingUserId()

    /**
     * Normalise an OR object (entity or array) into a plain array.
     *
     * @param mixed $value The OR object.
     *
     * @return array<string, mixed> The object as an array.
     */
    private function toArray(mixed $value): array
    {
        if (is_array($value) === true) {
            return $value;
        }

        if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
            $serialised = $value->jsonSerialize();
            if (is_array($serialised) === true) {
                return $serialised;
            }
        }

        if (is_object($value) === true && method_exists($value, 'getObject') === true) {
            $data = $value->getObject();
            if (is_array($data) === true) {
                return $data;
            }
        }

        return (array) $value;
    }//end toArray()
}//end class
