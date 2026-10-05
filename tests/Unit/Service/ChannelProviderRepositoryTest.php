<?php

/**
 * Unit tests for ChannelProviderRepository::findById().
 *
 * @category Tests
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#2.4
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\ChannelProviderRepository;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * ChannelProviderRepository lookups for a caller with and without a user.
 */
class ChannelProviderRepositoryTest extends TestCase {
	/**
	 * A repository over an ObjectService that behaves like OpenRegister's for
	 * an anonymous web request: under RBAC the provider is not found.
	 *
	 * @return ChannelProviderRepository
	 */
	private function repositoryForAnAnonymousRequest(): ChannelProviderRepository {
		$objectService = new class {
			public function find(
				int|string $id,
				?array $_extend = [],
				bool $files = false,
				$register = null,
				$schema = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
			): array {
				if ($_rbac === true || $_multitenancy === true) {
					throw new \RuntimeException("Object with identifier '" . $id . "' not found in any magic table");
				}

				return ['uuid' => (string)$id, 'kind' => 'sms', 'vendor' => 'messagebird', 'webhookSecret' => 's'];
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnArgument(2);

		return new ChannelProviderRepository($container, $appConfig, new NullLogger());
	}//end repositoryForAnAnonymousRequest()

	/**
	 * A user-scoped lookup keeps RBAC: anonymous finds nothing.
	 *
	 * @return void
	 */
	public function testAUserScopedLookupKeepsRbac(): void {
		$this->assertNull($this->repositoryForAnAnonymousRequest()->findById(id: 'prov-1'));
	}//end testAUserScopedLookupKeepsRbac()

	/**
	 * The signed-webhook lookup reads the provider as the system.
	 *
	 * @return void
	 */
	public function testASystemLookupFindsTheProviderWithoutAUser(): void {
		$row = $this->repositoryForAnAnonymousRequest()->findById(id: 'prov-1', asSystem: true);

		$this->assertSame('prov-1', $row['uuid'] ?? null);
	}//end testASystemLookupFindsTheProviderWithoutAUser()
}//end class
