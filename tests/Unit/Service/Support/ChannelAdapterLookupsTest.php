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

namespace OCA\Pipelinq\Tests\Unit\Service\Support;

use OCA\Pipelinq\Service\Support\ChannelAdapterLookups;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Contact-moment, phone, tenant and slug reads, and the message save.
 *
 * @covers \OCA\Pipelinq\Service\Support\ChannelAdapterLookups
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

	/**
	 * @return void
	 */
	public function testFindOrOpenConversation(): void {
		$this->assertSame('', $this->host([])->call('findOrOpenConversation', 'c1', 'p1', 'sms'));

		$store = new class {
			/** @var array<int, mixed> */
			public array $rows = [];

			/** @var array<int, array<int, mixed>> */
			public array $saved = [];

			public bool $broken = false;

			/**
			 * @param array<string, mixed> $config        Config.
			 * @param bool                 $_rbac         Rbac.
			 * @param bool                 $_multitenancy Multitenancy.
			 * @return array<int, mixed>
			 */
			public function findAll(array $config, bool $_rbac, bool $_multitenancy): array {
				if ($this->broken === true) {
					throw new RuntimeException('down');
				}

				$this->saved[] = [$config['filters'], $_rbac, $_multitenancy];
				return $this->rows;
			}

			/**
			 * @param array<string, mixed> $object   Object.
			 * @param string               $register Register.
			 * @param string               $schema   Schema.
			 * @param string|null          $uuid     Uuid.
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, string $register, string $schema, ?string $uuid): array {
				if ($this->broken === true) {
					throw new RuntimeException('locked');
				}

				$this->saved[] = [$object, $register, $schema, $uuid];
				return $object + ['id' => 'new'];
			}
		};

		$store->rows = [['id' => 'existing']];
		$this->assertSame('existing', $this->host([], $store)->call('findOrOpenConversation', 'c1', 'p1', 'sms', ['_rbac' => false]));
		$identity = ['contactId' => 'c1', 'providerId' => 'p1', 'channel' => 'sms', 'status' => 'open'];
		$this->assertSame([[$identity + ['register' => 'reg', 'schema' => 'channelConversation'], false, true]], $store->saved);

		$store->rows = [];
		$store->saved = [];
		$this->assertSame('new', $this->host([], $store)->call('findOrOpenConversation', 'c1', 'p1', 'sms'));
		$this->assertSame([$identity + ['lastInboundAt' => '2026-01-01T00:00:00Z'], 'reg', 'channelConversation', null], $store->saved[1]);

		$store->broken = true;
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly(2))->method('warning')->willReturnCallback(
			function (string $message, array $context): void {
				$this->assertContains(
					$message,
					[
						'ChannelAdapterLookupsHost.findOrOpenConversation: findAll failed',
						'ChannelAdapterLookupsHost.findOrOpenConversation: open failed',
					]
				);
				$this->assertArrayHasKey('exception', $context);
			}
		);
		$this->assertSame('', $this->host([], $store, $logger)->call('findOrOpenConversation', 'c1', 'p1', 'sms'));
	}//end testFindOrOpenConversation()
}//end class

/**
 * A host exposing the trait's private reads.
 */
final class ChannelAdapterLookupsHost {
	use ChannelAdapterLookups;

	private const DEFAULT_MESSAGE_SCHEMA_SLUG = 'channelMessage';

	private const DEFAULT_CONVERSATION_SCHEMA_SLUG = 'channelConversation';

	private const CONVERSATION_LOOKUP_FAILURE = 'findAll failed';

	private const CONVERSATION_OPENING_STAMPS = ['lastInboundAt'];

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
	 * @param array<string, mixed> $payload The row.
	 * @return string
	 */
	private function extractId(array $payload): string {
		return (string)($payload['id'] ?? '');
	}

	/**
	 * @return string
	 */
	private function nowIso(): string {
		return '2026-01-01T00:00:00Z';
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
