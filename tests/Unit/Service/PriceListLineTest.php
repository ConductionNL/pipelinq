<?php

/**
 * Unit tests for PriceListLine.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Pipelinq\Service\PriceListLine;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PriceListLine.
 */
class PriceListLineTest extends TestCase {

	/**
	 * Another SKU keeps every other field of the line.
	 *
	 * @return void
	 */
	public function testWithSkuKeepsTheRestOfTheLine(): void {
		$day = new DateTimeImmutable('2026-11-15');
		$line = new PriceListLine(productId: 'p-1', sku: 'RED', quantity: 3.0, currency: 'EUR', day: $day);

		$other = $line->withSku(sku: '');

		$this->assertSame(expected: '', actual: $other->sku);
		$this->assertSame(expected: 'RED', actual: $line->sku);
		$this->assertSame(expected: 'p-1', actual: $other->productId);
		$this->assertSame(expected: 3.0, actual: $other->quantity);
		$this->assertSame(expected: 'EUR', actual: $other->currency);
		$this->assertSame(expected: $day, actual: $other->day);
	}//end testWithSkuKeepsTheRestOfTheLine()
}//end class
