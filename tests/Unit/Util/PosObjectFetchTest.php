<?php

/**
 * Behavioural tests for the PosObjectFetch trait.
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

use OCA\Pipelinq\Util\PosObjectFetch;
use OCP\AppFramework\OCS\OCSNotFoundException;
use PHPUnit\Framework\TestCase;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;

/**
 * A found object is converted; a missing or unreadable one is a not-found.
 *
 * @covers \OCA\Pipelinq\Util\PosObjectFetch
 */
class PosObjectFetchTest extends TestCase {

	/**
	 * @return void
	 */
	public function testFetchOne(): void {
		$host = new class {
			use PosObjectFetch;

			/**
			 * @param string $id The id.
			 * @return array<string, mixed>
			 */
			public function fetch(string $id): array {
				return $this->fetchOne(schemaKey: 'thing_schema', id: $id, label: 'Niet gevonden.');
			}

			/**
			 * @param string $schemaKey The key.
			 * @return array{0: string, 1: string}
			 */
			private function config(string $schemaKey): array {
				return ['reg', $schemaKey];
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
			 * @param mixed $object The object.
			 * @return array<string, mixed>
			 */
			private function toArray(mixed $object): array {
				return (array)$object;
			}
		};

		$this->assertSame(['id' => 'known', 'at' => 'reg/thing_schema'], $host->fetch('known'));
		foreach (['missing', 'boom'] as $id) {
			try {
				$host->fetch($id);
				$this->fail('expected a not-found error');
			} catch (OCSNotFoundException $e) {
				$this->assertSame('Niet gevonden.', $e->getMessage());
			}
		}
	}//end testFetchOne()
}//end class
