<?php

/**
 * Tests for IncomingCallScope.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Cti
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Cti;

use OCA\Pipelinq\Service\Cti\IncomingCallScope;
use PHPUnit\Framework\TestCase;

/**
 * IncomingCallScope carries the agent and the time unchanged.
 */
final class IncomingCallScopeTest extends TestCase {
	/**
	 * Every value reads back as given.
	 *
	 * @return void
	 */
	public function testCarriesValues(): void {
		$scope = new IncomingCallScope(userId: 'agent-1', now: 1700000000);

		$this->assertSame(['agent-1', 1700000000], [$scope->userId, $scope->now]);
	}//end testCarriesValues()
}//end class
