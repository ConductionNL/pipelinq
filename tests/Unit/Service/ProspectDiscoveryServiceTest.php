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

	/**
	 * Test an existing client is excluded when OpenRegister returns entities.
	 *
	 * ObjectService::findAll() returns ObjectEntity instances, which cannot be
	 * read as arrays; that used to crash the whole discovery request.
	 *
	 * @return void
	 */
	public function testDiscoverExcludesExistingClientsReturnedAsEntities(): void {
		$icpConfig = $this->createMock(IcpConfigService::class);
		$icpConfig->method('isConfigured')->willReturn(true);
		$icpConfig->method('getIcpHash')->willReturn('abc12345');
		$icpConfig->method('getCriteria')->willReturn(['sbiCodes' => ['62']]);
		$icpConfig->method('getKvkApiKey')->willReturn('key');
		$icpConfig->method('getSettings')->willReturn([]);

		$kvkClient = $this->createMock(KvkApiClient::class);
		$kvkClient->method('search')->willReturn([
			['kvkNumber' => '1', 'tradeName' => 'Bitbrug Software', 'sbiCode' => '6201', 'isActive' => true],
			['kvkNumber' => '2', 'tradeName' => 'Polderwerk IT', 'sbiCode' => '6202', 'isActive' => true],
		]);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnMap([
			['register', 'pipelinq'],
			['client_schema', 'client'],
		]);

		$client = new class {
			/**
			 * The serialised object, as an ObjectEntity yields it.
			 *
			 * @return array
			 */
			public function jsonSerialize(): array {
				return ['name' => 'Bitbrug Software'];
			}
		};
		$objectService = new class([$client]) {
			/**
			 * Constructor.
			 *
			 * @param array $objects The objects findAll returns.
			 */
			public function __construct(private array $objects) {
			}

			/**
			 * Return the stubbed objects.
			 *
			 * @param array $config The query config.
			 *
			 * @return array
			 */
			public function findAll(array $config): array {
				return $this->objects;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['openregister']);

		$service = new ProspectDiscoveryService(
			$icpConfig,
			$kvkClient,
			$this->createMock(OpenCorporatesApiClient::class),
			new ProspectScoringService(),
			$settings,
			$this->createMock(LoggerInterface::class),
			$container,
			$appManager,
		);

		$result = $service->discover(refresh: true);

		$this->assertSame(1, $result['total']);
		$this->assertSame('Polderwerk IT', $result['prospects'][0]['tradeName']);
	}//end testDiscoverExcludesExistingClientsReturnedAsEntities()

	/**
	 * Test the limit: the top 10 by default, every prospect for 0.
	 *
	 * @return void
	 */
	public function testDiscoverLimitsToTheTopTenUnlessAskedForAll(): void {
		$icpConfig = $this->createMock(IcpConfigService::class);
		$icpConfig->method('isConfigured')->willReturn(true);
		$icpConfig->method('getIcpHash')->willReturn('abc12345');
		$icpConfig->method('getCriteria')->willReturn(['sbiCodes' => ['62']]);
		$icpConfig->method('getKvkApiKey')->willReturn('key');
		$icpConfig->method('getSettings')->willReturn([]);

		$rows = [];
		for ($i = 1; $i <= 12; $i++) {
			$rows[] = ['kvkNumber' => (string)$i, 'tradeName' => 'Company ' . $i, 'sbiCode' => '6201', 'isActive' => true];
		}

		$kvkClient = $this->createMock(KvkApiClient::class);
		$kvkClient->method('search')->willReturn($rows);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturn('');

		$service = new ProspectDiscoveryService(
			$icpConfig,
			$kvkClient,
			$this->createMock(OpenCorporatesApiClient::class),
			new ProspectScoringService(),
			$settings,
			$this->createMock(LoggerInterface::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(IAppManager::class),
		);

		$top = $service->discover(refresh: true);
		$this->assertSame(12, $top['total']);
		$this->assertSame(10, $top['displayed']);
		$this->assertCount(10, $top['prospects']);

		$all = $service->discover(refresh: true, limit: 0);
		$this->assertSame(12, $all['displayed']);
		$this->assertCount(12, $all['prospects']);
	}//end testDiscoverLimitsToTheTopTenUnlessAskedForAll()

	/**
	 * A cached list serves every limit, and a prospect added as a client since
	 * it was cached drops out without a refresh.
	 *
	 * @return void
	 */
	public function testCachedListServesEveryLimitAndDropsNewClients(): void {
		$icpConfig = $this->createMock(IcpConfigService::class);
		$icpConfig->method('isConfigured')->willReturn(true);
		$icpConfig->method('getIcpHash')->willReturn('abc12345');
		$icpConfig->method('getCriteria')->willReturn(['sbiCodes' => ['62']]);
		$icpConfig->method('getKvkApiKey')->willReturn('key');
		$icpConfig->method('getSettings')->willReturn([]);

		$rows = [];
		for ($i = 1; $i <= 12; $i++) {
			$rows[] = ['kvkNumber' => (string)$i, 'tradeName' => 'Company ' . $i, 'sbiCode' => '6201', 'isActive' => true];
		}

		$kvkClient = $this->createMock(KvkApiClient::class);
		$kvkClient->expects($this->once())->method('search')->willReturn($rows);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnMap([
			['register', 'pipelinq'],
			['client_schema', 'client'],
		]);

		$objectService = new class {
			/**
			 * The clients findAll returns.
			 *
			 * @var array
			 */
			public array $clients = [];

			/**
			 * Return the clients.
			 *
			 * @param array $config The query config.
			 *
			 * @return array
			 */
			public function findAll(array $config): array {
				return $this->clients;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['openregister']);

		// An in-memory cache, so the test does not depend on APCu being enabled for the CLI.
		$service = new class(
			$icpConfig,
			$kvkClient,
			$this->createMock(OpenCorporatesApiClient::class),
			new ProspectScoringService(),
			$settings,
			$this->createMock(LoggerInterface::class),
			$container,
			$appManager,
		) extends ProspectDiscoveryService {
			/**
			 * The cached entries by key.
			 *
			 * @var array<string, array>
			 */
			private array $memory = [];

			/**
			 * Read an entry from memory.
			 *
			 * @param string $key The cache key.
			 *
			 * @return array|null
			 */
			protected function getFromCache(string $key): ?array {
				return $this->memory[$key] ?? null;
			}

			/**
			 * Write an entry to memory.
			 *
			 * @param string $key  The cache key.
			 * @param array  $data The data.
			 *
			 * @return void
			 */
			protected function setInCache(string $key, array $data): void {
				$this->memory[$key] = $data;
			}
		};

		$this->assertSame(10, $service->discover()['displayed']);
		$this->assertSame(12, $service->discover(limit: 0)['displayed']);

		$objectService->clients = [['name' => 'Company 3']];
		$all = $service->discover(limit: 0);
		$this->assertSame(11, $all['total']);
		$this->assertNotContains('Company 3', array_column($all['prospects'], 'tradeName'));
	}//end testCachedListServesEveryLimitAndDropsNewClients()
}//end class
