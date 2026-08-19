<?php

/**
 * Pipelinq PosTransactionService.
 *
 * Business logic for POS transaction lifecycle, server-authoritative total /
 * tax calculation, and CloudEvent emission on confirmation.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/pos-transaction-core/tasks.md#2.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Event\PosStockMovedEvent;
use OCA\Pipelinq\Lifecycle\PosAccessPolicy;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Service for POS transaction business operations.
 *
 * All monetary totals are computed server-side from the persisted line items
 * (server-authoritative). Client-supplied subtotal / tax / total values are
 * never trusted: confirm and recalculate always re-derive them from the lines.
 *
 * Lifecycle transitions are applied through OpenRegister's TransitionEngine
 * (ADR-031): the engine enforces the declarative x-openregister-lifecycle table
 * on the posTransaction schema, runs the registered lifecycle guards (which own
 * the per-object authorization that closes the IDOR), and fires
 * ObjectTransitionedEvent for audit + notifications. This service no longer
 * mutates `status` directly or hand-rolls a manager gate.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Wires the collaborators a POS
 *  lifecycle service legitimately needs (OR container, app config, optional
 *  webhook dispatch, logger); splitting them would add indirection without
 *  reducing real coupling.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The class aggregates the
 *  whole POS lifecycle (calc core + five transition wrappers + event emit + OR
 *  persistence helpers) as many small, single-purpose methods; the cohesion is
 *  intentional and splitting it would scatter one transactional concern across
 *  several classes without reducing real complexity.
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)     The public surface is the POS
 *  calculation core (recalculateLine / computeTotals / normalizePriceMode), the
 *  BTW report aggregator (buildTaxReport / taxReport), the five lifecycle
 *  transition wrappers and the event emitter — all single-purpose and
 *  unit-tested individually; collapsing them would only hide tested seams.
 * @SuppressWarnings(PHPMD.TooManyMethods)           Same cohesion rationale: the
 *  calc core + report + five transition wrappers + engine/error-mapping helpers
 *  + OR persistence helpers are one POS lifecycle concern, intentionally kept
 *  together.
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     The class aggregates the whole
 *  POS transaction lifecycle as many small, single-purpose methods; the length
 *  reflects breadth of a cohesive concern, not tangled logic. Splitting it would
 *  scatter one transactional concern across several classes.
 *
 * @spec openspec/changes/pos-transaction-core/tasks.md#2.1
 * @spec openspec/changes/pos-lifecycle-guard-adoption/tasks.md#3.1
 */
class PosTransactionService {
	/**
	 * CloudEvent type emitted on confirmation.
	 *
	 * @var string
	 */
	public const EVENT_CONFIRMED = 'pipelinq.PosTransaction.confirmed';

	/**
	 * CloudEvent type emitted per settled transaction (stock decrement / COGS).
	 *
	 * @var string
	 *
	 * @spec openspec/changes/pos-stock-moved-event/specs/pos-stock-moved-event/spec.md#Requirement:-A-settled-POS-sale-SHALL-emit-a-typed-stock-moved-CloudEvent
	 */
	public const EVENT_STOCK_MOVED = 'nl.pipelinq.pos.stock.moved';

	/**
	 * CloudEvents source identifier for this app's POS surface.
	 *
	 * @var string
	 */
	private const EVENT_SOURCE = '/apps/pipelinq/pos';

	/**
	 * Cross-app soft-dependency: shillinq's administration context resolver
	 * (mirrors {@see \OCA\Shillinq\Service\AdministrationContextService}, the
	 * same seam TimeBillingHandoffService::resolveAdministrationId() uses).
	 *
	 * @var string
	 */
	private const SHILLINQ_ADMINISTRATION_CONTEXT_SERVICE = 'OCA\\Shillinq\\Service\\AdministrationContextService';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The DI container — retained for the OPTIONAL
	 *                                      lookups whose absence degrades gracefully:
	 *                                      OR WebhookService, OR SchemaMapper, the sibling
	 *                                      PosTenderService, and Shillinq's
	 *                                      AdministrationContextService (ADR-083 rule 1 exception).
	 * @param ObjectServiceInterface $objectService OpenRegister's object service
	 *                                      (ADR-083 rule 1 / ADR-084).
	 * @param TransitionEngine $transitionEngine OpenRegister's lifecycle transition engine.
	 *                                      Typed CONCRETELY because ADR-084 publishes no
	 *                                      contract for it yet; a double exists at
	 *                                      tests/Stubs/Service/Lifecycle/TransitionEngine.php.
	 * @param IAppConfig $appConfig The app config.
	 * @param PosAccessPolicy $policy The shared POS access policy.
	 * @param LoggerInterface $logger The logger.
	 * @param IEventDispatcher $eventDispatcher Event dispatcher for PosStockMovedEvent.
	 */
	public function __construct(
		private ContainerInterface $container,
		private readonly ObjectServiceInterface $objectService,
		private readonly TransitionEngine $transitionEngine,
		private IAppConfig $appConfig,
		private PosAccessPolicy $policy,
		private LoggerInterface $logger,
		private IEventDispatcher $eventDispatcher,
	) {
	}//end __construct()

	/**
	 * Valid price-display / entry modes for a transaction.
	 *
	 * @var array<int, string>
	 */
	private const PRICE_MODES = ['excl', 'incl'];

	/**
	 * Default price mode when none is supplied.
	 *
	 * @var string
	 */
	private const DEFAULT_PRICE_MODE = 'excl';

	/**
	 * Map of optional, non-null-typed string fields on the posTransaction
	 * schema to the non-empty default they must hold before a lifecycle
	 * transition, keyed by property name.
	 *
	 * Such a field materialises as null when it was never written (the magic
	 * column exists but the value is null), and OpenRegister's strict type
	 * validation then rejects null when the TransitionEngine re-saves the whole
	 * object — failing every confirm/settle/refund/park/resume with a 500.
	 * Writing the schema default '' does NOT help: OpenRegister coerces a stored
	 * empty string back to null. Only a NON-EMPTY enum member persists, so each
	 * field is coerced to its non-empty default here. See sanitizeForTransition().
	 *
	 * @var array<string, string>
	 */
	private const TRANSITION_STRING_DEFAULTS = ['consentSyncStatus' => 'pending'];

	/**
	 * Human-readable Dutch GL descriptions per common BTW rate, used to label
	 * the invoiceBreakdown lines shillinq posts. Falls back to a generated
	 * "X% BTW" string for any rate not listed here.
	 *
	 * @var array<int, string>
	 */
	private const RATE_DESCRIPTIONS = [
		0 => 'Nultarief (0%)',
		9 => 'Verlaagd tarief (9%)',
		21 => 'Standaardtarief (21%)',
	];

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
	 * Normalise a client-supplied price mode to a known value.
	 *
	 * Defaults to tax-exclusive ('excl') for any unknown / missing value so a
	 * malformed mode can never change the tax base unexpectedly.
	 *
	 * @param mixed $mode The raw price mode.
	 *
	 * @return string Either 'excl' or 'incl'.
	 *
	 * @spec openspec/specs/pos-nl-btw-engine/spec.md
	 */
	public function normalizePriceMode(mixed $mode): string {
		$value = '';
		if (is_string($mode) === true) {
			$value = strtolower(trim($mode));
		}

		if (in_array($value, self::PRICE_MODES, true) === true) {
			return $value;
		}

		return self::DEFAULT_PRICE_MODE;
	}//end normalizePriceMode()

