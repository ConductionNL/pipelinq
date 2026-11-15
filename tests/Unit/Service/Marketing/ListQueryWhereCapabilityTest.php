<?php

/**
 * Unit tests for ListQueryWhereCapability: OpenRegister's `listQuery.where`,
 * read once and cached, and false unless it is explicitly true.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Marketing
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Marketing;

use OCA\Pipelinq\Service\Marketing\ListQueryWhereCapability;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Pipelinq\Service\Marketing\ListQueryWhereCapability
 */
class ListQueryWhereCapabilityTest extends TestCase {

	/**
	 * The shared cache's contents.
	 *
	 * @var array<string, mixed>
	 */
	private array $cached = [];

	/**
	 * How often OpenRegister's capabilities were read.
	 *
	 * @var integer
	 */
	private int $reads = 0;

	/**
	 * The capability over a container answering the given capabilities, or throwing.
	 *
	 * @param mixed $capabilities What publicCapabilities() returns; a Throwable is thrown by the container.
	 *
	 * @return ListQueryWhereCapability
	 */
	private function capability(mixed $capabilities): ListQueryWhereCapability {
		$service = new class ($this, $capabilities) {
			public function __construct(private ListQueryWhereCapabilityTest $test, private mixed $capabilities) {
			}

			public function publicCapabilities(): mixed {
				$this->test->read();
				return $this->capabilities;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		if ($capabilities instanceof \Throwable) {
			$container->method('get')->willThrowException($capabilities);
		} else {
			$container->method('get')->with('OCA\\OpenRegister\\Service\\ApiVersion\\ApiCapabilitiesServiceInterface')->willReturn($service);
		}

		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(fn (string $key): mixed => ($this->cached[$key] ?? null));
		$cache->method('set')->willReturnCallback(
			function (string $key, mixed $value, int $ttl): bool {
				$this->cached[$key] = [$value, $ttl];
				return true;
			}
		);
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createLocal')->with('pipelinq_segments')->willReturn($cache);

		return new ListQueryWhereCapability($container, $factory, $this->createMock(LoggerInterface::class));
	}//end capability()

	/**
	 * Count one read of OpenRegister's capabilities.
	 *
	 * @return void
	 */
	public function read(): void {
		$this->reads++;
	}//end read()

	/**
	 * An explicit true is read once and kept for an hour.
	 *
	 * @return void
	 */
	public function testAnExplicitTrueIsReadOnceAndCached(): void {
		$capability = $this->capability(['listQuery' => ['where' => true]]);

		$this->assertTrue($capability->isAvailable());
		$this->assertTrue($capability->isAvailable());
		$this->assertSame(1, $this->reads);
		$this->assertSame(['listQueryWhere' => [true, 3600]], $this->cached);
	}//end testAnExplicitTrueIsReadOnceAndCached()

	/**
	 * A cached answer is used without asking OpenRegister.
	 *
	 * @return void
	 */
	public function testACachedAnswerIsUsed(): void {
		$this->cached = ['listQueryWhere' => false];

		$this->assertFalse($this->capability(['listQuery' => ['where' => true]])->isAvailable());
		$this->assertSame(0, $this->reads);
	}//end testACachedAnswerIsUsed()

	/**
	 * Anything but an explicit true, and an OpenRegister without the service, is false.
	 *
	 * @return void
	 */
	public function testAnythingButAnExplicitTrueIsFalse(): void {
		foreach ([['listQuery' => ['where' => 'yes']], ['listQuery' => []], ['limits' => []], 'not a map', new RuntimeException('no such service')] as $answer) {
			$this->cached = [];
			$this->assertFalse($this->capability($answer)->isAvailable());
		}
	}//end testAnythingButAnExplicitTrueIsFalse()
}//end class
