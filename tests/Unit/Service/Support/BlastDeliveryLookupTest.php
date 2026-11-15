<?php

/**
 * Behavioural tests for the BlastDeliveryLookup trait.
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

use OCA\Pipelinq\Service\Support\BlastDeliveryLookup;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A delivery is read from the configured schema; gaps and misses are null.
 *
 * @covers \OCA\Pipelinq\Service\Support\BlastDeliveryLookup
 */
class BlastDeliveryLookupTest extends TestCase {

	/**
	 * Build a host.
	 *
	 * @param string          $schema The delivery schema slug.
	 * @param bool            $hasOs  Whether an ObjectService is available.
	 * @param LoggerInterface $logger The logger.
	 *
	 * @return object The host.
	 */
	private function host(string $schema, bool $hasOs, LoggerInterface $logger): object {
		return new class($schema, $hasOs, $logger) {
			use BlastDeliveryLookup;

			/**
			 * @param string          $schema The schema slug.
			 * @param bool            $hasOs  Whether a service exists.
			 * @param LoggerInterface $logger The logger.
			 */
			public function __construct(private string $schema, private bool $hasOs, private LoggerInterface $logger) {
			}

			/**
			 * @param string $id The id.
			 * @return array<string, mixed>|null
			 */
			public function load(string $id): ?array {
				return $this->findBlastDelivery(id: $id, notFound: 'not found');
			}

			/**
			 * @return string
			 */
			private function getRegisterSlug(): string {
				return 'reg';
			}

			/**
			 * @return string
			 */
			private function getBlastDeliverySchemaSlug(): string {
				return $this->schema;
			}

			/**
			 * @return object|null
			 */
			private function getObjectService(): ?object {
				if ($this->hasOs === false) {
					return null;
				}

				return new class {
					/**
					 * @param string $id       Id.
					 * @param string $register Register.
					 * @param string $schema   Schema.
					 * @return array<string, string>|null
					 */
					public function find(string $id, string $register, string $schema): ?array {
						if ($id === 'boom') {
							throw new \RuntimeException('down');
						}

						return ($id === 'known') ? ['id' => $id, 'at' => $register.'/'.$schema] : null;
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
	}//end host()

	/**
	 * @return void
	 */
	public function testLookup(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with('not found', ['id' => 'boom', 'exception' => 'down']);
		$host = $this->host('blastDelivery', true, $logger);

		$this->assertSame(['id' => 'known', 'at' => 'reg/blastDelivery'], $host->load('known'));
		$this->assertNull($host->load('missing'));
		$this->assertNull($host->load('boom'));
		$this->assertNull($this->host('', true, $logger)->load('known'));
		$this->assertNull($this->host('blastDelivery', false, $logger)->load('known'));
	}//end testLookup()
}//end class
