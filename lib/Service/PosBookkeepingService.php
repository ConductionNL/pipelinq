<?php

/**
 * Pipelinq PosBookkeepingService.
 *
 * Operational POS end-of-day pipeline plus the registry-mediated bookkeeping
 * delegation: aggregating a posTransaction set into a daily posZReport (the
 * commercial cash-drawer / takings reconciliation pipelinq legitimately owns),
 * and raising the accounting consequence of a closed POS day as a
 * `shillinq.JournalEntry.raise` integration message through the ADR-019
 * integration registry (OpenRegister's WebhookService).
 *
 * Per cross-app contract #3 (bookkeeping / billing / accounting -> shillinq),
 * the GL chart of accounts, the VAT->GL posting and the journal entry itself
 * live in shillinq. pipelinq sends the **business facts** of the closed day
 * (date, totals, taxBreakdown) and lets shillinq build the journal; it does NOT
 * own a glAccountMapping chart, does NOT build GL-balanced ledger lines and does
 * NOT persist a parallel posJournalEntryOutbound journal. The shillinq outcome
 * is mirrored back onto the Z-report as a thin projection
 * (`bookkeepingStatus`, `shillinqJournalEntryId`).
 *
 * The deterministic idempotency key (SHA256(zReport.uuid + reportDate)) is
 * preserved across re-raises so shillinq de-duplicates against any journal it
 * already created. The raise is fire-and-retry: if shillinq is unreachable the
 * Z-report still closes (operational) and `bookkeepingStatus` stays `pending`
 * for a later retry — the POS day is never blocked by a bookkeeping outage.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V.
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/pipelinq-bookkeeping-to-shillinq/specs/pipelinq-bookkeeping-to-shillinq/spec.md#REQ-PBTS-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\PosAccessPolicy;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\EventDispatcher\Event;
use OCP\IAppConfig;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Service\WebhookService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Service for the POS end-of-day pipeline + registry-mediated journal raise.
 *
 * Orchestrates three concerns:
 *
 *   1. Z-report aggregation — derive a per-terminal daily settlement from the
 *      confirmed posTransaction set, computing subtotal, taxBreakdown,
 *      paymentMethodBreakdown and total server-side (operational — pipelinq owns).
 *   2. Journal raise — turn a ready Z-report's **business facts** into a
 *      `shillinq.JournalEntry.raise` CloudEvent dispatched through the ADR-019
 *      integration registry (OpenRegister WebhookService), resolved from the
 *      `shillinq_journal_webhook_url` toggle. shillinq builds the GL-balanced
 *      journal; pipelinq sends no pre-mapped ledger lines.
 *   3. Outcome projection — mirror the shillinq journal id + bookkeepingStatus
 *      (pending/raised/failed) onto the parent posZReport so operators can see
 *      whether a POS day is booked without pipelinq re-owning the ledger.
 *
 * Authorization is centralised on PosAccessPolicy: raiseJournalEntry is gated to
 * POS managers (the accounting role). generateZReport is server-internal and
 * called by the background job; it is not directly exposed to a
 * #[NoAdminRequired] endpoint.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Wires the collaborators the
 *  pipeline legitimately needs (OR container for ObjectService + WebhookService,
 *  app config, mailer for alerts, shared POS access policy, logger).
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)     The public surface mirrors
 *  the lifecycle stages, each unit-tested independently.
 * @SuppressWarnings(PHPMD.TooManyMethods)           Private helpers (fetch /
 *  save / sum / time / hash / dispatch) are deliberately small and
 *  single-purpose; merging them would only obscure the lifecycle.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Z-report aggregation +
 *  journal raise + outcome projection is inherently branchy; already split
 *  across small single-purpose helpers.
 *
 * @spec openspec/changes/pipelinq-bookkeeping-to-shillinq/specs/pipelinq-bookkeeping-to-shillinq/spec.md#REQ-PBTS-001
 */
class PosBookkeepingService {
	/**
	 * CloudEvent type emitted when a Z-report is generated.
	 *
	 * @var string
	 */
	public const EVENT_ZREPORT_SUBMITTED = 'pipelinq.PosZReport.submitted';

	/**
	 * ADR-019 integration message type raised in shillinq for a closed POS day.
	 *
	 * @var string
	 */
	public const EVENT_JOURNAL_RAISE = 'shillinq.JournalEntry.raise';

	/**
	 * CloudEvents source identifier for the EOD bookkeeping surface.
	 *
	 * @var string
	 */
	private const EVENT_SOURCE = 'pipelinq/posBookkeeping';

