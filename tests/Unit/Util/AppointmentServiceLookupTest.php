<?php

/**
 * Behavioural tests for the AppointmentServiceLookup trait.
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

use OCA\Pipelinq\Util\AppointmentServiceLookup;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;

/**
 * A Service is read from the configured schema; gaps are null, failures log.
 *
 * @covers \OCA\Pipelinq\Util\AppointmentServiceLookup
 */
class AppointmentServiceLookupTest extends TestCase {

	/**
	 * Build a host over the given register id and logger.
	 *
	 * @param string          $register The configured register id.
	 * @param LoggerInterface $logger   The logger.
	 *
	 * @return object The host.
	 */
	private function host(string $register, LoggerInterface $logger): object {
		return new class($register, $logger) {
			use AppointmentServiceLookup;

			/**
			 * @param string          $register The register id.
			 * @param LoggerInterface $logger   The logger.
			 */
			public function __construct(private string $register, private LoggerInterface $logger) {
			}

			/**
			 * @param string $id The service id.
			 * @return array<string, mixed>|null
			 */
			public function load(string $id): ?array {
				return $this->findServiceObject(serviceId: $id, failure: 'load failed');
			}

			/**
			 * @return string
			 */
			private function registerId(): string {
				return $this->register;
			}

			/**
			 * @param string $key The key.
			 * @return string
			 */
			private function schemaId(string $key): string {
				return $key.'-id';
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
					 * @return array<string, string>
					 */
					public function find(string $id, ?ObjectFindOptions $options = null, bool $_rbac = true, bool $_multitenancy = true): array {
						$register = $options?->register;
						$schema = $options?->schema;
						if ($id === 'broken') {
							throw new \RuntimeException('down');
						}

						return ['id' => $id, 'at' => $register.'/'.$schema];
					}
				};
			}

			/**
			 * @param mixed $object The object.
			 * @return array<string, mixed>|null
			 */
			private function toArray(mixed $object): ?array {
				return $object;
			}
		};
	}//end host()

	/**
	 * @return void
	 */
	public function testLookup(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('load failed', ['service' => 'broken']);
		$host = $this->host('reg', $logger);

		$this->assertSame(['id' => 's1', 'at' => 'reg/service_schema-id'], $host->load('s1'));
		$this->assertNull($host->load(''));
		$this->assertNull($host->load('broken'));
		$this->assertNull($this->host('', $logger)->load('s1'));
	}//end testLookup()
}//end class
