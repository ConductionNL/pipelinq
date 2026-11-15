<?php

/**
 * Unit tests for PartyRepairTarget.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Repair
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

namespace OCA\Pipelinq\Tests\Unit\Repair;

use OCA\Pipelinq\Repair\PartyRepairTarget;
use PHPUnit\Framework\TestCase;

/**
 * PartyRepairTarget carries its values unchanged.
 */
class PartyRepairTargetTest extends TestCase {

	/**
	 * Every constructor value is readable as given.
	 *
	 * @return void
	 */
	public function testKeepsTheValuesItIsGiven(): void {
		$objectService = new \stdClass();
		$register = '5';
		$schema = '12';
		$actingAdmin = null;
		$value = new PartyRepairTarget(objectService: $objectService, register: $register, schema: $schema, actingAdmin: $actingAdmin);

		self::assertSame($objectService, $value->objectService);
		self::assertSame($register, $value->register);
		self::assertSame($schema, $value->schema);
		self::assertSame($actingAdmin, $value->actingAdmin);
	}//end testKeepsTheValuesItIsGiven()
}//end class