	/**
	 * Default ceiling on raise attempts before terminal failure.
	 *
	 * @var int
	 */
	public const DEFAULT_MAX_ATTEMPTS = 5;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app config.
	 * @param IMailer $mailer The mailer (alert dispatch).
	 * @param PosAccessPolicy $policy The shared POS access policy.
	 * @param LoggerInterface $logger The logger.
	 * @param WebhookService $webhookService Dispatches POS webhooks.
	 * @param ObjectServiceInterface $objectService OpenRegister's published object service.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private IMailer $mailer,
		private PosAccessPolicy $policy,
		private LoggerInterface $logger,
		private readonly WebhookService $webhookService,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Round a monetary value to 2 decimals.
	 *
	 * @param float $value The value to round.
	 *
	 * @return float The value rounded to cents.
	 */
	private function money(float $value): float {
		return round($value, 2);
	}//end money()

	/**
	 * Generate a Z-report for a given reportDate and optional terminal.
	 *
	 * Aggregates every confirmed/settled posTransaction whose confirmedAt falls
	 * on the supplied reportDate (in UTC). When a terminalId is supplied only
	 * transactions on that terminal contribute; when null, the report spans all
	 * terminals. Returns the persisted posZReport array.
	 *
	 * Server-authoritative: subtotal, discountTotal, taxBreakdown, totalTax,
	 * total, paymentMethodBreakdown, transactionCount and transactionIds are
	 * derived from the transactions; the only client input is the date and
	 * optional terminal filter. A non-empty report is created `ready` with a
	 * `pending` bookkeepingStatus so the journal raise can be triggered.
	 *
	 * @param string $reportDate The settlement date in YYYY-MM-DD.
	 * @param string|null $terminalId Optional terminal filter (e.g. kassa-01).
	 *
	 * @return array<string, mixed> The persisted posZReport.
	 *
	 * @throws OCSBadRequestException When the reportDate cannot be parsed.
	 *
	 * @spec openspec/changes/pipelinq-bookkeeping-to-shillinq/specs/pipelinq-bookkeeping-to-shillinq/spec.md#REQ-PBTS-001
	 */
	public function generateZReport(string $reportDate, ?string $terminalId = null): array {
		if ($this->isValidDate(value: $reportDate) === false) {
			throw new OCSBadRequestException('reportDate moet in YYYY-MM-DD formaat zijn.');
		}

		$transactions = $this->fetchTransactionsForDate(reportDate: $reportDate, terminalId: $terminalId);

		$aggregate = $this->aggregateTransactions(transactions: $transactions);

		$status = 'draft';
		if ($aggregate['transactionCount'] > 0) {
			$status = 'ready';
		}

		$reference = 'Z-' . $reportDate;
		if ($terminalId !== null && $terminalId !== '') {
			$reference .= '-' . strtoupper($terminalId);
		}

		$report = [
			'reference' => $reference,
			'reportDate' => $reportDate,
			'terminalId' => $terminalId,
			'transactionIds' => $aggregate['transactionIds'],
			'transactionCount' => $aggregate['transactionCount'],
			'subtotal' => $aggregate['subtotal'],
			'discountTotal' => $aggregate['discountTotal'],
			'taxBreakdown' => $aggregate['taxBreakdown'],
			'totalTax' => $aggregate['totalTax'],
			'total' => $aggregate['total'],
			'paymentMethodBreakdown' => $aggregate['paymentMethodBreakdown'],
			'createdAt' => $this->now(),
			'settledAt' => $aggregate['settledAt'],
			'status' => $status,
			'bookkeepingStatus' => 'pending',
		];

		$persisted = $this->saveObjectFor(schemaKey: 'posZReport_schema', id: '', object: $report);

		$this->emitZReportSubmittedEvent(zReport: $persisted);

		$this->logger->info(
			'Pipelinq: Z-report generated',
			[
				'reportDate' => $reportDate,
				'terminalId' => $terminalId,
				'transactionCount' => $aggregate['transactionCount'],
				'total' => $aggregate['total'],
			]
		);

		return $persisted;
	}//end generateZReport()

