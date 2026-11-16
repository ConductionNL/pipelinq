<?php

/**
 * Unit tests for SegmentProperty.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Marketing
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

namespace OCA\Pipelinq\Tests\Unit\Service\Marketing;

use OCA\Pipelinq\Service\Marketing\SegmentProperty;
use PHPUnit\Framework\TestCase;

/**
 * SegmentProperty keeps the field and whether it is numeric.
 */
class SegmentPropertyTest extends TestCase {

	/**
	 * The values are kept as given.
	 *
	 * @return void
	 */
	public function testKeepsValues(): void {
		$property = new SegmentProperty(field: 'revenue', numeric: true);

		$this->assertSame('revenue', $property->field);
		$this->assertTrue($property->numeric);
		$this->assertFalse((new SegmentProperty(field: 'city', numeric: false))->numeric);
	}//end testKeepsValues()
}//end class
