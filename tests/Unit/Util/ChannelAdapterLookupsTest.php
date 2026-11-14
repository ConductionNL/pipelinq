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
 * Contact-moment, phone, tenant and slug reads.
 *
 * @covers \OCA\Pipelinq\Util\ChannelAdapterLookups
 */
class ChannelAdapterLookupsTest extends TestCase {

	/**
	 * Build a host over the given config values.
	 *
	 * @param array<string, string> $values Config values by key.
	 *
	 * @return ChannelAdapterLookupsHost The host.
	 */
	private function host(array $values): ChannelAdapterLookupsHost {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default): string => ($values[$key] ?? $default)
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('absent'));

		return new ChannelAdapterLookupsHost($appConfig, $container);
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
}//end class

/**
 * A host exposing the trait's private reads.
 */
final class ChannelAdapterLookupsHost {
	use ChannelAdapterLookups;

	/**
	 * @param IAppConfig         $appConfig The app config.
	 * @param ContainerInterface $container The container.
	 */
	public function __construct(private IAppConfig $appConfig, private ContainerInterface $container) {
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
