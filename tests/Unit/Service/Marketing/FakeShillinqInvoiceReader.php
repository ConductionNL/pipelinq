<?php

/**
 * A ShillinqInvoiceReader double that answers from arrays.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Marketing
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-bookkeeping-supplies-six-segment-fields-and-pipelinq-stores-none-of-them
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Marketing;

use OCA\Pipelinq\Service\ShillinqInvoiceReader;

/**
 * A reader that answers from arrays instead of shillinq's register.
 *
 * It stands in for the real reader rather than being mocked, because the
 * degradation path is the behaviour under test and `isAvailable()` returning
 * false has to be reachable without shillinq being installed.
 */
class FakeShillinqInvoiceReader extends ShillinqInvoiceReader {

	/**
	 * Whether shillinq answers at all.
	 *
	 * @var bool
	 */
	public bool $available = true;

	/**
	 * Customer reference to invoices.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	public array $invoices = [];

	/**
	 * Construct without the real collaborators.
	 */
	public function __construct() {
	}//end __construct()

	/**
	 * Whether shillinq answers.
	 *
	 * @return bool True when it does.
	 */
	public function isAvailable(): bool {
		return $this->available;
	}//end isAvailable()

	/**
	 * Every invoice past the draft stage.
	 *
	 * @param string $customerRef The customer.
	 *
	 * @return array<int, array<string, mixed>> The invoices.
	 */
	public function invoicesFor(string $customerRef): array {
		if ($this->available === false) {
			return [];
		}

		$out = [];
		foreach (($this->invoices[$customerRef] ?? []) as $invoice) {
			if ((string)($invoice['lifecycleState'] ?? '') !== 'draft') {
				$out[] = $invoice;
			}
		}

		return $out;
	}//end invoicesFor()

	/**
	 * The paid invoices inside a window.
	 *
	 * @param string $customerRef The customer.
	 * @param string $from Window start.
	 * @param string $to Window end.
	 *
	 * @return array<int, array<string, mixed>> The invoices.
	 */
	public function paidInvoicesFor(string $customerRef, string $from, string $to): array {
		$out = [];
		foreach ($this->invoicesFor(customerRef: $customerRef) as $invoice) {
			$date = (string)($invoice['invoiceDate'] ?? '');
			if ((string)($invoice['lifecycleState'] ?? '') !== 'paid') {
				continue;
			}

			if ($date !== '' && ($date < $from || $date > $to)) {
				continue;
			}

			$out[] = $invoice;
		}

		return $out;
	}//end paidInvoicesFor()
}//end class