	/**
	 * Aggregate a transaction list into the Z-report summary fields.
	 *
	 * Sums subtotal, discountTotal, totalTax and total; groups taxBreakdown by
	 * rate (summing base + tax across all transactions); groups
	 * paymentMethodBreakdown by paymentMethod; collects transactionIds; derives
	 * settledAt from the latest transaction's settledAt / confirmedAt. Pure
	 * function: no side effects.
	 *
	 * @param array<int, array<string, mixed>> $transactions The transactions.
	 *
	 * @return array{
	 *   transactionIds: array<int, string>,
	 *   transactionCount: int,
	 *   subtotal: float,
	 *   discountTotal: float,
	 *   taxBreakdown: array<int, array<string, float|int>>,
	 *   totalTax: float,
	 *   total: float,
	 *   paymentMethodBreakdown: array<int, array<string, float|string>>,
	 *   settledAt: string|null
	 * } The aggregate.
	 *
	 * @spec openspec/changes/pipelinq-bookkeeping-to-shillinq/specs/pipelinq-bookkeeping-to-shillinq/spec.md#REQ-PBTS-001
	 */
	public function aggregateTransactions(array $transactions): array {
		$ids = [];
		$subtotal = 0.0;
		$discountTotal = 0.0;
		$totalTax = 0.0;
		$grandTotal = 0.0;
		$byRate = [];
		$byMethod = [];
		$latestSettled = null;

		foreach ($transactions as $txn) {
			$id = (string)($txn['id'] ?? $txn['uuid'] ?? '');
			if ($id !== '') {
				$ids[] = $id;
			}

			$subtotal += (float)($txn['subtotal'] ?? 0);
			$discountTotal += (float)($txn['discountTotal'] ?? 0);
			$totalTax += (float)($txn['totalTax'] ?? 0);
			$grandTotal += (float)($txn['total'] ?? 0);

			$latestSettled = $this->maxIso(left: $latestSettled, right: (string)($txn['settledAt'] ?? $txn['confirmedAt'] ?? ''));

			foreach (($txn['taxBreakdown'] ?? []) as $entry) {
				$rate = (string)(int)($entry['rate'] ?? 0);
				if (isset($byRate[$rate]) === false) {
					$byRate[$rate] = ['rate' => (int)(float)($entry['rate'] ?? 0), 'base' => 0.0, 'tax' => 0.0];
				}

				$byRate[$rate]['base'] += (float)($entry['base'] ?? 0);
				$byRate[$rate]['tax'] += (float)($entry['tax'] ?? 0);
			}

			$method = (string)($txn['paymentMethod'] ?? '');
			if ($method !== '') {
				if (isset($byMethod[$method]) === false) {
					$byMethod[$method] = ['method' => $method, 'amount' => 0.0];
				}

				$byMethod[$method]['amount'] += (float)($txn['total'] ?? 0);
			}
		}//end foreach

		$taxBreakdown = [];
		foreach ($byRate as $row) {
			$taxBreakdown[] = [
				'rate' => $row['rate'],
				'base' => $this->money(value: (float)$row['base']),
				'tax' => $this->money(value: (float)$row['tax']),
			];
		}

		$paymentBreakdown = [];
		foreach ($byMethod as $row) {
			$paymentBreakdown[] = [
				'method' => $row['method'],
				'amount' => $this->money(value: (float)$row['amount']),
			];
		}

		return [
			'transactionIds' => $ids,
			'transactionCount' => count($ids),
			'subtotal' => $this->money(value: $subtotal),
			'discountTotal' => $this->money(value: $discountTotal),
			'taxBreakdown' => $taxBreakdown,
			'totalTax' => $this->money(value: $totalTax),
			'total' => $this->money(value: $grandTotal),
			'paymentMethodBreakdown' => $paymentBreakdown,
			'settledAt' => $latestSettled,
		];
	}//end aggregateTransactions()

	/**
	 * Compute the deterministic idempotency key for a Z-report.
	 *
	 * Hash is SHA256(zReport.uuid + reportDate) prefixed with `sha256:` so the
	 * shillinq side can detect the algorithm; deterministic so a re-raise with
	 * the same Z-report resolves to the same shillinq journal entry; and unique
	 * per (zReport, reportDate) pair so two Z-reports never collide.
	 *
	 * @param array<string, mixed> $zReport The Z-report object.
	 *
	 * @return string The idempotency key.
	 *
	 * @spec openspec/changes/pipelinq-bookkeeping-to-shillinq/specs/pipelinq-bookkeeping-to-shillinq/spec.md#REQ-PBTS-001
	 */
	public function computeIdempotencyKey(array $zReport): string {
		$uuid = (string)($zReport['id'] ?? $zReport['uuid'] ?? '');
		$date = (string)($zReport['reportDate'] ?? '');

		return 'sha256:' . hash('sha256', $uuid . $date);
	}//end computeIdempotencyKey()

