<?php

/**
 * Unit tests for RegisterSchema.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\RegisterSchema;
use PHPUnit\Framework\TestCase;

/**
 * Tests for RegisterSchema.
 */
class RegisterSchemaTest extends TestCase {

	/**
	 * The pair keeps the register and schema it was given.
	 *
	 * @return void
	 */
	public function testKeepsRegisterAndSchema(): void {
		$location = new RegisterSchema(register: 'r-1', schema: 's-1');

		$this->assertSame(expected: 'r-1', actual: $location->register);
		$this->assertSame(expected: 's-1', actual: $location->schema);
	}//end testKeepsRegisterAndSchema()
}//end class