	/**
	 * Human-readable Dutch GL description for a BTW rate.
	 *
	 * @param float $rate The BTW rate percentage.
	 *
	 * @return string The description.
	 *
	 * @spec openspec/specs/pos-nl-btw-engine/spec.md
	 */
	private function rateDescription(float $rate): string {
		$intRate = (int)round($rate);
		if (isset(self::RATE_DESCRIPTIONS[$intRate]) === true) {
			return self::RATE_DESCRIPTIONS[$intRate];
		}

		return rtrim(rtrim((string)$rate, '0'), '.') . '% BTW';
	}//end rateDescription()

	/**
	 * Compute taxAmount and lineTotal for a single line.
	 *
	 * Pure function: trusts only quantity, unitPrice, discount and taxRate from
	 * the input and overwrites any client-supplied taxAmount / lineTotal. This
	 * is the server-authoritative price computation referenced by REQ-POS-002.
	 *
	 * The optional $priceMode selects how the entered unitPrice is interpreted:
	 *   - 'excl' (default): unitPrice is the net price; tax is added on top.
	 *       net       = quantity * unitPrice * (1 - discount/100)
	 *       taxAmount = net * taxRate/100
	 *   - 'incl': unitPrice already contains BTW; the net is extracted out of it.
	 *       gross     = quantity * unitPrice * (1 - discount/100)
	 *       net       = gross / (1 + taxRate/100)
	 *       taxAmount = gross - net
	 * In both modes:
	 *   lineTotal = net + taxAmount   (the gross, BTW-inclusive line amount)
	 * and the persisted `net` field always carries the tax-exclusive base so the
	 * per-rate breakdown is identical regardless of how prices were entered.
	 *
	 * @param array<string, mixed> $lineData The raw line data.
	 * @param string|null $priceMode The transaction price mode ('excl'|'incl');
	 *                               null falls back to the line's own priceMode
	 *                               or the 'excl' default.
	 *
	 * @return array<string, mixed> The line data with computed net, taxAmount and lineTotal.
	 *
	 * @spec openspec/changes/pos-transaction-core/tasks.md#2.1
	 * @spec openspec/specs/pos-nl-btw-engine/spec.md
	 */
	public function recalculateLine(array $lineData, ?string $priceMode = null): array {
		$quantity = max(0.0, (float)($lineData['quantity'] ?? 0));
		$unitPrice = max(0.0, (float)($lineData['unitPrice'] ?? 0));
		$discount = min(100.0, max(0.0, (float)($lineData['discount'] ?? 0)));
		$taxRate = min(100.0, max(0.0, (float)($lineData['taxRate'] ?? 21)));

		$mode = $this->normalizePriceMode(mode: ($priceMode ?? ($lineData['priceMode'] ?? null)));

		$amount = ($quantity * $unitPrice * (1 - ($discount / 100)));

		// Default ('excl'): entered amount is the net base, BTW added on top.
		$net = $amount;
		$taxAmount = ($net * ($taxRate / 100));

		if ($mode === 'incl') {
			// Entered amount is BTW-inclusive: extract the net base out of it.
			$net = ($amount / (1 + ($taxRate / 100)));
			$taxAmount = ($amount - $net);
		}

		$lineData['quantity'] = $quantity;
		$lineData['unitPrice'] = $unitPrice;
		$lineData['discount'] = $discount;
		$lineData['taxRate'] = $taxRate;
		$lineData['net'] = $this->money(value: $net);
		$lineData['taxAmount'] = $this->money(value: $taxAmount);
		$lineData['lineTotal'] = $this->money(value: ($net + $taxAmount));

		return $lineData;
	}//end recalculateLine()

	/**
	 * Compute aggregate totals for a transaction from its line items.
	 *
	 * Pure function used by recalculateTotals(): groups tax by rate into a
	 * taxBreakdown array and a GL-oriented invoiceBreakdown array (with Dutch
	 * descriptions), and sums subtotal, discountTotal, totalTax and total.
	 * Server-authoritative — derives every figure from the (re)computed lines.
	 *
	 * The optional $priceMode is threaded into recalculateLine so the per-line
	 * net base is extracted correctly whether prices were entered incl. or excl.
	 * BTW. The breakdown bases are always tax-exclusive, so the GL split shillinq
	 * receives is identical regardless of entry mode.
	 *
	 * @param array<int, array<string, mixed>> $lines The transaction's line items.
	 * @param string|null $priceMode The transaction price mode
	 *                               ('excl'|'incl'); null defaults to 'excl'.
	 *
	 * @return array<string, mixed> Computed totals: priceMode, subtotal, discountTotal,
	 *                              taxBreakdown, invoiceBreakdown, totalTax, total.
	 *
	 * @spec openspec/changes/pos-transaction-core/tasks.md#2.1
	 * @spec openspec/specs/pos-nl-btw-engine/spec.md #REQ-BTW-003, #REQ-BTW-004
	 */
	public function computeTotals(array $lines, ?string $priceMode = null): array {
		$mode = $this->normalizePriceMode(mode: $priceMode);
		$subtotal = 0.0;
		$discountTotal = 0.0;
		$totalTax = 0.0;
		$byRate = [];

		foreach ($lines as $rawLine) {
			$line = $this->recalculateLine(lineData: $rawLine, priceMode: $mode);
			$taxRate = (float)$line['taxRate'];

			// Net base is the tax-exclusive amount the line computed for this
			// price mode; the gross-before-discount uses the same mode so the
			// discount figure is consistent (incl-mode strips BTW out first).
			$net = (float)$line['net'];
			$taxAmount = (float)$line['taxAmount'];
			$grossNoDisc = $this->lineNetBeforeDiscount(line: $line, mode: $mode);

			$subtotal += $net;
			$discountTotal += ($grossNoDisc - $net);
			$totalTax += $taxAmount;

			$rateKey = (string)$taxRate;
			if (isset($byRate[$rateKey]) === false) {
				$byRate[$rateKey] = ['rate' => $taxRate, 'base' => 0.0, 'tax' => 0.0];
			}

			$byRate[$rateKey]['base'] += $net;
			$byRate[$rateKey]['tax'] += $taxAmount;
		}//end foreach

		$taxBreakdown = [];
		$invoiceBreakdown = [];
		ksort($byRate, SORT_NUMERIC);
		foreach ($byRate as $entry) {
			$base = $this->money(value: $entry['base']);
			$tax = $this->money(value: $entry['tax']);

			$taxBreakdown[] = [
				'rate' => $entry['rate'],
				'base' => $base,
				'tax' => $tax,
			];

			$invoiceBreakdown[] = [
				'rate' => $entry['rate'],
				'base' => $base,
				'tax' => $tax,
				'description' => $this->rateDescription(rate: (float)$entry['rate']),
			];
		}//end foreach

		$subtotal = $this->money(value: $subtotal);
		$totalTax = $this->money(value: $totalTax);

		return [
			'priceMode' => $mode,
			'subtotal' => $subtotal,
			'discountTotal' => $this->money(value: $discountTotal),
			'taxBreakdown' => $taxBreakdown,
			'invoiceBreakdown' => $invoiceBreakdown,
			'totalTax' => $totalTax,
			'total' => $this->money(value: ($subtotal + $totalTax)),
		];
	}//end computeTotals()

