<?php

/**
 * Behavioural tests for the QuietObjectSave trait.
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

use OCA\Pipelinq\Service\Support\QuietObjectSave;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;

/**
 * Saves by id into the given schema; no service skips, a failure logs.
 *
 * @covers \OCA\Pipelinq\Service\Support\QuietObjectSave
 */
class QuietObjectSaveTest extends TestCase {

	/**
	 * Build a host over the given store and logger.
	 *
	 * @param object|null     $store  The object service.
	 * @param LoggerInterface $logger The logger.
	 *
	 * @return object The host.
	 */
	private function host(?object $store, LoggerInterface $logger): object {
		return new class($store, $logger) {
			use QuietObjectSave;

			/**
			 * @param object|null     $store  The object service.
			 * @param LoggerInterface $logger The logger.
			 */
			public function __construct(private ?object $store, private LoggerInterface $logger) {
			}

			/**
			 * @param array<string, mixed> $payload The row.
			 * @param string               $id      The id.
			 * @return void
			 */
			public function save(array $payload, string $id): void {
				$this->saveQuietly(payload: $payload, schema: 'sch', id: $id, failure: 'save failed');
			}

			/**
			 * @return object|null
			 */
			private function getObjectService(): ?object {
				return $this->store;
			}

			/**
			 * @return string
			 */
			private function getRegisterSlug(): string {
				return 'reg';
			}
		};
	}//end host()

	/**
	 * @return void
	 */
	public function testSaveQuietly(): void {
		$store = new class {
			/** @var array<int, array<int, mixed>> */
			public array $calls = [];

			/**
			 * @param array<string, mixed> $object   Object.
			 * @param string               $register Register.
			 * @param string               $schema   Schema.
			 * @param string               $uuid     Uuid.
			 * @return void
			 */
			public function saveObject(array $object, ObjectSaveOptions $options = new ObjectSaveOptions(), bool $_rbac = true, bool $_multitenancy = true): void {
				$register = $options->target->register;
				$schema = $options->target->schema;
				$uuid = $options->uuid;
				if ($uuid === 'locked') {
					throw new \RuntimeException('busy');
				}

				$this->calls[] = [$object, $register, $schema, $uuid];
			}
		};
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('save failed', ['id' => 'locked', 'exception' => 'busy']);

		$this->host($store, $logger)->save(['a' => 1], 'r1');
		$this->host($store, $logger)->save(['a' => 2], 'locked');
		$this->host(null, $logger)->save(['a' => 3], 'r3');
		$this->assertSame([[['a' => 1], 'reg', 'sch', 'r1']], $store->calls);
	}//end testSaveQuietly()
}//end class
