<?php

/**
 * Behavioural tests for the ConfiguredObjectFind trait.
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

use OCA\Pipelinq\Service\Support\ConfiguredObjectFind;
use PHPUnit\Framework\TestCase;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;

/**
 * A configured find answers the row; gaps, misses and failures are null.
 *
 * @covers \OCA\Pipelinq\Service\Support\ConfiguredObjectFind
 */
class ConfiguredObjectFindTest extends TestCase {

	/**
	 * @return void
	 */
	public function testFindConfigured(): void {
		$host = new class {
			use ConfiguredObjectFind;

			/**
			 * @param array{0: string, 1: string} $ids The ids.
			 * @param string                      $id  The id.
			 * @return array<string, mixed>|null
			 */
			public function find(array $ids, string $id): ?array {
				return $this->findConfigured(ids: $ids, id: $id);
			}

			/**
			 * @return object
			 */
			private function getObjectService(): object {
				return new class {
					/**
					 * @param string $id       Id.
					 * @param string $register Register.
					 * @param string $schema   Schema.
					 * @return object|null
					 */
					public function find(string $id, ?ObjectFindOptions $options = null, bool $_rbac = true, bool $_multitenancy = true): ?object {
						$register = $options?->register;
						$schema = $options?->schema;
						if ($id === 'boom') {
							throw new \RuntimeException('down');
						}

						return ($id === 'known') ? (object)['id' => $id, 'at' => $register.'/'.$schema] : null;
					}
				};
			}

			/**
			 * @param mixed $value The row.
			 * @return array<string, mixed>
			 */
			private function toArray(mixed $value): array {
				return (array)$value;
			}
		};

		$this->assertSame(['id' => 'known', 'at' => 'reg/sch'], $host->find(['reg', 'sch'], 'known'));
		$this->assertNull($host->find(['reg', 'sch'], 'missing'));
		$this->assertNull($host->find(['reg', 'sch'], 'boom'));
		$this->assertNull($host->find(['reg', 'sch'], ''));
		$this->assertNull($host->find(['', 'sch'], 'known'));
	}//end testFindConfigured()
}//end class
