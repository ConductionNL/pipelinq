<?php

/**
 * Unit tests for EventLogPurge.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Cti
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

namespace OCA\Pipelinq\Tests\Unit\Service\Cti;

use OCA\Pipelinq\Service\Cti\EventLogPurge;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * A purge pass carries its service, location and cutoff unchanged.
 */
class EventLogPurgeTest extends TestCase {

	/**
	 * Every value given is the value read back.
	 *
	 * @return void
	 */
	public function testCarriesItsValues(): void {
		$objectService = new stdClass();
		$purge = new EventLogPurge(objectService: $objectService, register: 'reg', schema: 'log', cutoff: '2026-01-01T00:00:00Z');

		self::assertSame($objectService, $purge->objectService);
		self::assertSame('reg', $purge->register);
		self::assertSame('log', $purge->schema);
		self::assertSame('2026-01-01T00:00:00Z', $purge->cutoff);
	}//end testCarriesItsValues()
}//end class
