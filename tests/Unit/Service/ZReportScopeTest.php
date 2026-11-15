<?php

/**
 * Unit tests for ZReportScope.
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

use OCA\Pipelinq\Service\ZReportScope;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ZReportScope.
 */
class ZReportScopeTest extends TestCase {

	/**
	 * A named terminal limits the report; null or empty does not.
	 *
	 * @return void
	 */
	public function testHasTerminalOnlyForANonEmptyTerminal(): void {
		$scope = new ZReportScope(reportDate: '2026-11-15', terminalId: 'kassa-01');

		$this->assertTrue(condition: $scope->hasTerminal());
		$this->assertSame(expected: '2026-11-15', actual: $scope->reportDate);
		$this->assertSame(expected: 'kassa-01', actual: $scope->terminalId);
		$this->assertFalse(condition: (new ZReportScope(reportDate: '2026-11-15', terminalId: null))->hasTerminal());
		$this->assertFalse(condition: (new ZReportScope(reportDate: '2026-11-15', terminalId: ''))->hasTerminal());
	}//end testHasTerminalOnlyForANonEmptyTerminal()
}//end class