	/**
	 * Whether journal-raise dispatch is enabled.
	 *
	 * Returns true only when shillinq_journal_webhook_url is a non-empty,
	 * well-formed HTTPS URL. An unconfigured or malformed value disables the
	 * integration so the raise no-ops silently and leaves bookkeepingStatus
	 * pending (matching the project-ledger / WIP / AP dispatch toggles).
	 *
	 * @return bool True when a valid HTTPS webhook URL is configured.
	 *
	 * @spec openspec/changes/pipelinq-bookkeeping-to-shillinq/specs/pipelinq-bookkeeping-to-shillinq/spec.md#REQ-PBTS-001
	 */
	public function shouldDispatch(): bool {
		$url = $this->journalWebhookUrl();
		if ($url === '') {
			return false;
		}

		if (filter_var($url, FILTER_VALIDATE_URL) === false) {
			return false;
		}

		return str_starts_with($url, 'https://');
	}//end shouldDispatch()

	/**
	 * Raise the POS-day journal entry in shillinq through the ADR-019 registry.
	 *
	 * Manager-gated (POS manager OR Nextcloud admin via PosAccessPolicy::
	 * isManager). Builds a `shillinq.JournalEntry.raise` CloudEvent carrying the
	 * Z-report **business facts** (date, totals, taxBreakdown) with the
	 * deterministic `X-Idempotency-Key`, dispatches it through OpenRegister's
	 * WebhookService (the ADR-019 registry path) and projects the outcome onto
	 * the parent posZReport:
	 *
	 *   - dispatched         -> bookkeepingStatus = raised (shillinq de-dups via
	 *                           the idempotency key and builds the journal).
	 *   - not configured /   -> bookkeepingStatus = pending (queued for retry;
	 *     shillinq unreachable    the Z-report close is never blocked).
	 *   - max attempts hit   -> bookkeepingStatus = failed + admin alert.
	 *
	 * pipelinq builds NO GL-balanced ledger lines and owns NO chart of accounts;
	 * shillinq maps the taxBreakdown to GL accounts and posts the journal.
	 *
	 * @param string $zReportId The Z-report UUID.
	 * @param string $userId The acting user UID (manager / admin).
	 *
	 * @return array<string, mixed> The persisted Z-report after the raise.
	 *
	 * @throws OCSForbiddenException When the user is not a POS manager / admin.
	 * @throws OCSNotFoundException When the Z-report is missing.
	 *
	 * @spec openspec/changes/pipelinq-bookkeeping-to-shillinq/specs/pipelinq-bookkeeping-to-shillinq/spec.md#REQ-PBTS-001
	 */
	public function raiseJournalEntry(string $zReportId, string $userId): array {
		$this->requireManager(userId: $userId);

		$zReport = $this->fetchZReport(id: $zReportId);

		return $this->dispatchRaise(zReport: $zReport);
	}//end raiseJournalEntry()

	/**
	 * Dispatch the journal raise for a Z-report and persist the outcome.
	 *
	 * Server-internal (no manager gate) so the repair step and the retry job can
	 * re-raise an in-flight Z-report directly. Returns the persisted Z-report.
	 *
	 * The optional $idempotencyKey lets the migration repair step re-raise an
	 * in-flight record with its ORIGINAL key; when omitted the deterministic
	 * SHA256(zReport.uuid + reportDate) key is computed (which equals the
	 * original for any record built by this service), so shillinq always
	 * de-duplicates against the same journal.
	 *
	 * @param array<string, mixed> $zReport The Z-report object.
	 * @param string|null $idempotencyKey Optional original idempotency key.
	 *
	 * @return array<string, mixed> The persisted Z-report after the raise.
	 *
	 * @spec openspec/changes/pipelinq-bookkeeping-to-shillinq/specs/pipelinq-bookkeeping-to-shillinq/spec.md#REQ-PBTS-001
	 */
	public function dispatchRaise(array $zReport, ?string $idempotencyKey = null): array {
		if ($idempotencyKey === null || $idempotencyKey === '') {
			$idempotencyKey = $this->computeIdempotencyKey(zReport: $zReport);
		}

		if ($this->shouldDispatch() === false) {
			return $this->projectPending(
				zReport: $zReport,
				reason: 'shillinq journal integration not configured'
			);
		}

		$payload = $this->buildRaisePayload(zReport: $zReport, idempotencyKey: $idempotencyKey);
		$dispatched = $this->dispatch(eventName: self::EVENT_JOURNAL_RAISE, payload: $payload);

		if ($dispatched === false) {
			return $this->onRaiseFailure(zReport: $zReport);
		}

		return $this->onRaised(zReport: $zReport, idempotencyKey: $idempotencyKey);
	}//end dispatchRaise()

