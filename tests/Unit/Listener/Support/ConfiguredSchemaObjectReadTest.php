<?php

/**
 * Behavioural tests for the ConfiguredSchemaObjectRead trait.
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

namespace OCA\Pipelinq\Tests\Unit\Listener\Support;

use OCA\Pipelinq\Listener\Support\ConfiguredSchemaObjectRead;
use OCA\Pipelinq\Listener\Support\ConfiguredSchemaObjectWrite;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;

/**
 * Reads and saves against the configured register and the host's schema key.
 *
 * @covers \OCA\Pipelinq\Listener\Support\ConfiguredSchemaObjectRead
 * @uses   \OCA\Pipelinq\Util\ContainerObjectFind
 */
class ConfiguredSchemaObjectReadTest extends TestCase {

	/**
	 * Build a host over a fake object service.
	 *
	 * @param array<string, string> $values Config values.
	 * @param object                $store  The object service.
	 * @param LoggerInterface       $logger The logger.
	 * @param TestCase              $test   The test (for mocks).
	 *
	 * @return ConfiguredSchemaObjectHost The host.
	 */
	public static function host(array $values, object $store, LoggerInterface $logger, TestCase $test): ConfiguredSchemaObjectHost {
		$appConfig = $test->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default): string => ($values[$key] ?? $default)
		);
		$container = $test->createStub(ContainerInterface::class);
		$container->method('get')->willReturn($store);

		return new ConfiguredSchemaObjectHost($appConfig, $container, $logger);
	}//end host()

	/**
	 * A fake object service that records its calls.
	 *
	 * @param bool $fail Whether every call throws.
	 *
	 * @return object The fake.
	 */
	public static function store(bool $fail = false): object {
		return new class($fail) {
			/** @var array<int, array<int, mixed>> */
			public array $calls = [];

			/**
			 * @param bool $fail Whether to throw.
			 */
			public function __construct(private bool $fail) {
			}

			/**
			 * @param string $id       Id.
			 * @param string $register Register.
			 * @param string $schema   Schema.
			 * @return object|null
			 */
			public function find(string $id, ?ObjectFindOptions $options = null, bool $_rbac = true, bool $_multitenancy = true): ?object {
				$register = $options?->register;
				$schema = $options?->schema;
				if ($this->fail === true) {
					throw new RuntimeException('down');
				}

				if ($id !== 'known') {
					return null;
				}

				return new class([$register, $schema]) {
					/**
					 * @param array<int, string> $at Where it was read.
					 */
					public function __construct(private array $at) {
					}

					/**
					 * @return array<string, mixed>
					 */
					public function getObject(): array {
						return ['at' => $this->at];
					}
				};
			}

			/**
			 * @param array<string, mixed> $object   Object.
			 * @param array<int, string>   $extend   Extend.
			 * @param string               $register Register.
			 * @param string               $schema   Schema.
			 * @param string               $uuid     Uuid.
			 * @return void
			 */
			public function saveObject(array $object, ObjectSaveOptions $options = new ObjectSaveOptions(), bool $_rbac = true, bool $_multitenancy = true): void {
				$extend = $options->extend;
				$register = $options->target->register;
				$schema = $options->target->schema;
				$uuid = $options->uuid;
				if ($this->fail === true) {
					throw new RuntimeException('down');
				}

				$this->calls[] = [$object, $extend, $register, $schema, $uuid];
			}
		};
	}//end store()

	/**
	 * @return void
	 */
	public function testFetch(): void {
		$config = ['register' => 'r', 'thing_schema' => 's'];
		$quiet = $this->createMock(LoggerInterface::class);
		$quiet->expects($this->never())->method('warning');

		$host = self::host($config, self::store(), $quiet, $this);
		$this->assertSame(['at' => ['r', 's']], $host->read('known'));
		$this->assertNull($host->read('missing'));
		$this->assertNull($host->read(''));
		$this->assertNull(self::host(['register' => 'r'], self::store(), $quiet, $this)->read('known'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with('read failed', ['exception' => 'down', 'uuid' => 'known']);
		$this->assertNull(self::host($config, self::store(fail: true), $logger, $this)->read('known'));
	}//end testFetch()
}//end class

/**
 * A host of both traits with fixed constants.
 */
final class ConfiguredSchemaObjectHost {
	use ConfiguredSchemaObjectRead;
	use ConfiguredSchemaObjectWrite;

	private const OBJECT_SCHEMA_KEY = 'thing_schema';

	private const FETCH_FAILURE_MESSAGE = 'read failed';

	private const PERSIST_FAILURE_MESSAGE = 'save failed';

	/**
	 * @param IAppConfig         $appConfig The app config.
	 * @param ContainerInterface $container The container.
	 * @param LoggerInterface    $logger    The logger.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param string $uuid The uuid.
	 * @return array<string, mixed>|null
	 */
	public function read(string $uuid): ?array {
		return $this->fetch(uuid: $uuid);
	}

	/**
	 * @param string               $uuid The uuid.
	 * @param array<string, mixed> $data The data.
	 * @return void
	 */
	public function write(string $uuid, array $data): void {
		$this->persist(uuid: $uuid, data: $data);
	}
}//end class
