<?php

/**
 * Unit tests for BrpRetentionSweep.
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

use DateTimeImmutable;
use OCA\Pipelinq\BackgroundJob\BrpRetentionSweep;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * A retention sweep carries its service, register, schemas and time unchanged.
 */
class BrpRetentionSweepTest extends TestCase {

	/**
	 * Every value given is the value read back.
	 *
	 * @return void
	 */
	public function testCarriesItsValues(): void {
		$objects = new stdClass();
		$now = new DateTimeImmutable('2026-01-01T00:00:00Z');
		$sweep = new BrpRetentionSweep(objects: $objects, register: 'reg', personSchema: 'person', contactSchema: 'contact', now: $now);

		self::assertSame($objects, $sweep->objects);
		self::assertSame('reg', $sweep->register);
		self::assertSame('person', $sweep->personSchema);
		self::assertSame('contact', $sweep->contactSchema);
		self::assertSame($now, $sweep->now);
	}//end testCarriesItsValues()
}//end class