	/**
	 * Persist a successful raise: bookkeepingStatus -> raised.
	 *
	 * The shillinq journal id is the deterministic idempotency key digest until
	 * the (out-of-scope) reverse outcome sync overwrites it with shillinq's own
	 * id; this is sufficient for the operator "this day is booked" projection
	 * and keeps the re-raise resolving to the same journal.
	 *
	 * @param array<string, mixed> $zReport The Z-report.
	 * @param string $idempotencyKey The deterministic key.
	 *
	 * @return array<string, mixed> The persisted Z-report.
	 */
	private function onRaised(array $zReport, string $idempotencyKey): array {
		$zReport['bookkeepingStatus'] = 'raised';
		$zReport['shillinqJournalEntryId'] = $idempotencyKey;
		$zReport['bookkeepingAttempts'] = ((int)($zReport['bookkeepingAttempts'] ?? 0)) + 1;

		$saved = $this->saveObjectFor(
			schemaKey: 'posZReport_schema',
			id: (string)($zReport['id'] ?? $zReport['uuid'] ?? ''),
			object: $zReport
		);

		$this->logger->info(
			'Pipelinq: POS-day journal raised in shillinq via registry',
			['zReportId' => (string)($zReport['id'] ?? ''), 'idempotencyKey' => $idempotencyKey]
		);

		return $saved;
	}//end onRaised()

	/**
	 * Project bookkeepingStatus = pending without incrementing the attempt count.
	 *
	 * Used when the integration is unconfigured / shillinq is unreachable: the
	 * POS day still closes and the raise is left queued for retry.
	 *
	 * @param array<string, mixed> $zReport The Z-report.
	 * @param string $reason Why the raise was deferred.
	 *
	 * @return array<string, mixed> The persisted Z-report.
	 */
	private function projectPending(array $zReport, string $reason): array {
		$zReport['bookkeepingStatus'] = 'pending';

		$saved = $this->saveObjectFor(
			schemaKey: 'posZReport_schema',
			id: (string)($zReport['id'] ?? $zReport['uuid'] ?? ''),
			object: $zReport
		);

		$this->logger->info(
			'Pipelinq: POS-day journal raise deferred (pending)',
			['zReportId' => (string)($zReport['id'] ?? ''), 'reason' => $reason]
		);

		return $saved;
	}//end projectPending()

	/**
	 * Persist a failed raise attempt: pending until max attempts, then failed.
	 *
	 * Increments the attempt counter; once the configured ceiling is reached the
	 * bookkeepingStatus becomes `failed` and the accounting administrator is
	 * alerted. Below the ceiling the status stays `pending` for the retry job.
	 *
	 * @param array<string, mixed> $zReport The Z-report.
	 *
	 * @return array<string, mixed> The persisted Z-report.
	 */
	private function onRaiseFailure(array $zReport): array {
		$attempts = ((int)($zReport['bookkeepingAttempts'] ?? 0)) + 1;
		$zReport['bookkeepingAttempts'] = $attempts;

		if ($attempts >= $this->maxAttempts()) {
			$zReport['bookkeepingStatus'] = 'failed';

			$saved = $this->saveObjectFor(
				schemaKey: 'posZReport_schema',
				id: (string)($zReport['id'] ?? $zReport['uuid'] ?? ''),
				object: $zReport
			);

			$this->sendAlertEmail(
				subject: 'POS bookkeeping: journaalpost raise naar shillinq permanent gefaald',
				body: 'Z-report ' . ((string)($zReport['reference'] ?? ($zReport['id'] ?? '')))
					. ' kon na ' . $attempts . ' pogingen niet naar shillinq worden geraised.'
			);

			$this->logger->warning(
				'Pipelinq: POS-day journal raise max-retries exhausted',
				['zReportId' => (string)($zReport['id'] ?? ''), 'attempts' => $attempts]
			);

			return $saved;
		}//end if

		$zReport['bookkeepingStatus'] = 'pending';

		$saved = $this->saveObjectFor(
			schemaKey: 'posZReport_schema',
			id: (string)($zReport['id'] ?? $zReport['uuid'] ?? ''),
			object: $zReport
		);

		$this->logger->info(
			'Pipelinq: POS-day journal raise transient failure, will retry',
			['zReportId' => (string)($zReport['id'] ?? ''), 'attempts' => $attempts]
		);

		return $saved;
	}//end onRaiseFailure()

