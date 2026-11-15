<?php

/**
 * Behavioural tests for the NestedIdValue trait.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Portal\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Portal\Support;

use OCA\Pipelinq\Service\Portal\Support\NestedIdValue;
use PHPUnit\Framework\TestCase;

/**
 * String ids, {id|uuid} references and empty values.
 *
 * @covers \OCA\Pipelinq\Service\Portal\Support\NestedIdValue
 */
class NestedIdValueTest extends TestCase {

	/**
	 * @return void
	 */
	public function testReadId(): void {
		$host = new class {
			use NestedIdValue;

			/**
			 * @param mixed $value The raw value.
			 * @return string|null
			 */
			public function read(mixed $value): ?string {
				return $this->readId(value: $value);
			}
		};

		$this->assertNull($host->read(null));
		$this->assertNull($host->read(''));
		$this->assertSame('a', $host->read('a'));
		$this->assertSame('7', $host->read(7));
		$this->assertSame('i', $host->read(['id' => 'i', 'uuid' => 'u']));
		$this->assertSame('u', $host->read(['uuid' => 'u']));
		$this->assertNull($host->read(['name' => 'x']));
	}//end testReadId()
}//end class
