<?php

/**
 * Unit tests for QuoteLine.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\QuoteLine;
use PHPUnit\Framework\TestCase;

/**
 * QuoteLine carries its values unchanged.
 */
class QuoteLineTest extends TestCase {

	/**
	 * Every constructor value is readable as given.
	 *
	 * @return void
	 */
	public function testKeepsTheValuesItIsGiven(): void {
		$product = ['id' => 'p-1', 'unitPrice' => 10.0];
		$quantity = 3.0;
		$variantSku = 'SKU-1';
		$currency = 'EUR';
		$value = new QuoteLine(product: $product, quantity: $quantity, variantSku: $variantSku, currency: $currency);

		self::assertSame($product, $value->product);
		self::assertSame($quantity, $value->quantity);
		self::assertSame($variantSku, $value->variantSku);
		self::assertSame($currency, $value->currency);
	}//end testKeepsTheValuesItIsGiven()
}//end class