	/**
	 * Tax-exclusive line amount before the line discount, for the given mode.
	 *
	 * In 'excl' mode this is quantity * unitPrice; in 'incl' mode the BTW is
	 * first stripped from the entered (gross) unit price so the discount delta
	 * is measured on a consistent tax-exclusive basis.
	 *
	 * @param array<string, mixed> $line The recomputed line.
	 * @param string $mode The normalised price mode.
	 *
	 * @return float The net amount before discount.
	 *
	 * @spec openspec/specs/pos-nl-btw-engine/spec.md
	 */
	private function lineNetBeforeDiscount(array $line, string $mode): float {
		$quantity = (float)$line['quantity'];
		$unitPrice = (float)$line['unitPrice'];
		$taxRate = (float)$line['taxRate'];
		$gross = ($quantity * $unitPrice);

		if ($mode === 'incl') {
			return ($gross / (1 + ($taxRate / 100)));
		}

		return $gross;
	}//end lineNetBeforeDiscount()

	/**
	 * Aggregate a per-rate BTW compliance report across a set of transactions.
	 *
	 * Sums each transaction's server-computed invoiceBreakdown (falling back to
	 * taxBreakdown for legacy records that predate this engine) into a single
	 * per-rate split that shillinq can post as GL journal lines. Refunded
	 * transactions contribute their breakdown as negative (reversing) amounts so
	 * the report reflects net VAT liability.
	 *
	 * Only confirmed / settled / refunded transactions count toward the report;
	 * drafts and parked carts are excluded as they are not yet fiscally final.
	 *
	 * @param array<int, array<string, mixed>> $transactions The transactions to aggregate.
	 *
	 * @return array<string, mixed> A report: rates (per-rate base/tax/description),
	 *                              totals, and the count of transactions included.
	 *
	 * @spec openspec/specs/pos-nl-btw-engine/spec.md
	 */
	public function buildTaxReport(array $transactions): array {
		$byRate = [];
		$totalBase = 0.0;
		$totalTax = 0.0;
		$includedCount = 0;

		foreach ($transactions as $transaction) {
			$status = (string)($transaction['status'] ?? '');
			if (in_array($status, ['confirmed', 'settled', 'refunded'], true) === false) {
				continue;
			}

			$sign = 1.0;
			if ($status === 'refunded') {
				$sign = -1.0;
			}

			$rows = $transaction['invoiceBreakdown'] ?? $transaction['taxBreakdown'] ?? [];
			if (is_array($rows) === false) {
				continue;
			}

			$includedCount++;
			foreach ($rows as $row) {
				if (is_array($row) === false) {
					continue;
				}

				$rate = (float)($row['rate'] ?? 0);
				$base = ($sign * (float)($row['base'] ?? 0));
				$tax = ($sign * (float)($row['tax'] ?? 0));
				$rateKey = (string)$rate;

				if (isset($byRate[$rateKey]) === false) {
					$byRate[$rateKey] = ['rate' => $rate, 'base' => 0.0, 'tax' => 0.0];
				}

				$byRate[$rateKey]['base'] += $base;
				$byRate[$rateKey]['tax'] += $tax;
				$totalBase += $base;
				$totalTax += $tax;
			}//end foreach
		}//end foreach

		$rates = [];
		ksort($byRate, SORT_NUMERIC);
		foreach ($byRate as $entry) {
			$rates[] = [
				'rate' => $entry['rate'],
				'base' => $this->money(value: $entry['base']),
				'tax' => $this->money(value: $entry['tax']),
				'description' => $this->rateDescription(rate: (float)$entry['rate']),
			];
		}

		return [
			'rates' => $rates,
			'totalBase' => $this->money(value: $totalBase),
			'totalTax' => $this->money(value: $totalTax),
			'transactionCount' => $includedCount,
		];
	}//end buildTaxReport()

	/**
	 * Build the per-rate BTW compliance report for every transaction in this
	 * app's register (optionally narrowed to a status).
	 *
	 * The cross-object BTW report aggregates every fiscally-final transaction in
	 * the register, so it is restricted to POS managers / admins (a cashier may
	 * only see their own sale, not the whole ledger). The gate fails closed.
	 *
	 * @param string|null $status Optional status filter (confirmed/settled/refunded).
	 * @param string $userId The acting user UID (must be a POS manager / admin).
	 *
	 * @return array<string, mixed> The aggregated report.
	 *
	 * @throws OCSForbiddenException If the caller is not a POS manager / admin.
	 *
	 * @spec openspec/specs/pos-nl-btw-engine/spec.md
	 * @spec openspec/changes/pos-lifecycle-guard-adoption/tasks.md#4.3
	 */
	public function taxReport(?string $status = null, string $userId = ''): array {
		if ($this->policy->isManager(userId: $userId) === false) {
			throw new OCSForbiddenException('Alleen een beheerder mag het BTW-rapport opvragen.');
		}

		$transactions = $this->fetchAllTransactions(status: $status);

		return $this->buildTaxReport(transactions: $transactions);
	}//end taxReport()

	/**
	 * Recompute and persist totals for a transaction from its persisted lines.
	 *
	 * @param string $transactionId The transaction UUID.
	 *
	 * @return array<string, mixed> The updated transaction object.
	 *
	 * @throws OCSNotFoundException If the transaction does not exist in this app's register.
	 *
	 * @spec openspec/changes/pos-transaction-core/tasks.md#2.1
	 */
	public function recalculateTotals(string $transactionId): array {
		$transaction = $this->fetchTransaction(id: $transactionId);
		$mode = $this->normalizePriceMode(mode: ($transaction['priceMode'] ?? null));
		$lines = $this->fetchLines(transactionId: $transactionId);
		$totals = $this->computeTotals(lines: $lines, priceMode: $mode);

		$transaction = array_merge($transaction, $totals);

		return $this->saveTransaction(id: $transactionId, transaction: $transaction);
	}//end recalculateTotals()

