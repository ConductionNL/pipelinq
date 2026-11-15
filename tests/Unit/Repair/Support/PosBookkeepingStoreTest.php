<?php

/**
 * Unit tests for PosBookkeepingStore.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Repair\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Repair\Support;

use OCA\Pipelinq\Repair\Support\PosBookkeepingStore;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PosBookkeepingStore.
 */
class PosBookkeepingStoreTest extends TestCase {

	/**
	 * A new store has no Z-reports; adding them keeps the service and register.
	 *
	 * @return void
	 */
	public function testWithZReportsKeepsTheServiceAndRegister(): void {
		$service = new \stdClass();
		$store = new PosBookkeepingStore(objectService: $service, register: 'pipelinq');

		$withReports = $store->withZReports(zReportsById: ['z-1' => ['id' => 'z-1']]);

		$this->assertSame(expected: [], actual: $store->zReportsById);
		$this->assertSame(expected: ['z-1' => ['id' => 'z-1']], actual: $withReports->zReportsById);
		$this->assertSame(expected: $service, actual: $withReports->objectService);
		$this->assertSame(expected: 'pipelinq', actual: $withReports->register);
	}//end testWithZReportsKeepsTheServiceAndRegister()
}//end class
