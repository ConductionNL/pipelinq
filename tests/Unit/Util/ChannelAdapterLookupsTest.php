<?php

/**
 * Behavioural tests for the ChannelAdapterLookups trait.
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

use OCA\Pipelinq\Util\ChannelAdapterLookups;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Contact-moment, phone, tenant and slug reads, and the message save.
 *
 * @covers \OCA\Pipelinq\Util\ChannelAdapterLookups
 * @uses \OCA\Pipelinq\Util\ConfiguredSchemaSlug
 */
class ChannelAdapterLookupsTest extends TestCase {

	/**
	 * Build a host over the given config values.
	 *
	 * @param array<string, string> $values Config values by key.
	 * @param object|null           $store  The object service.
	 * @param LoggerInterface|null  $logger The logger.
	 *
	 * @return ChannelAdapterLookupsHost The host.
	 */
	private function host(array $values, ?object $store = null, ?LoggerInterface $logger = null): ChannelAdapterLookupsHost {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default): string => ($values[$key] ?? $default)
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('absent'));

		return new ChannelAdapterLookupsHost($appConfig, $container, $logger ?? $this->createStub(LoggerInterface::class), $store);
	}//end host()

	/**
	 * @return void
	 */
	public function testScalarReads(): void {
		$host = $this->host(['tenant_id' => 't1', 'x_schema' => 'xs']);
		$this->assertSame('t1', $host->call('getTenantId'));
		$this->assertSame('default', $this->host([])->call('getTenantId'));
		$this->assertSame('xs', $host->call('resolveSchemaSlug', 'x_schema', 'fallback'));
		$this->assertSame('fallback', $host->call('resolveSchemaSlug', 'y_schema', 'fallback'));
		$this->assertSame('+31', $host->call('extractPhone', ['phone' => '+32', 'mobile' => '+31']));
		$this->assertSame('', $host->call('extractPhone', ['phoneNumber' => '']));
		$this->assertNull($host->call('contactmoments'));
	}//end testScalarReads()

	/**
	 * @return void
	 */
	public function testPersistMessage(): void {
		$this->assertNull($this->host([])->call('persistMessage', ['body' => 'x']));

		$store = new class {
			/** @var array<int, array<int, mixed>> */
			public array $calls = [];

			/**
			 * @param array<string, mixed> $object   Object.
			 * @param string               $register Register.
			 * @param string               $schema   Schema.
			 * @param string|null          $uuid     Uuid.
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, string $register, string $schema, ?string $uuid): array {
				if (($object['fail'] ?? false) === true) {
					throw new RuntimeException('locked');
				}

				$this->calls[] = [$register, $schema, $uuid];
				return $object + ['id' => 'm1'];
			}
		};
		$this->assertSame(['body' => 'x', 'id' => 'm1'], $this->host([], $store)->call('persistMessage', ['body' => 'x']));
		$this->assertSame([['reg', 'channelMessage', null]], $store->calls);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with('ChannelAdapterLookupsHost.persistMessage: save failed', ['exception' => 'locked']);
		$this->assertNull($this->host([], $store, $logger)->call('persistMessage', ['fail' => true]));
	}//end testPersistMessage()
}//end class

/**
 * A host exposing the trait's private reads.
 */
final class ChannelAdapterLookupsHost {
	use ChannelAdapterLookups;

	private const DEFAULT_MESSAGE_SCHEMA_SLUG = 'channelMessage';

	/**
	 * @param IAppConfig         $appConfig The app config.
	 * @param ContainerInterface $container The container.
	 * @param LoggerInterface    $logger    The logger.
	 * @param object|null        $store     The object service.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private ContainerInterface $container,
		private LoggerInterface $logger,
		private ?object $store,
	) {
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

	/**
	 * @param mixed $value The value.
	 * @return array<string, mixed>
	 */
	private function toArray(mixed $value): array {
		return (array)$value;
	}

	/**
	 * @param string $method  The private method.
	 * @param mixed  ...$args The arguments.
	 * @return mixed
	 */
	public function call(string $method, mixed ...$args): mixed {
		return $this->$method(...$args);
	}
}//end class