	/**
	 * Confirm a draft or parked transaction.
	 *
	 * Recomputes server-authoritative totals, validates the cart is non-empty,
	 * sets status=confirmed + confirmedAt, persists, then emits the
	 * pipelinq.PosTransaction.confirmed CloudEvent (fire-and-forget).
	 *
	 * @param string $id The transaction UUID.
	 * @param string $userId The acting user UID.
	 *
	 * @return array<string, mixed> The confirmed transaction.
	 *
	 * @throws OCSNotFoundException If the transaction does not exist.
	 * @throws OCSBadRequestException If status is invalid or the cart is empty.
	 *
	 * @spec openspec/changes/pos-transaction-core/tasks.md#2.1
	 */
	public function confirmTransaction(string $id, string $userId): array {
		// Pre-stage server-authoritative side-effect fields BEFORE the engine
		// applies the status transition. Money is recomputed here (the guard
		// verifies the non-empty-cart precondition and the per-object access
		// rule that closes the IDOR; the engine validates the from-state).
		$transaction = $this->fetchTransaction(id: $id);

		// Pos-customer-link / REQ-PCL-005: 'op rekening' (onAccount) tender
		// requires a linked customer. Enforced server-side here (the UI
		// disables the Afrekenen button but the contract is the server's).
		$this->assertOnAccountHasCustomer(transaction: $transaction);

		$mode = $this->normalizePriceMode(mode: ($transaction['priceMode'] ?? null));
		$lines = $this->fetchLines(transactionId: $id);
		$totals = $this->computeTotals(lines: $lines, priceMode: $mode);

		$transaction = array_merge(
			$transaction,
			$totals,
			[
				'confirmedAt' => $this->now(),
				'parkedAt' => null,
			]
		);
		$this->saveTransaction(id: $id, transaction: $transaction);

		// Apply the transition through OpenRegister: it enforces the lifecycle
		// table, runs PosTransactionConfirmGuard (access + non-empty cart), sets
		// status=confirmed and fires ObjectTransitionedEvent.
		$saved = $this->transitionObject(id: $id, action: 'confirm');

		// Emit the shillinq accounting CloudEvent (fire-and-forget) and persist
		// the resulting event id.
		$eventId = $this->emitConfirmedEvent(transaction: $saved);
		if ($eventId !== '') {
			$saved['cloudEventId'] = $eventId;
			$saved = $this->saveTransaction(id: $id, transaction: $saved);
		}

		$this->logger->info('Pipelinq: POS transaction confirmed', ['id' => $id, 'userId' => $userId]);

		return $saved;
	}//end confirmTransaction()

	/**
	 * Settle a confirmed transaction (payment received).
	 *
	 * @param string $id The transaction UUID.
	 * @param string $userId The acting user UID.
	 *
	 * @return array<string, mixed> The settled transaction.
	 *
	 * @throws OCSNotFoundException If the transaction does not exist.
	 * @throws OCSForbiddenException If the caller may not access the transaction.
	 * @throws OCSBadRequestException If the transaction is not confirmed.
	 *
	 * @spec openspec/changes/pos-transaction-core/tasks.md#2.1
	 * @spec openspec/changes/pos-lifecycle-guard-adoption/tasks.md#3.1
	 */
	public function settleTransaction(string $id, string $userId): array {
		$transaction = $this->fetchTransaction(id: $id);

		// Tender-sum gate (pos-split-tender REQ-PST-004): sum of tenders MUST
		// equal the transaction total before settle. Resolved lazily via the
		// container so this service does not pull PosTenderService into its
		// constructor signature (avoids a circular DI graph with the routes
		// that wire both controllers together).
		$tenderService = $this->resolveTenderService();
		if ($tenderService !== null) {
			$tenderService->assertBalancedForSettle(transactionId: $id);
		}

		$transaction['settledAt'] = $this->now();
		$this->saveTransaction(id: $id, transaction: $transaction);

		$saved = $this->transitionObject(id: $id, action: 'settle');

		// Emit a TenderPostedEvent per tender so Shillinq posts each leg to
		// its configured GL account (pos-split-tender REQ-PST-006). The
		// emitter is fire-and-forget — failure NEVER aborts the settle path
		// (a tender that did not post is picked up by TenderPostedRetryJob).
		if ($tenderService !== null) {
			try {
				$tenderService->emitTendersPosted(transactionId: $id);
			} catch (\Throwable $e) {
				$this->logger->warning(
					'Pipelinq: tender CloudEvent emission failed; retry job will pick up',
					['id' => $id, 'exception' => $e->getMessage()]
				);
			}
		}

		// Emit the stock-moved CloudEvent from the SAME commit path as the
		// tender-posted event(s) above, so a POS sale posts payment AND stock
		// atomically (pos-stock-moved-event). Fire-and-forget: a downstream
		// failure (e.g. shillinq offline) NEVER aborts a completed settle.
		try {
			$this->emitStockMovedEvent(transactionId: $id);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: stock-moved CloudEvent emission failed',
				['id' => $id, 'exception' => $e->getMessage()]
			);
		}

		$this->logger->info('Pipelinq: POS transaction settled', ['id' => $id, 'userId' => $userId]);

