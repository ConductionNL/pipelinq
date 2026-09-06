<?php

/**
 * Unit tests for ProspectDiscoveryService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\IcpConfigService;
use OCA\Pipelinq\Service\KvkApiClient;
use OCA\Pipelinq\Service\OpenCorporatesApiClient;
use OCA\Pipelinq\Service\ProspectDiscoveryService;
use OCA\Pipelinq\Service\ProspectScoringService;
use OCA\Pipelinq\Service\SettingsService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for ProspectDiscoveryService.
 */
class ProspectDiscoveryServiceTest extends TestCase {
	/**
	 * The service under test.
	 *
	 * @var ProspectDiscoveryService
	 */
	private ProspectDiscoveryService $service;

	/**
	 * Mock ICP config service.
	 *
	 * @var IcpConfigService
	 */
	private IcpConfigService $icpConfig;

	/**
	 * Set up the test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->icpConfig = $this->createMock(IcpConfigService::class);
		$kvkClient = $this->createMock(KvkApiClient::class);
		$ocClient = $this->createMock(OpenCorporatesApiClient::class);
		$scoring = new ProspectScoringService();
		$settings = $this->createMock(SettingsService::class);
		$logger = $this->createMock(LoggerInterface::class);

		$settings->method('getConfigValue')->willReturn('');
		// createLeadFromProspect calls getObjectStoreConfig() which needs
		// register + client_schema + lead_schema set, otherwise the method
		// returns early with ['error' => ...]. Stub a minimal valid config
		// so the happy-path tests can exercise the leadData/clientData
		// construction.
		$settings->method('getSettings')->willReturn([
			'register' => 'pipelinq',
			'client_schema' => 'client',
			'lead_schema' => 'lead',
		]);

		$container = $this->createMock(ContainerInterface::class);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn([]);

		$this->service = new ProspectDiscoveryService($this->icpConfig,
			$kvkClient,
			$ocClient,
			$scoring,
			$settings,
			$logger,
			$container,
			$appManager,
		);
	}//end setUp()

	/**
	 * Test discover returns error when ICP not configured.
	 *
	 * @return void
	 */
	public function testDiscoverReturnsErrorWhenNotConfigured(): void {
		$this->icpConfig->method('isConfigured')->willReturn(false);

		$result = $this->service->discover();

		$this->assertSame('no_icp_configured', $result['error']);
	}//end testDiscoverReturnsErrorWhenNotConfigured()
}//end class
