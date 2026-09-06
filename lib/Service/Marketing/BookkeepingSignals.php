<?php

/**
 * Pipelinq BookkeepingSignals.
 *
 * The six segment fields that come out of shillinq: recognised revenue, the
 * value tier it falls into, months since the last invoice, the catalogue
 * products and services the customer was invoiced for, and where they stand
 * with the credit control.
 *
 * 🔴 READ ONLY, AND NOTHING IS STORED. Money stays in shillinq (ADR-107).
 * Every amount comes back through {@see ShillinqInvoiceReader}, which has no
 * write path, and nothing computed here is written to a pipelinq object. A
 * tier cached on a client is a second source of truth that goes stale
 * silently, which is the failure a derived field exists to avoid.
 *
 * 🔴 EVERY FIELD ANSWERS NULL WHEN IT CANNOT BE READ, NEVER ZERO. A zero is a
 * number the segment evaluator compares, and `gte`, `lte` and `between` all
 * treat an unreadable value as a match. Null is what lets
 * {@see SegmentSignalService} refuse the leaf instead.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Marketing
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-bookkeeping-supplies-six-segment-fields-and-pipelinq-stores-none-of-them
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Marketing;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\ShillinqInvoiceReader;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;

/**
 * BookkeepingSignals: the six shillinq-derived fields, or six nulls.
 *
 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-bookkeeping-supplies-six-segment-fields-and-pipelinq-stores-none-of-them
 */
class BookkeepingSignals {

	/**
	 * Trailing months of bookkeeping a revenue signal reads by default.
	 *
	 * @var int
	 */
	private const DEFAULT_WINDOW_MONTHS = 12;

	/**
	 * Revenue at or above which a customer is top tier, by default.
	 *
	 * @var float
	 */
	private const DEFAULT_TOP_THRESHOLD = 25000.0;

	/**
	 * Revenue at or above which a customer is mid tier, by default.
	 *
	 * @var float
	 */
	private const DEFAULT_MID_THRESHOLD = 5000.0;

	/**
	 * Average days in a month, for the whole-month count.
	 *
	 * @var float
	 */
	private const MONTH = 30.436875;

	/**
	 * Seconds in a day.
	 *
	 * @var int
	 */
	private const DAY = 86400;

	/**
	 * The dunning states, worst first.
	 *
	 * @var array<int, string>
	 */
	private const WORST_FIRST = ['written-off', 'overdue', 'disputed'];

	/**
	 * Constructor.
	 *
	 * @param ShillinqInvoiceReader $invoices Read-only shillinq invoice reader.
	 * @param IAppConfig $appConfig Pipelinq app config.
	 * @param ITimeFactory $time Clock.
	 *
	 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-bookkeeping-supplies-six-segment-fields-and-pipelinq-stores-none-of-them
	 */
	public function __construct(
		private ShillinqInvoiceReader $invoices,
		private IAppConfig $appConfig,
		private ITimeFactory $time,
	) {
	}//end __construct()

	/**
	 * Whether shillinq answers at all.
	 *
	 * @return bool True when it is installed.
	 *
	 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-an-unresolved-signal-shrinks-the-audience
	 */
	public function isAvailable(): bool {
		return $this->invoices->isAvailable();
	}//end isAvailable()

	/**
	 * The six signals for one shillinq customer.
	 *
	 * @param string $customerRef The shillinq customer reference, empty when
	 *                            the client records none.
	 * @param array<string, string> $catalogue Lowercase product name to `product` or `service`.
	 *
	 * @return array<string, mixed> The six fields, all null when unreadable.
	 *
	 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-bookkeeping-supplies-six-segment-fields-and-pipelinq-stores-none-of-them
	 */
	public function forCustomer(string $customerRef, array $catalogue): array {
		$blank = [
			SegmentSignalService::FIELD_REVENUE => null,
			SegmentSignalService::FIELD_VALUE_TIER => null,
			SegmentSignalService::FIELD_MONTHS_SINCE_INVOICE => null,
			SegmentSignalService::FIELD_PRODUCTS => null,
			SegmentSignalService::FIELD_SERVICES => null,
			SegmentSignalService::FIELD_DUNNING => null,
		];

		$customerRef = trim($customerRef);
		if ($customerRef === '' || $this->invoices->isAvailable() === false) {
			return $blank;
		}

		$invoices = $this->invoices->invoicesFor(customerRef: $customerRef);
		if ($invoices === []) {
			return $blank;
		}

		$revenue = $this->revenueOf(customerRef: $customerRef);

		return [
			SegmentSignalService::FIELD_REVENUE => $revenue,
			SegmentSignalService::FIELD_VALUE_TIER => $this->tierOf(revenue: $revenue),
			SegmentSignalService::FIELD_MONTHS_SINCE_INVOICE => $this->monthsSince(invoices: $invoices),
			SegmentSignalService::FIELD_PRODUCTS => $this->itemsOf(invoices: $invoices, catalogue: $catalogue, wanted: 'product'),
			SegmentSignalService::FIELD_SERVICES => $this->itemsOf(invoices: $invoices, catalogue: $catalogue, wanted: 'service'),
			SegmentSignalService::FIELD_DUNNING => $this->dunningOf(invoices: $invoices),
		];
	}//end forCustomer()