		return $saved;
	}//end settleTransaction()

	/**
	 * Lazy resolver for the tender-domain service. Returns null when OR /
	 * tender schemas are not configured (so older fleet apps without the
	 * split-tender migration applied keep working).
	 *
	 * @return PosTenderService|null The service, or null when unavailable.
	 *
	 * @spec openspec/changes/pos-split-tender/specs.md#REQ-PST-004
	 */
	private function resolveTenderService(): ?PosTenderService {
		try {
			$service = $this->container->get(PosTenderService::class);
		} catch (\Throwable $e) {
			return null;
		}

		if ($service instanceof PosTenderService) {
			return $service;
		}

		return null;
	}//end resolveTenderService()

	/**
	 * Emit a PosStockMovedEvent for every sold line on a settled transaction.
	 *
	 * Called from settleTransaction() AFTER the status transition + tender
	 * emission have completed. Resolves each line's `product` ref to its SKU
	 * (the shared key shillinq's inventory keys on) via this app's own
	 * `product` schema — the same field product-vendor-master ingest already
	 * syncs from shillinq (see IngestProductVendorMaster). A line whose
	 * product cannot be resolved to a SKU is still carried with an empty
	 * `productRef`, never dropped, so the shillinq consumer's unmatched-line
	 * audit surface sees it.
	 *
	 * @param string $transactionId The settled transaction UUID.
	 *
	 * @return string The emitted event id, or empty string when there were no
	 *                sold lines / the transaction could not be resolved.
	 *
	 * @spec openspec/changes/pos-stock-moved-event/specs/pos-stock-moved-event/spec.md#Requirement:-A-settled-POS-sale-SHALL-emit-a-typed-stock-moved-CloudEvent
	 */
	public function emitStockMovedEvent(string $transactionId): string {
		try {
			$transaction = $this->fetchTransaction(id: $transactionId);
		} catch (\Throwable $e) {
			return '';
		}

		$lines = $this->fetchLines(transactionId: $transactionId);
		if ($lines === []) {
			return '';
		}

		$eventLines = [];
		foreach ($lines as $line) {
			$productId = (string)($line['product'] ?? '');
			[$sku, $unit] = $this->resolveProductSkuAndUnit(productId: $productId);
			$eventLines[] = [
				'productRef' => $sku,
				'qty' => (float)($line['quantity'] ?? 0),
				'unit' => $unit,
				// Reserved for future multi-location POS support (out of scope
				// today — see the pos-stock-moved-event proposal).
				'location' => '',
			];
		}

		$eventId = $this->uuid();
		$event = new PosStockMovedEvent(
			eventId: $eventId,
			transactionUuid: $transactionId,
			transactionReference: (string)($transaction['reference'] ?? ''),
			administrationId: $this->resolveAdministrationId(),
			lines: $eventLines,
			emittedAt: $this->now(),
		);

		try {
			$this->eventDispatcher->dispatchTyped(event: $event);
		} catch (\Throwable $e) {
			$this->logger->info(
				'Pipelinq POS stock: typed dispatch failed; falling back to webhook',
				['transactionId' => $transactionId, 'exception' => $e->getMessage()]
			);
		}

		// Fire-and-forget to OR WebhookService so external subscribers
		// (Shillinq) get the CloudEvent over their configured webhook URL,
		// mirroring PosTenderService::emitSingleTenderPosted().
		try {
			$webhookService = $this->container->get('OCA\\OpenRegister\\Service\\WebhookService');
			$webhookService->dispatchEvent(
				_event: new Event(),
				eventName: self::EVENT_STOCK_MOVED,
				payload: $event->toCloudEvent()
			);
		} catch (\Throwable $e) {
			$this->logger->debug(
				'Pipelinq POS stock: WebhookService unavailable; skipping external dispatch',
				['transactionId' => $transactionId, 'exception' => $e->getMessage()]
			);
		}

		return $eventId;
	}//end emitStockMovedEvent()

	/**
	 * Resolve a line's `product` ref to its `[sku, unit]` pair via this app's
	 * own `product` schema. Returns `['', '']` when the ref is empty or the
	 * product cannot be resolved / has no sku — the caller still carries the
	 * line (empty productRef), it never silently drops it.
	 *
	 * @param string $productId The product UUID referenced by the line.
	 *
	 * @return array{0: string, 1: string} The `[sku, unit]` pair.
	 *
	 * @spec openspec/changes/pos-stock-moved-event/specs/pos-stock-moved-event/spec.md#Requirement:-A-settled-POS-sale-SHALL-emit-a-typed-stock-moved-CloudEvent
	 */
	private function resolveProductSkuAndUnit(string $productId): array {
		if ($productId === '') {
			return ['', ''];
		}

		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'product_schema', '');
		if ($register === '' || $schema === '') {
			return ['', ''];
		}

		try {
			$product = $this->objectService->find(id: $productId, register: $register, schema: $schema);
		} catch (\Throwable $e) {
			return ['', ''];
		}

		if ($product === null) {
			return ['', ''];
		}

		$productArr = $this->toArray(object: $product);

		return [
			(string)($productArr['sku'] ?? ''),
			(string)($productArr['unit'] ?? ''),
		];
	}//end resolveProductSkuAndUnit()

	/**
	 * Resolve the shillinq administration/tenant id this sale posts to.
	 *
	 * Mirrors {@see TimeBillingHandoffService::resolveAdministrationId()}:
	 * a soft cross-app dependency on shillinq's AdministrationContextService,
	 * falling back to `'default'` when shillinq is not installed/available or
	 * the context cannot be built.
	 *
	 * @return string The administration id.
	 *
	 * @spec openspec/changes/pos-stock-moved-event/specs/pos-stock-moved-event/spec.md#Requirement:-A-settled-POS-sale-SHALL-emit-a-typed-stock-moved-CloudEvent
	 */
	private function resolveAdministrationId(): string {
		try {
			$contextService = $this->container->get(self::SHILLINQ_ADMINISTRATION_CONTEXT_SERVICE);
			if (is_object($contextService) === true && method_exists($contextService, 'buildContext') === true) {
				$context = $contextService->buildContext();
				$candidate = (string)($context['activeAdministrationId'] ?? '');
				if ($candidate !== '') {
					return $candidate;
				}
			}
		} catch (\Throwable $e) {
			// Fall through to the default below.
		}

		return 'default';
	}//end resolveAdministrationId()

	/**
	 * Refund / void a confirmed or settled transaction (manager only).
	 *
	 * @param string $id The transaction UUID.
	 * @param string $reason The refund reason (required).
	 * @param string $userId The acting user UID.
	 *
	 * @return array<string, mixed> The refunded transaction.
	 *
	 * @throws OCSNotFoundException If the transaction does not exist.
	 * @throws OCSForbiddenException If the user lacks manager permission.
	 * @throws OCSBadRequestException If the status is invalid or the reason is empty.
	 *
	 * @spec openspec/changes/pos-transaction-core/tasks.md#2.1
	 * @spec openspec/changes/pos-lifecycle-guard-adoption/tasks.md#3.1
	 */
	public function refundTransaction(string $id, string $reason, string $userId): array {
		if (trim($reason) === '') {
			throw new OCSBadRequestException('Vul een reden in voor de terugboeking.');
		}

		// Manager authorization is enforced by PosTransactionRefundGuard inside
		// the engine; the reason is a side-effect field persisted before the
		// transition.
		$transaction = $this->fetchTransaction(id: $id);
		$transaction['refundedAt'] = $this->now();
		$transaction['refundReason'] = $reason;
		$this->saveTransaction(id: $id, transaction: $transaction);

		$saved = $this->transitionObject(id: $id, action: 'refund');

		$this->logger->info('Pipelinq: POS transaction refunded', ['id' => $id, 'userId' => $userId]);

		return $saved;
	}//end refundTransaction()

	/**
	 * Park a draft transaction so it can be resumed later.
	 *
	 * @param string $id The transaction UUID.
	 * @param string $userId The acting user UID.
	 *
	 * @return array<string, mixed> The parked transaction.
	 *
	 * @throws OCSNotFoundException If the transaction does not exist.
	 * @throws OCSForbiddenException If the caller may not access the transaction.
	 * @throws OCSBadRequestException If the transaction is not a draft.
	 *
	 * @spec openspec/changes/pos-transaction-core/tasks.md#2.1
	 * @spec openspec/changes/pos-lifecycle-guard-adoption/tasks.md#3.1
	 */
	public function parkTransaction(string $id, string $userId): array {
		$transaction = $this->fetchTransaction(id: $id);
		$transaction['parkedAt'] = $this->now();
		$this->saveTransaction(id: $id, transaction: $transaction);

		$saved = $this->transitionObject(id: $id, action: 'park');

		$this->logger->info('Pipelinq: POS transaction parked', ['id' => $id, 'userId' => $userId]);

		return $saved;
	}//end parkTransaction()

	/**
	 * Resume a parked transaction back to draft.
	 *
	 * @param string $id The transaction UUID.
	 * @param string $userId The acting user UID.
	 *
	 * @return array<string, mixed> The resumed transaction.
	 *
	 * @throws OCSNotFoundException If the transaction does not exist.
	 * @throws OCSForbiddenException If the caller may not access the transaction.
	 * @throws OCSBadRequestException If the transaction is not parked.
	 *
	 * @spec openspec/changes/pos-transaction-core/tasks.md#2.1
	 * @spec openspec/changes/pos-lifecycle-guard-adoption/tasks.md#3.1
	 */
	public function resumeTransaction(string $id, string $userId): array {
		$transaction = $this->fetchTransaction(id: $id);
		$transaction['parkedAt'] = null;
		$this->saveTransaction(id: $id, transaction: $transaction);

		$saved = $this->transitionObject(id: $id, action: 'resume');

		$this->logger->info('Pipelinq: POS transaction resumed', ['id' => $id, 'userId' => $userId]);

		return $saved;
	}//end resumeTransaction()

	/**
	 * Assert that an on-account ('op rekening') transaction has a linked customer.
	 *
	 * Mirrors PosCustomerLinkService::assertOnAccountHasCustomer; duplicated
	 * here to avoid a circular service dependency at confirm-time. The check
	 * is a single guarded comparison so duplication has negligible cost and
	 * the invariant is enforced by the same module the lifecycle owns.
	 *
	 * @param array<string, mixed> $transaction The transaction data.
	 *
	 * @return void
	 *
	 * @throws OCSBadRequestException When tenderType is 'onAccount' without a customer.
	 *
	 * @spec openspec/changes/pos-customer-link/specs.md#REQ-PCL-005
	 */
	private function assertOnAccountHasCustomer(array $transaction): void {
		$tender = (string)($transaction['tenderType'] ?? '');
		$customer = (string)($transaction['customer'] ?? '');

		if ($tender === 'onAccount' && $customer === '') {
			throw new OCSBadRequestException(
				"Klant is verplicht voor 'op rekening' transacties."
			);
		}
	}//end assertOnAccountHasCustomer()

	/**
	 * Apply a named lifecycle transition through OpenRegister's TransitionEngine.
	 *
	 * The engine validates the declarative x-openregister-lifecycle table on the
	 * posTransaction schema, enforces per-object `update` RBAC, runs the
	 * transition's registered guard (which owns the per-object authorization
	 * that closes the IDOR), saves through ObjectService, and dispatches
	 * ObjectTransitionedEvent. A denial / invalid transition is mapped to the
	 * appropriate OCS exception so the controller surfaces 403 / 422 / 404.
	 *
	 * @param string $id The transaction UUID.
	 * @param string $action The transition action.
	 *
	 * @return array<string, mixed> The saved transaction as an array.
	 *
	 * @throws OCSForbiddenException When the guard / RBAC denies the transition.
	 * @throws OCSBadRequestException When the transition is invalid from the current state.
	 * @throws OCSNotFoundException When the object cannot be resolved.
	 *
	 * @spec openspec/changes/pos-lifecycle-guard-adoption/tasks.md#3.1
	 */
	private function transitionObject(string $id, string $action): array {
		// Cleanse the STORED object before the engine re-saves it. The
		// TransitionEngine re-reads the whole persisted transaction
		// ($object->getObject()) and re-saves it; OpenRegister's strict type
		// validation then rejects an optional, non-null-typed string field that
		// is stored as null (e.g. `consentSyncStatus`, schema type 'string'
		// default '') with "should be type 'string' but is 'null'" — failing
		// every confirm/settle/refund/park/resume with a 500. saveTransaction()
		// only null-FILTERS its own direct-save path, which leaves the prior
		// stored null untouched, so the engine path bypasses that guard. Coerce
		// the null back to the schema default here so the engine's whole-object
		// re-save validates.
		$this->sanitizeForTransition(id: $id);

		try {
			$saved = $this->transitionEngine->transition(objectId: $id, action: $action);
		} catch (\Throwable $e) {
			throw $this->mapTransitionError(e: $e);
		}

		return $this->toArray(object: $saved);
	}//end transitionObject()

	/**
	 * Coerce null-valued optional string fields to their schema default before
	 * a TransitionEngine re-save.
	 *
	 * Only persists when a coercion is actually needed (so the happy path adds
	 * no extra write). `consentSyncStatus` is the known offender: it is a
	 * non-null-typed string with default '' that materialises as null when the
	 * marketing-consent sync never ran, and the engine's whole-object re-save
	 * then fails OR's strict type validation.
	 *
	 * @param string $id The transaction UUID.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pos-lifecycle-guard-adoption/tasks.md#3.1
	 */
	private function sanitizeForTransition(string $id): void {
		try {
			$transaction = $this->fetchTransaction(id: $id);
		} catch (\Throwable $e) {
			// A missing object is surfaced by the engine call itself as 404; do
			// not mask it here.
			return;
		}

		$needsSave = false;
		foreach (self::TRANSITION_STRING_DEFAULTS as $field => $default) {
			// Coerce the null AND the empty-string case to a NON-EMPTY default.
			// Null is what the engine's whole-object re-save chokes on; an empty
			// string is no use because OpenRegister stores it back as null. A
			// non-empty enum member persists and lets the transition succeed.
			// The `?? ''` collapses a missing/null value to '', so testing for
			// '' alone covers both the absent and the explicit-null cases.
			$value = ($transaction[$field] ?? '');
			if ($value === '') {
				$transaction[$field] = $default;
				$needsSave = true;
			}
		}

		if ($needsSave === true) {
			$this->saveTransaction(id: $id, transaction: $transaction);
		}
	}//end sanitizeForTransition()

	/**
	 * Map a TransitionEngine throwable to the correct OCS exception.
	 *
	 * Authorization denials (NotAuthorizedException / guard deny) → 403; an
	 * invalid-state transition → 422; everything else is rethrown for the
	 * controller's generic 500 handler. Matching is by class short-name + message
	 * so the app does not hard-depend on OR's exception classes at compile time.
	 *
	 * @param \Throwable $e The engine throwable.
	 *
	 * @return \Throwable The mapped OCS exception (or the original on no match).
	 *
	 * @spec openspec/changes/pos-lifecycle-guard-adoption/tasks.md#3.3
	 */
	private function mapTransitionError(\Throwable $e): \Throwable {
		$short = (new ReflectionClass($e))->getShortName();
		$message = $e->getMessage();

		// Per-object RBAC denial from the engine → 403.
		if ($short === 'NotAuthorizedException' || stripos($message, 'permission') !== false) {
			$forbidden = 'Geen toegang tot deze transactie.';
			if ($message !== '') {
				$forbidden = $message;
			}

			return new OCSForbiddenException($forbidden);
		}

		// Guard authorization denials (HookStoppedException carrying the guard's
		// deny message) → 403. The POS access / refund guards phrase denials with
		// these Dutch markers; an IDOR attempt therefore surfaces as 403.
		if ($this->isAccessDenial(message: $message) === true) {
			return new OCSForbiddenException($message);
		}

		// Invalid-state transition or other guard precondition → 422.
		if (stripos($message, 'not allowed from') !== false
			|| stripos($message, 'not declared') !== false
			|| stripos($message, 'artikel') !== false
		) {
			return new OCSBadRequestException($message);
		}

		if (stripos($message, 'not found') !== false) {
			return new OCSNotFoundException($message);
		}

		return $e;
	}//end mapTransitionError()

	/**
	 * Whether a transition-engine error message is an access (authz) denial.
	 *
	 * @param string $message The error message.
	 *
	 * @return bool Whether it represents an access denial (→ HTTP 403).
	 *
	 * @spec openspec/changes/pos-lifecycle-guard-adoption/tasks.md#3.3
	 */
	private function isAccessDenial(string $message): bool {
		foreach (['gemachtigd', 'beheerder', 'mag deze transactie', 'mag een transactie'] as $marker) {
			if (stripos($message, $marker) !== false) {
				return true;
			}
		}

		return false;
	}//end isAccessDenial()

	/**
	 * Emit the pipelinq.PosTransaction.confirmed CloudEvent (fire-and-forget).
	 *
	 * Dispatched through OpenRegister's WebhookService, which delivers to any
	 * webhook subscribed to the event name (e.g. Shillinq's accounting
	 * consumer). If OR / WebhookService is unavailable, or no consumer is yet
	 * configured, this is a silent no-op — confirmation must never fail because
	 * a downstream subscriber is missing.
	 *
	 * @param array<string, mixed> $transaction The confirmed transaction.
	 *
	 * @return string The generated CloudEvents id, or empty string on failure.
	 *
	 * @spec openspec/changes/pos-transaction-core/tasks.md#2.1
	 */
	public function emitConfirmedEvent(array $transaction): string {
		$eventId = $this->uuid();
		$payload = $this->buildConfirmedPayload(eventId: $eventId, transaction: $transaction);

		try {
			$webhookService = $this->container->get('OCA\OpenRegister\Service\WebhookService');
			$event = new Event();
			$webhookService->dispatchEvent(_event: $event, eventName: self::EVENT_CONFIRMED, payload: $payload);
			return $eventId;
		} catch (\Throwable $e) {
			// Fire-and-forget: a missing/failed downstream subscriber must not
			// block confirmation. Log and continue without a stored event id.
			$this->logger->warning(
				'Pipelinq: POS confirmed CloudEvent not dispatched (no consumer or OR unavailable)',
				['exception' => $e->getMessage()]
			);
			return '';
		}//end try
	}//end emitConfirmedEvent()

	/**
	 * Build the CloudEvents 1.0 envelope for a confirmed transaction.
	 *
	 * @param string $eventId The CloudEvents id.
	 * @param array<string, mixed> $transaction The confirmed transaction.
	 *
	 * @return array<string, mixed> The CloudEvent payload.
	 *
	 * @spec openspec/changes/pos-transaction-core/tasks.md#2.1
	 */
	private function buildConfirmedPayload(string $eventId, array $transaction): array {
		$confirmedAt = (string)($transaction['confirmedAt'] ?? '');

		$time = $this->now();
		if ($confirmedAt !== '') {
			$time = $confirmedAt;
		}

		return [
			'specversion' => '1.0',
			'type' => self::EVENT_CONFIRMED,
			'source' => self::EVENT_SOURCE,
			'id' => $eventId,
			'time' => $time,
			'datacontenttype' => 'application/json',
			'data' => [
				'transactionId' => (string)($transaction['id'] ?? $transaction['uuid'] ?? ''),
				'reference' => (string)($transaction['reference'] ?? ''),
				'cashier' => (string)($transaction['cashier'] ?? ''),
				'customer' => (string)($transaction['customer'] ?? ''),
				'tenderType' => (string)($transaction['tenderType'] ?? 'cash'),
				'marketingConsent' => (bool)($transaction['marketingConsent'] ?? false),
				// Pos-staff-pin-permissions REQ-PSP-009: include the active
				// POS staff member id in the shillinq commission feed so the
				// accounting integration can attribute the sale to the staff
				// who actually rang it up (which may differ from the NC cashier
				// user). Empty when no staff session was opened.
				'staffMemberId' => (string)($transaction['staffMemberId'] ?? ''),
				'total' => (float)($transaction['total'] ?? 0),
				'totalTax' => (float)($transaction['totalTax'] ?? 0),
				'priceMode' => $this->normalizePriceMode(mode: ($transaction['priceMode'] ?? null)),
				'taxBreakdown' => ($transaction['taxBreakdown'] ?? []),
				'invoiceBreakdown' => ($transaction['invoiceBreakdown'] ?? []),
				'confirmedAt' => $confirmedAt,
			],
		];
	}//end buildConfirmedPayload()

	/**
	 * Fetch a transaction from this app's register, as an array.
	 *
	 * @param string $id The transaction UUID.
	 *
	 * @return array<string, mixed> The transaction data.
	 *
	 * @throws OCSNotFoundException If the object is not found in this app's posTransaction schema.
	 *
	 * @spec openspec/changes/pos-transaction-core/tasks.md#2.1
	 */
	private function fetchTransaction(string $id): array {
		[$register, $schema] = $this->config(schemaKey: 'posTransaction_schema');

		try {
			$object = $this->objectService->find(id: $id, register: $register, schema: $schema);
		} catch (\Throwable $e) {
			$object = null;
		}

		if ($object === null) {
			throw new OCSNotFoundException('Transactie niet gevonden.');
		}

		return $this->toArray(object: $object);
	}//end fetchTransaction()

	/**
	 * Fetch all line items belonging to a transaction.
	 *
	 * @param string $transactionId The parent transaction UUID.
	 *
	 * @return array<int, array<string, mixed>> The line items.
	 *
	 * @spec openspec/changes/pos-transaction-core/tasks.md#2.1
	 */
	private function fetchLines(string $transactionId): array {
		[$register, $schema] = $this->config(schemaKey: 'posTransactionLine_schema');

		try {
			// The register + schema scope MUST live under a nested `@self` block
			// (OpenRegister's findAll treats flat `filters.register` /
			// `filters.schema` as ordinary property filters, which match nothing
			// and silently return zero rows — the bug that left the cart "empty"
			// and every total at 0). Custom property filters (transaction) stay at
			// the top of the filters array.
			$results = $this->objectService->findAll(
				config: [
					'filters' => [
						'@self' => [
							'register' => $register,
							'schema' => $schema,
						],
						'transaction' => $transactionId,
					],
				]
			);
		} catch (\Throwable $e) {
			$this->logger->warning('Pipelinq: failed to fetch POS lines', ['exception' => $e->getMessage()]);
			return [];
		}//end try

		$lines = [];
		foreach (($results ?? []) as $result) {
			$lines[] = $this->toArray(object: $result);
		}

		return $lines;
	}//end fetchLines()

	/**
	 * Fetch all transactions in this app's register, optionally by status.
	 *
	 * @param string|null $status Optional status filter.
	 *
	 * @return array<int, array<string, mixed>> The transactions.
	 *
	 * @spec openspec/specs/pos-nl-btw-engine/spec.md
	 */
	private function fetchAllTransactions(?string $status = null): array {
		[$register, $schema] = $this->config(schemaKey: 'posTransaction_schema');

		// Register + schema scope under `@self` (flat keys match nothing); status
		// is a real property filter and stays top-level.
		$filters = [
			'@self' => [
				'register' => $register,
				'schema' => $schema,
			],
		];
		if ($status !== null && $status !== '') {
			$filters['status'] = $status;
		}

		try {
			$results = $this->objectService->findAll(config: ['filters' => $filters]);
		} catch (\Throwable $e) {
			$this->logger->warning('Pipelinq: failed to fetch POS transactions', ['exception' => $e->getMessage()]);
			return [];
		}

		$transactions = [];
		foreach (($results ?? []) as $result) {
			$transactions[] = $this->toArray(object: $result);
		}

		return $transactions;
	}//end fetchAllTransactions()

	/**
	 * Persist a transaction object via the OR ObjectService.
	 *
	 * @param string $id The transaction UUID.
	 * @param array<string, mixed> $transaction The transaction data.
	 *
	 * @return array<string, mixed> The saved transaction as an array.
	 *
	 * @spec openspec/changes/pos-transaction-core/tasks.md#2.1
	 */
	private function saveTransaction(string $id, array $transaction): array {
		[$register, $schema] = $this->config(schemaKey: 'posTransaction_schema');

		// Never trust client-derived id/uuid; always write to the resolved id.
		unset($transaction['@self']);

		// Drop null-valued properties before persisting. The confirm/settle path
		// re-saves the whole transaction as fetched from OpenRegister; optional
		// typed fields that were never set (e.g. consentSyncStatus, a string with
		// default '') come back as null, and OpenRegister's strict type
		// validation then rejects null for a non-null-typed property — which
		// previously failed every confirm with "Property 'consentSyncStatus'
		// should be type 'string' but is 'null'". Omitting null keys lets OR
		// re-apply the schema default / treat the optional field as unset.
		$transaction = array_filter(
			$transaction,
			static fn ($value): bool => $value !== null
		);

		$saved = $this->objectService->saveObject(
			object: $transaction,
			extend: [],
			register: $register,
			schema: $schema,
			uuid: $id
		);

		return $this->toArray(object: $saved);
	}//end saveTransaction()

	/**
	 * Resolve the register + a schema config key into their stored IDs.
	 *
	 * @param string $schemaKey The app-config schema key (e.g. posTransaction_schema).
	 *
	 * @return array{0: string, 1: string} The [register, schema] IDs.
	 *
	 * @throws OCSNotFoundException If the register or schema is not configured.
	 *
	 * @spec openspec/changes/pos-transaction-core/tasks.md#2.1
	 */
	private function config(string $schemaKey): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');

		// A deployed app-config frequently leaves the numeric POS schema ids
		// empty (the OpenRegister schema exists under its canonical slug but the
		// admin-settings link was never populated — verified on the dev box where
		// register=16 but posTransaction_schema / posTransactionLine_schema are
		// blank). The server-authoritative reads use ObjectService::findAll()
		// whose `@self.schema` filter requires a NUMERIC schema id (a slug
		// silently returns zero results), so derive the canonical slug from the
		// config key ('posTransaction_schema' -> 'posTransaction') and resolve it
		// to its numeric id via OpenRegister's SchemaMapper. This keeps the
		// confirm / settle / recompute flow functional regardless of config
		// linkage — mirroring the frontend store slug-fallback (src/store/store.js).
		if ($schema === '') {
			$slug = preg_replace('/_schema$/', '', $schemaKey);
			$schema = $this->resolveSchemaIdBySlug(slug: (string)$slug);
		}

		if ($register === '' || $schema === '') {
			throw new OCSNotFoundException('POS register of schema is niet geconfigureerd.');
		}

		return [$register, $schema];
	}//end config()

	/**
	 * Resolve a schema slug to its numeric OpenRegister schema id.
	 *
	 * Used as the fallback when the `<slug>_schema` app-config key is empty on a
	 * deployed instance. OpenRegister's SchemaMapper::find() accepts an id, uuid
	 * or slug and returns the Schema entity; we read its numeric id so the
	 * downstream findAll() filter (which requires a numeric `@self.schema`)
	 * matches. Returns an empty string when the slug cannot be resolved so the
	 * caller raises the standard "not configured" error rather than silently
	 * querying nothing.
	 *
	 * @param string $slug The canonical schema slug (e.g. 'posTransaction').
	 *
	 * @return string The numeric schema id, or '' when it cannot be resolved.
	 *
	 * @spec openspec/changes/pos-transaction-core/tasks.md#2.1
	 */
	private function resolveSchemaIdBySlug(string $slug): string {
		if ($slug === '') {
			return '';
		}

		try {
			$schemaMapper = $this->container->get('OCA\OpenRegister\Db\SchemaMapper');
			// Resolve with RBAC + multi-tenancy disabled: this is a
			// server-authoritative internal id lookup (not a user-scoped read),
			// and the POS schemas may be owned by a different organisation than
			// the acting cashier — the default org-scoped filter would then
			// throw DoesNotExistException for a schema that genuinely exists.
			// NOTE: OpenRegister's Schema is an Entity with MAGIC getters, so
			// method_exists($schema, 'getId') returns false even though
			// $schema->getId() resolves — call getId() directly (any failure is
			// caught below and yields the '' "not configured" path).
			$schema = $schemaMapper->find($slug, [], null, false, false);
			$id = '';
			if (is_object($schema) === true) {
				$id = (string)$schema->getId();
			}

			return $id;
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: could not resolve POS schema slug to id (config fallback)',
				['slug' => $slug, 'exception' => $e->getMessage()]
			);
			return '';
		}//end try
	}//end resolveSchemaIdBySlug()

	/**
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
	 * Current time as an ISO 8601 string.
	 *
	 * @return string The current timestamp.
	 */
	private function now(): string {
		return (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
	}//end now()

	/**
	 * Generate a v4 UUID.
	 *
	 * @return string The UUID.
	 */
	private function uuid(): string {
		$data = random_bytes(16);
		$data[6] = chr((ord($data[6]) & 0x0F) | 0x40);
		$data[8] = chr((ord($data[8]) & 0x3F) | 0x80);

		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}//end uuid()
}//end class
