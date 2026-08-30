<?php

/**
 * Unit tests for the CTI adapter registry.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-8.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\Cti\AdapterRegistry;
use OCA\Pipelinq\Service\Cti\CtiAdapterInterface;
use OCA\Pipelinq\Service\Cti\Result\CtiCallResult;
use OCA\Pipelinq\Service\Cti\Result\CtiWebhookResult;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Tests for the adapter registry.
 */
class CtiAdapterRegistryTest extends TestCase {
	/**
	 * The registry under test.
	 *
	 * @var AdapterRegistry
	 */
	private AdapterRegistry $registry;

	/**
	 * Mock container.
	 *
	 * @var ContainerInterface
	 */
	private ContainerInterface $container;

	/**
	 * Set up the test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->container = $this->createMock(ContainerInterface::class);
		$this->registry = new AdapterRegistry($this->container);
	}//end setUp()

	/**
	 * Test built-in platforms are registered.
	 *
	 * @return void
	 */
	public function testBuiltInsRegistered(): void {
		$platforms = $this->registry->listPlatforms();
		$this->assertContains('callvoip', $platforms);
		$this->assertContains('ringcentral', $platforms);
		$this->assertContains('asterisk', $platforms);
	}//end testBuiltInsRegistered()

	/**
	 * Test the registry throws when asked for an unknown platform.
	 *
	 * @return void
	 */
	public function testGetThrowsForUnknown(): void {
		$this->expectException(\RuntimeException::class);
		$this->registry->get('does-not-exist');
	}//end testGetThrowsForUnknown()

	/**
	 * Test custom adapter registration + resolution.
	 *
	 * @return void
	 */
	public function testCustomAdapterCanBeRegistered(): void {
		$adapter = new class implements CtiAdapterInterface {
			public function getPlatform(): string {
				return 'fake';
			}
			public function handleInboundWebhook(array $payload): CtiWebhookResult {
				return new CtiWebhookResult(eventType: 'unknown', externalCallId: '');
			}
			public function originateCall(string $extension, string $targetNumber, string $callerId): CtiCallResult {
				return new CtiCallResult(success: true);
			}
			public function subscribeToPresence(string $userId, string $extension): void {
			}
			public function verifyWebhookSignature(string $payload, string $signature): bool {
				return true;
			}
		};

		$this->container
			->method('get')
			->willReturn($adapter);

		$this->registry->register('fake', $adapter::class);
		$resolved = $this->registry->get('fake');
		$this->assertSame('fake', $resolved->getPlatform());
	}//end testCustomAdapterCanBeRegistered()
}//end class
