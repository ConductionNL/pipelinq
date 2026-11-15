<?php

/**
 * Behavioural tests for the ConfiguredObjectSave trait.
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

use OCA\Pipelinq\Util\ConfiguredObjectSave;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A configured target saves and answers the row; a gap refuses by name.
 *
 * @covers \OCA\Pipelinq\Util\ConfiguredObjectSave
 */
class ConfiguredObjectSaveTest extends TestCase {

	/**
	 * @return void
	 */
	public function testSaveConfigured(): void {
		$host = new class {
			use ConfiguredObjectSave;

			/** @var array<int, mixed> */
			public array $saved = [];

			/**
			 * @param array{0: string, 1: string} $ids     The ids.
			 * @param array<string, mixed>        $payload The payload.
			 * @param string|null                 $uuid    The id.
			 * @return array<string, mixed>
			 */
			public function save(array $ids, array $payload, ?string $uuid): array {
				return $this->saveConfigured(ids: $ids, payload: $payload, uuid: $uuid, label: 'Thing');
			}

			/**
			 * @return object
			 */
			private function getObjectService(): object {
				return new class($this) {
					/**
					 * @param object $host The host.
					 */
					public function __construct(private object $host) {
					}

					/**
					 * @param array<string, mixed> $object   Object.
					 * @param array<int, string>   $extend   Extend.
					 * @param string               $register Register.
					 * @param string               $schema   Schema.
					 * @param string|null          $uuid     Uuid.
					 * @return array<string, mixed>
					 */
					public function saveObject(array $object, array $extend, string $register, string $schema, ?string $uuid): array {
						$this->host->saved = func_get_args();
						return $object + ['id' => ($uuid ?? 'new')];
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

		$this->assertSame(['a' => 1, 'id' => 'new'], $host->save(['reg', 'sch'], ['a' => 1], null));
		$this->assertSame([['a' => 1], [], 'reg', 'sch', null], $host->saved);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Thing schema is not configured.');
		$host->save(['reg', ''], ['a' => 2], 'u2');
	}//end testSaveConfigured()
}//end class
