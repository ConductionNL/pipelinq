<?php

/**
 * ShillinqInvoiceProjection::lines: every invoice line shape.
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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Marketing;

use OCA\Pipelinq\Service\Marketing\ShillinqInvoiceProjection;
use PHPUnit\Framework\TestCase;

/**
 * Only named lines are kept, with their amounts as numbers.
 */
class ShillinqInvoiceProjectionTest extends TestCase {

	/**
	 * Every line shape: a value that is not a list has no lines; lines that
	 * are not lists or have no name are dropped; names are trimmed; amounts
	 * and quantities are numbers, missing ones zero; the unit is text.
	 *
	 * @return void
	 */
	public function testEveryLineShape(): void {
		$projection = new ShillinqInvoiceProjection();
		$this->assertSame([], $projection->lines(value: 'none'));
		$this->assertSame(
			[
				['itemName' => 'Kassa', 'netAmount' => 99.5, 'quantity' => 2.0, 'unitCode' => 'C62'],
				['itemName' => 'Installatie', 'netAmount' => 0.0, 'quantity' => 0.0, 'unitCode' => ''],
			],
			$projection->lines(value: ['junk', ['itemName' => ' '], ['itemName' => ' Kassa ', 'netAmount' => '99.5', 'quantity' => 2, 'unitCode' => 'C62'], ['itemName' => 'Installatie']])
		);
	}//end testEveryLineShape()

	/**
	 * The head carries the id, the gross amount as a number, EUR when no
	 * currency is set and the date part of the invoice date.
	 *
	 * @return void
	 */
	public function testTheHead(): void {
		$projection = new ShillinqInvoiceProjection();
		$this->assertSame(
			['id' => 'i1', 'amount' => 12.5, 'currency' => 'EUR', 'invoiceDate' => '2026-03-01'],
			$projection->head(row: ['grossAmount' => '12.5', 'invoiceDate' => '2026-03-01T10:00:00Z'], id: 'i1')
		);
		$this->assertSame(
			['id' => 'i2', 'amount' => 0.0, 'currency' => 'USD', 'invoiceDate' => ''],
			$projection->head(row: ['currency' => 'USD'], id: 'i2')
		);
	}//end testTheHead()
}//end class
