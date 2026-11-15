<?php

/**
 * Behavioural tests for the PartyObjectRead trait.
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

use OCA\Pipelinq\Util\PartyObjectRead;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Found entities serialise; misses, odd shapes and failures are null.
 *
 * @covers \OCA\Pipelinq\Util\PartyObjectRead
 * @uses   \OCA\Pipelinq\Util\SerialisedEntity
 */
class PartyObjectReadTest extends TestCase {

	/**
	 * @return void
	 */
	public function testRead(): void {
		$store = new class {
			/**
			 * @param string $id The id.
			 * @return \JsonSerializable|null
			 */
			public function find(string $id): ?\JsonSerializable {
				if ($id === 'boom') {
					throw new \RuntimeException('down');
				}

				if ($id === 'missing') {
					return null;
				}

				return new class($id) implements \JsonSerializable {
					/**
					 * @param string $id The id.
					 */
					public function __construct(private string $id) {
					}

					/**
					 * @return mixed
					 */
					public function jsonSerialize(): mixed {
						return ($this->id === 'scalar') ? 'x' : ['id' => $this->id];
					}
				};
			}
		};
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('debug')->with('read failed', ['party' => 'boom', 'exception' => 'down']);
		$host = new class($store, $logger) {
			use PartyObjectRead;

			/**
			 * @param object          $objectService The store.
			 * @param LoggerInterface $logger        The logger.
			 */
			public function __construct(private object $objectService, private LoggerInterface $logger) {
			}

			/**
			 * @param string $id The id.
			 * @return array<string, mixed>|null
			 */
			public function read(string $id): ?array {
				return $this->readLogged(id: $id, failure: 'read failed', idKey: 'party');
			}
		};

		$this->assertSame(['id' => 'p1'], $host->read('p1'));
		$this->assertNull($host->read('missing'));
		$this->assertNull($host->read('scalar'));
		$this->assertNull($host->read('boom'));
	}//end testRead()
}//end class