	/**
	 * Build the `shillinq.JournalEntry.raise` CloudEvent payload.
	 *
	 * Carries the Z-report **business facts** only — date, totals, taxBreakdown
	 * — so shillinq builds the GL-balanced journal. pipelinq sends NO pre-mapped
	 * debit/credit ledger lines and NO chart-of-accounts. The deterministic
	 * idempotency key lets shillinq de-duplicate a re-raise against the same
	 * journal.
	 *
	 * @param array<string, mixed> $zReport The Z-report.
	 * @param string $idempotencyKey The deterministic idempotency key.
	 *
	 * @return array<string, mixed> The CloudEvent payload.
	 *
	 * @spec openspec/changes/pipelinq-bookkeeping-to-shillinq/specs/pipelinq-bookkeeping-to-shillinq/spec.md#REQ-PBTS-001
	 */
	public function buildRaisePayload(array $zReport, string $idempotencyKey): array {
		$zReportId = (string)($zReport['id'] ?? $zReport['uuid'] ?? '');

		return [
			'specversion' => '1.0',
			'type' => self::EVENT_JOURNAL_RAISE,
			'source' => self::EVENT_SOURCE,
			'id' => $idempotencyKey,
			'time' => $this->now(),
			'subject' => (string)($zReport['reference'] ?? $zReportId),
			'datacontenttype' => 'application/json',
			'data' => [
				'idempotencyKey' => $idempotencyKey,
				'zReportId' => $zReportId,
				'reference' => (string)($zReport['reference'] ?? ''),
				'postingDate' => (string)($zReport['reportDate'] ?? ''),
				'terminalId' => (string)($zReport['terminalId'] ?? ''),
				'currency' => 'EUR',
				'subtotal' => (float)($zReport['subtotal'] ?? 0),
				'totalTax' => (float)($zReport['totalTax'] ?? 0),
				'total' => (float)($zReport['total'] ?? 0),
				'taxBreakdown' => (array)($zReport['taxBreakdown'] ?? []),
				'transactionCount' => (int)($zReport['transactionCount'] ?? 0),
			],
		];
	}//end buildRaisePayload()

	/**
	 * Emit the pipelinq.PosZReport.submitted CloudEvent on Z-report generation.
	 *
	 * Fire-and-forget. Carries the Z-report summary so a downstream
	 * reconciliation consumer can correlate it with its eventual journal raise
	 * (via subject = Z-report reference).
	 *
	 * @param array<string, mixed> $zReport The persisted Z-report.
	 *
	 * @return bool True on successful dispatch, false otherwise.
	 *
	 * @spec openspec/changes/pipelinq-bookkeeping-to-shillinq/specs/pipelinq-bookkeeping-to-shillinq/spec.md#REQ-PBTS-001
	 */
	public function emitZReportSubmittedEvent(array $zReport): bool {
		$payload = [
			'specversion' => '1.0',
			'type' => self::EVENT_ZREPORT_SUBMITTED,
			'source' => self::EVENT_SOURCE,
			'id' => (string)($zReport['id'] ?? $zReport['uuid'] ?? ''),
			'time' => $this->now(),
			'subject' => (string)($zReport['reference'] ?? ($zReport['id'] ?? '')),
			'datacontenttype' => 'application/json',
			'data' => [
				'zReportId' => (string)($zReport['id'] ?? $zReport['uuid'] ?? ''),
				'reportDate' => (string)($zReport['reportDate'] ?? ''),
				'terminalId' => (string)($zReport['terminalId'] ?? ''),
				'transactionCount' => (int)($zReport['transactionCount'] ?? 0),
				'total' => (float)($zReport['total'] ?? 0),
				'currency' => 'EUR',
			],
		];

		return $this->dispatch(eventName: self::EVENT_ZREPORT_SUBMITTED, payload: $payload);
	}//end emitZReportSubmittedEvent()

