<?php

/**
 * Tests for LabelRenameException.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Labels
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Labels;

use OCA\Pipelinq\Service\Labels\LabelRenameException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A refused rename carries its message and the HTTP status that answers it.
 *
 * @covers \OCA\Pipelinq\Service\Labels\LabelRenameException
 */
class LabelRenameExceptionTest extends TestCase {

	/**
	 * The message and status come back as given, for each status the service uses.
	 *
	 * @return void
	 */
	public function testItKeepsTheMessageAndTheStatus(): void {
		foreach ([400, 403, 404, 409, 503] as $status) {
			$e = new LabelRenameException(message: 'Refused '.$status, status: $status);

			$this->assertSame(expected: 'Refused '.$status, actual: $e->getMessage());
			$this->assertSame(expected: $status, actual: $e->getStatus());
		}
	}//end testItKeepsTheMessageAndTheStatus()

	/**
	 * It is a runtime exception whose code stays 0: the status is not the exception code.
	 *
	 * @return void
	 */
	public function testTheStatusIsNotTheExceptionCode(): void {
		$e = new LabelRenameException(message: 'Another label already has this name.', status: 409);

		$this->assertInstanceOf(expected: RuntimeException::class, actual: $e);
		$this->assertSame(expected: 0, actual: $e->getCode());
		$this->assertNull(actual: $e->getPrevious());
	}//end testTheStatusIsNotTheExceptionCode()
}//end class
