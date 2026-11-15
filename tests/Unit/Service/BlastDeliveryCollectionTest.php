<?php

/**
 * Unit tests for BlastDeliveryCollection.
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

use OCA\Pipelinq\Service\BlastDeliveryCollection;
use PHPUnit\Framework\TestCase;

/**
 * BlastDeliveryCollection carries its values unchanged.
 */
class BlastDeliveryCollectionTest extends TestCase {

	/**
	 * Every constructor value is readable as given.
	 *
	 * @return void
	 */
	public function testKeepsTheValuesItIsGiven(): void {
		$objectService = new \stdClass();
		$register = 'pipelinq';
		$schema = 'blastDelivery';
		$value = new BlastDeliveryCollection(objectService: $objectService, register: $register, schema: $schema);

		self::assertSame($objectService, $value->objectService);
		self::assertSame($register, $value->register);
		self::assertSame($schema, $value->schema);
	}//end testKeepsTheValuesItIsGiven()
}//end class