	/**
	 * Dispatch a CloudEvent through OpenRegister's WebhookService (ADR-019).
	 *
	 * Fire-and-forget: any failure to resolve or invoke the WebhookService is
	 * logged and reported as a false return so the caller can leave the raise
	 * pending, but never throws — a missing consumer or an unavailable
	 * OpenRegister must never fail the originating Z-report close.
	 *
	 * @param string $eventName The webhook event name.
	 * @param array<string, mixed> $payload The CloudEvent payload.
	 *
	 * @return bool True on successful dispatch, false on failure.
	 */
	private function dispatch(string $eventName, array $payload): bool {
		try {
			$event = new Event();
			$this->webhookService->dispatchEvent(
				_event: $event,
				eventName: $eventName,
				payload: $payload
			);
			return true;
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: bookkeeping CloudEvent not dispatched (no consumer or OpenRegister unavailable)',
				['exception' => $e->getMessage(), 'eventName' => $eventName]
			);
			return false;
		}//end try
	}//end dispatch()

	/**
	 * Send an alert email to the configured accounting administrator.
	 *
	 * Best-effort. Failure to send is logged but never propagated.
	 *
	 * @param string $subject The alert subject.
	 * @param string $body The alert body.
	 *
	 * @return void
	 */
	private function sendAlertEmail(string $subject, string $body): void {
		$to = trim($this->appConfig->getValueString(Application::APP_ID, 'pos_eod.alert_email', ''));
		if ($to === '') {
			return;
		}

		try {
			$message = $this->mailer->createMessage();
			$message->setTo([$to]);
			$message->setSubject($subject);
			$message->setPlainBody($body);
			$this->mailer->send($message);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: POS bookkeeping alert email not sent',
				['exception' => $e->getMessage(), 'to' => $to, 'subject' => $subject]
			);
		}
	}//end sendAlertEmail()

	/**
	 * Fetch confirmed/settled posTransactions matching a date + optional terminal.
	 *
	 * Reads every posTransaction, then filters in-PHP by status (confirmed,
	 * settled), terminalId (when supplied) and reportDate (matching the day of
	 * `settledAt` or `confirmedAt` in UTC). A row that cannot be parsed simply
	 * does not contribute — the report degrades gracefully rather than aborting.
	 *
	 * @param string $reportDate The settlement date in YYYY-MM-DD.
	 * @param string|null $terminalId Optional terminal filter.
	 *
	 * @return array<int, array<string, mixed>> The matching transactions.
	 */
	private function fetchTransactionsForDate(string $reportDate, ?string $terminalId): array {
		[$register, $schema] = $this->config(schemaKey: 'posTransaction_schema');

		try {
			$results = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'register' => $register,
						'schema' => $schema,
					],
				]
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: failed to read POS transactions for Z-report; total assumed 0',
				['exception' => $e->getMessage()]
			);
			return [];
		}

		$matches = [];
		foreach (($results ?? []) as $result) {
			$txn = $this->toArray(object: $result);
			$status = (string)($txn['status'] ?? '');
			if (in_array($status, ['confirmed', 'settled'], true) === false) {
				continue;
			}

			if ($terminalId !== null && $terminalId !== '' && (string)($txn['terminalId'] ?? '') !== $terminalId) {
				continue;
			}

			$stamp = (string)($txn['settledAt'] ?? $txn['confirmedAt'] ?? '');
			$day = $this->isoDay(value: $stamp);
			if ($day !== $reportDate) {
				continue;
			}

			$matches[] = $txn;
		}

		return $matches;
	}//end fetchTransactionsForDate()

	/**
	 * Assert the user is a POS manager (accounting role).
	 *
	 * @param string $userId The acting user UID.
	 *
	 * @return void
	 *
	 * @throws OCSForbiddenException When the user is not a manager / admin.
	 */
	private function requireManager(string $userId): void {
		if ($this->policy->isManager(userId: $userId) === false) {
			throw new OCSForbiddenException('Alleen accounting-beheerders mogen de journaalpost naar shillinq raisen.');
		}
	}//end requireManager()

	/**
	 * Fetch a posZReport by UUID (scoped to this app's schema).
	 *
	 * @param string $id The Z-report UUID.
	 *
	 * @return array<string, mixed> The Z-report.
	 *
	 * @throws OCSNotFoundException When the Z-report is not found.
	 * @spec openspec/specs/time-approval-workflow/spec.md#requirement-approved-time-entries-are-emitted-to-shillinqs-time-intake
	 */
	public function fetchZReport(string $id): array {
		return $this->fetchOne(schemaKey: 'posZReport_schema', id: $id, label: 'Z-report niet gevonden.');
	}//end fetchZReport()

	/**
	 * Fetch a single object by UUID for a schema config key.
	 *
	 * @param string $schemaKey The app-config schema key.
	 * @param string $id The object UUID.
	 * @param string $label The not-found error message.
	 *
	 * @return array<string, mixed> The object.
	 *
	 * @throws OCSNotFoundException When the object is not found.
	 */
	private function fetchOne(string $schemaKey, string $id, string $label): array {
		[$register, $schema] = $this->config(schemaKey: $schemaKey);

		try {
			$object = $this->getObjectService()->find(id: $id, register: $register, schema: $schema);
		} catch (\Throwable $e) {
			$object = null;
		}

		if ($object === null) {
			throw new OCSNotFoundException($label);
		}

		return $this->toArray(object: $object);
	}//end fetchOne()

	/**
	 * Persist an object via the OR ObjectService for a schema config key.
	 *
	 * @param string $schemaKey The app-config schema key.
	 * @param string $id The object UUID (empty to create).
	 * @param array<string, mixed> $object The object data.
	 *
	 * @return array<string, mixed> The saved object.
	 */
	private function saveObjectFor(string $schemaKey, string $id, array $object): array {
		[$register, $schema] = $this->config(schemaKey: $schemaKey);

		unset($object['@self']);

		$saved = $this->getObjectService()->saveObject(
			object: $object,
			extend: [],
			register: $register,
			schema: $schema,
			uuid: $id
		);

		return $this->toArray(object: $saved);
	}//end saveObjectFor()

	/**
	 * Resolve the register + a schema config key into their stored IDs.
	 *
	 * @param string $schemaKey The app-config schema key.
	 *
	 * @return array{0: string, 1: string} The [register, schema] IDs.
	 *
	 * @throws OCSNotFoundException When the register or schema is not configured.
	 */
	private function config(string $schemaKey): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');

		if ($register === '' || $schema === '') {
			throw new OCSNotFoundException('Bookkeeping-register of -schema is niet geconfigureerd.');
		}

		return [$register, $schema];
	}//end config()

	/**
	 * Read the configured shillinq journal-entry registry webhook URL.
	 *
	 * @return string The configured URL, or an empty string when unset.
	 */
	private function journalWebhookUrl(): string {
		return trim($this->appConfig->getValueString(Application::APP_ID, 'shillinq_journal_webhook_url', ''));
	}//end journalWebhookUrl()

	/**
	 * Read the configured max raise attempts (default {@see self::DEFAULT_MAX_ATTEMPTS}).
	 *
	 * @return int The max attempts (>= 1).
	 *
	 * @spec openspec/changes/pipelinq-bookkeeping-to-shillinq/specs/pipelinq-bookkeeping-to-shillinq/spec.md#REQ-PBTS-002
	 */
	public function maxAttempts(): int {
		$raw = trim(
			$this->appConfig->getValueString(
				Application::APP_ID,
				'pos_eod.max_retry_attempts',
				(string)self::DEFAULT_MAX_ATTEMPTS
			)
		);

		$candidate = (int)$raw;
		if ($candidate > 0) {
			return $candidate;
		}

		return self::DEFAULT_MAX_ATTEMPTS;
	}//end maxAttempts()

	/**
	 * Get the OpenRegister ObjectService.
	 *
	 * @return object The object service.
	 */
	private function getObjectService(): object {
		// Injected (ADR-083): a property read throws nothing, so the old
		// catch was unreachable — phpstan reports it as a dead catch.
		return $this->objectService;
	}//end getObjectService()

	/**
	 * Normalise an OR object (entity or array) into a plain array.
	 *
	 * @param mixed $object The OR object.
	 *
	 * @return array<string, mixed> The object as an array.
	 */
	private function toArray(mixed $object): array {
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

		return (array)$object;
	}//end toArray()

	/**
	 * The greater of two ISO 8601 timestamp strings (null-safe).
	 *
	 * @param string|null $left The left value.
	 * @param string|null $right The right value.
	 *
	 * @return string|null The greater timestamp, or null when both are empty.
	 */
	private function maxIso(?string $left, ?string $right): ?string {
		$l = $left;
		if ($left === null || $left === '') {
			$l = null;
		}

		$r = $right;
		if ($right === null || $right === '') {
			$r = null;
		}

		if ($l === null) {
			return $r;
		}

		if ($r === null) {
			return $l;
		}

		if (strcmp($l, $r) >= 0) {
			return $l;
		}

		return $r;
	}//end maxIso()

	/**
	 * Extract the day portion of an ISO 8601 timestamp in UTC.
	 *
	 * @param string $value The ISO 8601 timestamp.
	 *
	 * @return string The day in YYYY-MM-DD, or empty string on parse failure.
	 */
	private function isoDay(string $value): string {
		if ($value === '') {
			return '';
		}

		try {
			$dateTime = new DateTimeImmutable($value);
			return $dateTime->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d');
		} catch (\Throwable $e) {
			return '';
		}
	}//end isoDay()

	/**
	 * Whether a YYYY-MM-DD string is a parseable date.
	 *
	 * @param string $value The candidate date.
	 *
	 * @return bool True when valid.
	 */
	private function isValidDate(string $value): bool {
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
			return false;
		}

		try {
			new DateTimeImmutable($value);
			return true;
		} catch (\Throwable $e) {
			return false;
		}
	}//end isValidDate()

	/**
	 * Current time as an ISO 8601 string in UTC.
	 *
	 * Public so the background jobs and unit tests can stamp timestamps
	 * consistently without each coupling directly to the date-time classes.
	 *
	 * @return string The current timestamp.
	 *
	 * @spec openspec/changes/pipelinq-bookkeeping-to-shillinq/specs/pipelinq-bookkeeping-to-shillinq/spec.md#REQ-PBTS-001
	 */
	public function now(): string {
		return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
	}//end now()
}//end class
