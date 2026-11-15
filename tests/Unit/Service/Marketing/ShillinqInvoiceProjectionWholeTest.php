<?php

/**
 * ShillinqInvoiceProjection::whole and ::identify: which invoices are kept
 * and the shape they are reduced to.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Marketing
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/marketing-integrated-campaigns/spec.md#requirement-the-bookkeeping-supplies-six-segment-fields-and-pipelinq-stores-none-of-them
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Marketing;

use OCA\Pipelinq\Service\Marketing\ShillinqInvoiceProjection;
use PHPUnit\Framework\TestCase;

/**
 * Drafts, stateless and id-less rows are dropped; the rest are reduced.
 *
 * @covers \OCA\Pipelinq\Service\Marketing\ShillinqInvoiceProjection
 */
class ShillinqInvoiceProjectionWholeTest extends TestCase {

	/**
	 * A draft, a row without a state and a row without any id are not invoices.
	 *
	 * @return void
	 */
	public function testDraftsStatelessAndIdlessRowsAreDropped(): void {
		$projection = new ShillinqInvoiceProjection();

		$this->assertNull($projection->whole(row: ['uuid' => 'i-1', 'lifecycleState' => ' Draft ']));
		$this->assertNull($projection->whole(row: ['uuid' => 'i-1', 'lifecycleState' => '  ']));
		$this->assertNull($projection->whole(row: ['lifecycleState' => 'paid', 'uuid' => '', '@self' => ['id' => ['nested']]]));
	}//end testDraftsStatelessAndIdlessRowsAreDropped()

	/**
	 * A kept invoice is reduced to its id, gross amount, currency, dates,
	 * lower-cased state and named lines.
	 *
	 * @return void
	 */
	public function testAKeptInvoiceIsReducedToItsOwnShape(): void {
		$whole = (new ShillinqInvoiceProjection())->whole(
			row: [
				'@self' => ['uuid' => 'i-7'],
				'lifecycleState' => 'Overdue',
				'grossAmount' => '121.50',
				'invoiceDate' => '2026-03-01T10:00:00+01:00',
				'dueDate' => '2026-03-31',
				'invoiceLines' => [['itemName' => ' Adviesuur ', 'netAmount' => 100, 'quantity' => 1, 'unitCode' => 'HUR'], ['itemName' => '']],
			]
		);

		$this->assertSame(
			[
				'id' => 'i-7',
				'amount' => 121.5,
				'currency' => 'EUR',
				'invoiceDate' => '2026-03-01',
				'dueDate' => '2026-03-31',
				'lifecycleState' => 'overdue',
				'lines' => [['itemName' => 'Adviesuur', 'netAmount' => 100.0, 'quantity' => 1.0, 'unitCode' => 'HUR']],
			],
			$whole
		);
	}//end testAKeptInvoiceIsReducedToItsOwnShape()

	/**
	 * The id is the row's own uuid, id or slug first, then the same keys under `@self`.
	 *
	 * @return void
	 */
	public function testTheIdPrefersTheRowOverItsSelfBlock(): void {
		$projection = new ShillinqInvoiceProjection();

		$this->assertSame('row-id', $projection->identify(row: ['id' => 'row-id', '@self' => ['uuid' => 'self-uuid']]));
		$this->assertSame('42', $projection->identify(row: ['id' => 42]));
		$this->assertSame('self-slug', $projection->identify(row: ['uuid' => '', '@self' => ['slug' => 'self-slug']]));
		$this->assertSame('', $projection->identify(row: []));
	}//end testTheIdPrefersTheRowOverItsSelfBlock()
}//end class
