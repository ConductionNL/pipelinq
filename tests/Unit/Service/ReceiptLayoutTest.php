<?php

/**
 * Unit tests for ReceiptLayout.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\ReceiptLayout;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ReceiptLayout.
 */
class ReceiptLayoutTest extends TestCase {

	/**
	 * A row fills the width, the value right-aligned.
	 *
	 * @return void
	 */
	public function testRowFillsTheWidth(): void {
		$layout = new ReceiptLayout(width: 12, invoice: false);

		$this->assertSame(expected: 'Total  €1,00', actual: $layout->row(left: 'Total', right: '€1,00'));
		$this->assertFalse(condition: $layout->invoice);
	}//end testRowFillsTheWidth()

	/**
	 * A row too long for the width keeps one space between label and value.
	 *
	 * @return void
	 */
	public function testOverlongRowKeepsOneSpace(): void {
		$layout = new ReceiptLayout(width: 4, invoice: true);

		$this->assertSame(expected: 'Subtotal €10,00', actual: $layout->row(left: 'Subtotal', right: '€10,00'));
	}//end testOverlongRowKeepsOneSpace()
}//end class
