<?php

/**
 * Unit tests for the CTI webhook gates and the adapter registry.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Cti
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/cti-screenpop-adapter/spec.md#requirement-inbound-screen-pop-on-call-answer-req-cti-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Cti;

use OCA\Pipelinq\Service\Cti\Adapter\AsteriskAdapter;
use OCA\Pipelinq\Service\Cti\Adapter\CallVoipAdapter;
use OCA\Pipelinq\Service\Cti\Adapter\RingCentralAdapter;
use OCA\Pipelinq\Service\Cti\AdapterRegistry;
use OCA\Pipelinq\Service\Cti\CtiAdapterInterface;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use stdClass;

/**
 * Each platform's webhook gate refuses when no secret is configured or no
 * signature is sent; the registry builds each adapter once and refuses a
 * class that is not an adapter.
 *
 * @covers \OCA\Pipelinq\Service\Cti\Adapter\RingCentralAdapter
 * @covers \OCA\Pipelinq\Service\Cti\Adapter\CallVoipAdapter
 * @covers \OCA\Pipelinq\Service\Cti\Adapter\AsteriskAdapter
 * @covers \OCA\Pipelinq\Service\Cti\AdapterRegistry
 */
final class CtiWebhookGateAndRegistryTest extends TestCase {

	/**
	 * Each adapter and the config key holding its secret.
	 *
	 * @return array<string, array{0: class-string, 1: string}>
	 */
	public static function adapters(): array {
		return [
			'RingCentral' => [RingCentralAdapter::class, 'cti_ringcentral_webhook_token'],
			'CallVoip' => [CallVoipAdapter::class, 'cti_callvoip_webhook_secret'],
			'Asterisk' => [AsteriskAdapter::class, 'cti_asterisk_webhook_secret'],
		];
	}//end adapters()

	/**
	 * With no secret configured, nothing is accepted, not even an empty match.
	 *
	 * @param class-string $class The adapter.
	 * @param string       $key   Its secret's config key.
	 *
	 * @dataProvider adapters
	 *
	 * @return void
	 */
	public function testNoConfiguredSecretAcceptsNothing(string $class, string $key): void {
		$adapter = $this->adapter(class: $class, key: $key, secret: '');

		$this->assertFalse($adapter->verifyWebhookSignature('{"event":"answered"}', ''));
		$this->assertFalse($adapter->verifyWebhookSignature('{"event":"answered"}', 'anything'));
	}//end testNoConfiguredSecretAcceptsNothing()

	/**
	 * With a secret configured, a missing signature is still refused.
	 *
	 * @param class-string $class The adapter.
	 * @param string       $key   Its secret's config key.
	 *
	 * @dataProvider adapters
	 *
	 * @return void
	 */
	public function testAMissingSignatureIsRefused(string $class, string $key): void {
		$this->assertFalse($this->adapter(class: $class, key: $key, secret: 's3cret')->verifyWebhookSignature('{}', ''));
	}//end testAMissingSignatureIsRefused()

	/**
	 * RingCentral accepts exactly its configured validation token.
	 *
	 * @return void
	 */
	public function testRingCentralAcceptsOnlyItsToken(): void {
		$adapter = $this->adapter(class: RingCentralAdapter::class, key: 'cti_ringcentral_webhook_token', secret: 'rc-token');

		$this->assertTrue($adapter->verifyWebhookSignature('{}', 'rc-token'));
		$this->assertFalse($adapter->verifyWebhookSignature('{}', 'rc-token '));
		$this->assertFalse($adapter->verifyWebhookSignature('{}', 'other'));
	}//end testRingCentralAcceptsOnlyItsToken()

	/**
	 * An adapter is built once per platform, whatever the platform's case or
	 * padding, and re-registering a platform builds it afresh.
	 *
	 * @return void
	 */
	public function testTheRegistryBuildsEachAdapterOnce(): void {
		$first = $this->createMock(CtiAdapterInterface::class);
		$second = $this->createMock(CtiAdapterInterface::class);
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->exactly(2))->method('get')->with(CallVoipAdapter::class)
			->willReturnOnConsecutiveCalls($first, $second);
		$registry = new AdapterRegistry(container: $container);

		$this->assertSame($first, $registry->get('callvoip'));
		$this->assertSame($first, $registry->get(' CallVoip '));

		$registry->register(platform: 'callvoip', adapterClass: CallVoipAdapter::class);
		$this->assertSame($second, $registry->get('callvoip'));
		$this->assertSame(['callvoip', 'ringcentral', 'asterisk'], $registry->listPlatforms());
	}//end testTheRegistryBuildsEachAdapterOnce()

	/**
	 * A registered class that is not an adapter is refused by name.
	 *
	 * @return void
	 */
	public function testTheRegistryRefusesANonAdapter(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn(new stdClass());
		$registry = new AdapterRegistry(container: $container);
		$registry->register(platform: 'teams', adapterClass: stdClass::class);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('CTI adapter class did not implement CtiAdapterInterface: stdClass');

		$registry->get('teams');
	}//end testTheRegistryRefusesANonAdapter()

	/**
	 * An adapter whose secret is the given value.
	 *
	 * @param class-string $class  The adapter.
	 * @param string       $key    Its secret's config key.
	 * @param string       $secret The configured secret.
	 *
	 * @return RingCentralAdapter|CallVoipAdapter|AsteriskAdapter
	 */
	private function adapter(string $class, string $key, string $secret): object {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $asked, string $default = ''): string => ($asked === $key ? $secret : $default)
		);

		return new $class($appConfig, $this->createMock(IClientService::class), $this->createMock(LoggerInterface::class));
	}//end adapter()
}//end class
