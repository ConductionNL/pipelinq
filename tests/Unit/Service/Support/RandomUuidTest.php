<?php

/**
 * Behavioural tests for the RandomUuid trait.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Util
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

namespace OCA\Pipelinq\Tests\Unit\Service\Support;

use OCA\Pipelinq\Service\Support\RandomUuid;
use PHPUnit\Framework\TestCase;

/**
 * Each call yields a fresh, well-formed version 4 UUID.
 *
 * @covers \OCA\Pipelinq\Service\Support\RandomUuid
 */
class RandomUuidTest extends TestCase {

	/**
	 * @return void
	 */
	public function testVersionFourShape(): void {
		$host = new class {
			use RandomUuid;

			/**
			 * @return string
			 */
			public function next(): string {
				return $this->uuid();
			}
		};

		$first = $host->next();
		$this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $first);
		$this->assertNotSame($first, $host->next());
	}//end testVersionFourShape()
}//end class
