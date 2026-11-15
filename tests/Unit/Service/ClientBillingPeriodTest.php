<?php

/**
 * Unit tests for ClientBillingPeriod.
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

use OCA\Pipelinq\Service\ClientBillingPeriod;
use PHPUnit\Framework\TestCase;

/**
 * ClientBillingPeriod holds a client and an inclusive date range.
 */
class ClientBillingPeriodTest extends TestCase {

	/**
	 * The values are kept as given.
	 *
	 * @return void
	 */
	public function testKeepsValues(): void {
		$period = new ClientBillingPeriod(clientId: 'c-1', periodStart: '2026-01-01', periodEnd: '2026-01-31');

		$this->assertSame('c-1', $period->clientId);
		$this->assertSame('2026-01-01', $period->periodStart);
		$this->assertSame('2026-01-31', $period->periodEnd);
	}//end testKeepsValues()

	/**
	 * Both bounds are inclusive; dates outside and empty dates are not contained.
	 *
	 * @return void
	 */
	public function testContainsIsInclusiveAndRejectsEmpty(): void {
		$period = new ClientBillingPeriod(clientId: 'c-1', periodStart: '2026-01-01', periodEnd: '2026-01-31');

		$this->assertTrue($period->contains(date: '2026-01-01'));
		$this->assertTrue($period->contains(date: '2026-01-15'));
		$this->assertTrue($period->contains(date: '2026-01-31'));
		$this->assertFalse($period->contains(date: '2025-12-31'));
		$this->assertFalse($period->contains(date: '2026-02-01'));
		$this->assertFalse($period->contains(date: ''));
	}//end testContainsIsInclusiveAndRejectsEmpty()
}//end class
