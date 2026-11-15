<?php

/**
 * Unit tests for AvailabilityCacheScope.
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
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\AvailabilityCacheScope;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AvailabilityCacheScope.
 */
class AvailabilityCacheScopeTest extends TestCase {

	/**
	 * The scope keeps the register, schema and resource it was given.
	 *
	 * @return void
	 */
	public function testKeepsItsParts(): void {
		$scope = new AvailabilityCacheScope(register: 'r', schema: 's', resourceId: 'res-1');

		$this->assertSame(expected: 'r', actual: $scope->register);
		$this->assertSame(expected: 's', actual: $scope->schema);
		$this->assertSame(expected: 'res-1', actual: $scope->resourceId);
	}//end testKeepsItsParts()
}//end class
