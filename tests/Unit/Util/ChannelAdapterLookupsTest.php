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
use RuntimeException;

/**
 * Phone, tenant and slug reads, and the find-or-open conversation flow.
 *
 * @covers \OCA\Pipelinq\Util\ChannelAdapterLookups
 */
class ChannelAdapterLookupsTest extends TestCase {

	/**
	 * Build a host over the given config values.
	 *
	 * @param array<string, string> $values Config values by key.
	 * @param object|null           $os     The object service the host hands out.
	 *
	 * @return ChannelAdapterLookupsHost The host.
	 */
	private function host(array $values, ?object $os = null): ChannelAdapterLookupsHost {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default): string => ($values[$key] ?? $default)
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('absent'));

		return new ChannelAdapterLookupsHost($appConfig, $container, $os);
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
	public function testFindOrOpenConversation(): void {
		$this->assertSame('', $this->host([])->call('findOrOpenConversation', 'c', 'p', 'sms'));

		$found = $this->host([], new \stdClass());
		$found->existing = 'conv-1';
		$this->assertSame('conv-1', $found->call('findOrOpenConversation', 'c', 'p', 'sms'));
		$this->assertSame('channelConversation', $found->seenSchema);
		$this->assertSame(['contactId' => 'c', 'providerId' => 'p', 'channel' => 'sms', 'status' => 'open'], $found->seenConversation);

		$opened = $this->host(['conversation_schema' => 'cs'], new \stdClass());
		$this->assertSame('new-1', $opened->call('findOrOpenConversation', 'c', 'p', 'whatsapp', ['_rbac' => false]));
		$this->assertSame('cs', $opened->seenSchema);
	}//end testFindOrOpenConversation()
}//end class

/**
 * A host with recording conversation steps.
 */
final class ChannelAdapterLookupsHost {
	use ChannelAdapterLookups;

	private const DEFAULT_CONVERSATION_SCHEMA_SLUG = 'channelConversation';

	public ?string $existing = null;

	public ?string $seenSchema = null;

	/**
	 * @var array<string, string>|null
	 */
	public ?array $seenConversation = null;

	/**
	 * @param IAppConfig         $appConfig The app config.
	 * @param ContainerInterface $container The container.
	 * @param object|null        $os        The object service.
	 */
	public function __construct(private IAppConfig $appConfig, private ContainerInterface $container, private ?object $os) {
	}

	/**
	 * @param string $method  The private method.
	 * @param mixed  ...$args The arguments.
	 * @return mixed
	 */
	public function call(string $method, mixed ...$args): mixed {
		return $this->$method(...$args);
	}

	/**
	 * @return object|null
	 */
	private function getObjectService(): ?object {
		return $this->os;
	}

	/**
	 * @param object                $objectService The object service.
	 * @param array<string, string> $conversation  The conversation identity.
	 * @param string                $schema        The schema.
	 * @param array<string, bool>   $scope         The scope.
	 * @return string|null
	 */
	private function findOpenConversation(object $objectService, array $conversation, string $schema, array $scope): ?string {
		$this->seenSchema = $schema;
		$this->seenConversation = $conversation;
		return $this->existing;
	}

	/**
	 * @param object                $objectService The object service.
	 * @param array<string, string> $conversation  The conversation identity.
	 * @param string                $schema        The schema.
	 * @return string
	 */
	private function openConversation(object $objectService, array $conversation, string $schema): string {
		return 'new-1';
	}
}//end class
