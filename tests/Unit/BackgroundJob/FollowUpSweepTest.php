<?php

/**
 * Unit tests for FollowUpSweep.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\BackgroundJob
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

namespace OCA\Pipelinq\Tests\Unit\BackgroundJob;

use OCA\Pipelinq\BackgroundJob\FollowUpSweep;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * A sweep carries its object service, register and window unchanged.
 */
class FollowUpSweepTest extends TestCase {

	/**
	 * Every value given is the value read back.
	 *
	 * @return void
	 */
	public function testCarriesItsValues(): void {
		$objectService = new stdClass();
		$sweep = new FollowUpSweep(objectService: $objectService, register: 'reg-1', now: 1000, windowEnd: 4600);

		self::assertSame($objectService, $sweep->objectService);
		self::assertSame('reg-1', $sweep->register);
		self::assertSame(1000, $sweep->now);
		self::assertSame(4600, $sweep->windowEnd);
	}//end testCarriesItsValues()
}//end class
