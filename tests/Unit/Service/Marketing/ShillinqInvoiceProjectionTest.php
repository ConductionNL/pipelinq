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
}//end class
