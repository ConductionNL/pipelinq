<?php

/**
 * Behavioural tests for the ObjectServiceTarget trait.
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

namespace OCA\Pipelinq\Tests\Unit\Util;

use OCA\Pipelinq\Util\ObjectServiceTarget;
use PHPUnit\Framework\TestCase;

/**
 * Both slugs and a service make a target; any gap is null.
 *
 * @covers \OCA\Pipelinq\Util\ObjectServiceTarget
 */
class ObjectServiceTargetTest extends TestCase {

	/**
	 * @return void
	 */
	public function testTarget(): void {
		$service = new \stdClass();
		foreach ([[$service, 'r', 's', ['r', 's', $service]], [$service, '', 's', null], [$service, 'r', '', null], [null, 'r', 's', null]] as [$os, $register, $schema, $expected]) {
			$host = new class($os) {
				use ObjectServiceTarget;

				/**
				 * @param object|null $os The service.
				 */
				public function __construct(private ?object $os) {
				}

				/**
				 * @param string $register The register.
				 * @param string $schema   The schema.
				 * @return array<int, mixed>|null
				 */
				public function target(string $register, string $schema): ?array {
					return $this->objectTarget(register: $register, schema: $schema);
				}

				/**
				 * @return object|null
				 */
				private function getObjectService(): ?object {
					return $this->os;
				}
			};

			$this->assertSame($expected, $host->target($register, $schema));
		}
	}//end testTarget()
}//end class