	/**
	 * Paid revenue over the signal window.
	 *
	 * @param string $customerRef The shillinq customer reference.
	 *
	 * @return float The sum of paid gross amounts.
	 */
	private function revenueOf(string $customerRef): float {
		$now = $this->time->getTime();
		$months = max(1, $this->appConfig->getValueInt(Application::APP_ID, 'marketing.signal_window_months', self::DEFAULT_WINDOW_MONTHS));
		$from = date('Y-m-d', (int)strtotime('-' . $months . ' months', $now));
		$to = date('Y-m-d', $now);

		$total = 0.0;
		foreach ($this->invoices->paidInvoicesFor(customerRef: $customerRef, from: $from, to: $to) as $invoice) {
			$total += (float)($invoice['amount'] ?? 0);
		}

		return round($total, 2);
	}//end revenueOf()

	/**
	 * The tier revenue falls into.
	 *
	 * @param float $revenue Recognised revenue over the window.
	 *
	 * @return string One of top, mid, low, none.
	 */
	private function tierOf(float $revenue): string {
		if ($revenue >= $this->threshold(key: 'marketing.value_tier_top', default: self::DEFAULT_TOP_THRESHOLD)) {
			return 'top';
		}

		if ($revenue >= $this->threshold(key: 'marketing.value_tier_mid', default: self::DEFAULT_MID_THRESHOLD)) {
			return 'mid';
		}

		if ($revenue > 0.0) {
			return 'low';
		}

		return 'none';
	}//end tierOf()

	/**
	 * Whole months since the most recent invoice.
	 *
	 * @param array<int, array<string, mixed>> $invoices The customer's invoices.
	 *
	 * @return int|null The month count, or null when no invoice carries a date.
	 */
	private function monthsSince(array $invoices): ?int {
		$latest = '';
		foreach ($invoices as $invoice) {
			$date = (string)($invoice['invoiceDate'] ?? '');
			if ($date > $latest) {
				$latest = $date;
			}
		}

		if ($latest === '') {
			return null;
		}

		$stamp = strtotime($latest);
		if ($stamp === false) {
			return null;
		}

		$days = (int)floor((($this->time->getTime() - $stamp) / self::DAY));
		if ($days < 0) {
			return 0;
		}

		return (int)floor(($days / self::MONTH));
	}//end monthsSince()

	/**
	 * The catalogue items of one kind this customer has been invoiced for.
	 *
	 * An invoice line names an item in free text. Rather than guessing
	 * whether "Adviesuur" is a product or a service from its unit code, the
	 * line is matched against pipelinq's own product catalogue, which already
	 * records which of the two it is. A line matching nothing contributes to
	 * neither list: a guess in a cross-sell audience mails the wrong people
	 * and nobody can see why.
	 *
	 * @param array<int, array<string, mixed>> $invoices The customer's invoices.
	 * @param array<string, string> $catalogue Lowercase product name to its kind.
	 * @param string $wanted `product` or `service`.
	 *
	 * @return array<int, string> The matched catalogue names, unique and sorted.
	 */
	private function itemsOf(array $invoices, array $catalogue, string $wanted): array {
		$found = [];
		foreach ($invoices as $invoice) {
			foreach ((array)($invoice['lines'] ?? []) as $line) {
				$key = strtolower(trim((string)($line['itemName'] ?? '')));
				if ($key !== '' && ($catalogue[$key] ?? '') === $wanted) {
					$found[$key] = true;
				}
			}
		}

		$names = array_keys($found);
		sort($names);
		return $names;
	}//end itemsOf()

	/**
	 * Where the customer stands with the credit control.
	 *
	 * @param array<int, array<string, mixed>> $invoices The customer's invoices.
	 *
	 * @return string One of written-off, overdue, disputed, current, unknown.
	 */
	private function dunningOf(array $invoices): string {
		$states = [];
		foreach ($invoices as $invoice) {
			$states[(string)($invoice['lifecycleState'] ?? '')] = true;
		}

		foreach (self::WORST_FIRST as $worst) {
			if (array_key_exists($worst, $states) === true) {
				return $worst;
			}
		}

		if ($states === []) {
			return SegmentSignalService::DUNNING_UNKNOWN;
		}

		return 'current';
	}//end dunningOf()

	/**
	 * One configured tier threshold.
	 *
	 * @param string $key App-config key.
	 * @param float $default Fallback when the key is unset or unreadable.
	 *
	 * @return float The threshold.
	 */
	private function threshold(string $key, float $default): float {
		$raw = trim($this->appConfig->getValueString(Application::APP_ID, $key, ''));
		if ($raw === '' || is_numeric($raw) === false) {
			return $default;
		}

		return (float)$raw;
	}//end threshold()
}//end class
