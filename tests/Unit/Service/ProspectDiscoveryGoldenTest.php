<?php

/**
 * Golden test for prospect discovery.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
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

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\ExistingClientNames;
use OCA\Pipelinq\Service\IcpConfigService;
use OCA\Pipelinq\Service\KvkApiClient;
use OCA\Pipelinq\Service\OpenCorporatesApiClient;
use OCA\Pipelinq\Service\ProspectDiscoveryService;
use OCA\Pipelinq\Service\ProspectRanking;
use OCA\Pipelinq\Service\ProspectScoringService;
use OCA\Pipelinq\Service\ProspectSources;
use OCA\Pipelinq\Service\SettingsService;
use OCP\App\IAppManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Runs discover() over scripted sources, clients and cache states, and
 * compares the result and every collaborator call with
 * ProspectDiscoveryGolden.json. Set PROSPECT_GOLDEN_RECORD=1 to rewrite it.
 *
 * @covers \OCA\Pipelinq\Service\ProspectDiscoveryService
 * @covers \OCA\Pipelinq\Service\ProspectSources
 * @covers \OCA\Pipelinq\Service\ExistingClientNames
 * @covers \OCA\Pipelinq\Service\ProspectRanking
 */
class ProspectDiscoveryGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/ProspectDiscoveryGolden.json';

	/**
	 * Calls recorded during one case, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $events = [];

	/**
	 * Every case's setup.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function definitions(): array {
		$base = [
			'configured'  => true,
			'criteria'    => ['sbi' => ['62'], 'excludeInactive' => true],
			'kvk'         => 'rows',
			'oc'          => 'off',
			'clients'     => 'rows',
			'installed'   => ['openregister'],
			'register'    => 'reg',
			'container'   => 'ok',
			'cached'      => null,
			'refresh'     => false,
			'limit'       => 10,
		];

		return [
			'not configured'           => array_merge($base, ['configured' => false]),
			'kvk only'                 => $base,
			'kvk fails'                => array_merge($base, ['kvk' => 'throw']),
			'opencorporates merged'    => array_merge($base, ['oc' => 'rows']),
			'opencorporates fails'     => array_merge($base, ['oc' => 'throw']),
			'opencorporates not bool'  => array_merge($base, ['oc' => 'truthy']),
			'inactive kept'            => array_merge($base, ['criteria' => ['sbi' => ['62'], 'excludeInactive' => false]]),
			'no inactive setting'      => array_merge($base, ['criteria' => ['sbi' => ['62']]]),
			'limit two'                => array_merge($base, ['limit' => 2]),
			'limit zero'               => array_merge($base, ['limit' => 0]),
			'openregister missing'     => array_merge($base, ['installed' => []]),
			'no register'              => array_merge($base, ['register' => '']),
			'container fails'          => array_merge($base, ['container' => 'throw']),
			'cached list'              => array_merge($base, ['cached' => 'hit']),
			'cached list refreshed'    => array_merge($base, ['cached' => 'hit', 'refresh' => true]),
		];
	}//end definitions()

	/**
	 * The case names.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function cases(): array {
		$names = [];
		foreach (array_keys(self::definitions()) as $name) {
			$names[$name] = [$name];
		}

		return $names;
	}//end cases()

	/**
	 * Record one call.
	 *
	 * @param string               $call The call name.
	 * @param array<string, mixed> $args The arguments by name.
	 *
	 * @return void
	 */
	public function recordCall(string $call, array $args): void {
		$this->events[] = ['call' => $call, 'args' => $args];
	}//end recordCall()

	/**
	 * A prospect row.
	 *
	 * @param string $kvk    The KVK number.
	 * @param string $name   The trade name.
	 * @param bool   $active Whether it is active.
	 *
	 * @return array<string, mixed>
	 */
	private static function prospect(string $kvk, string $name, bool $active=true): array {
		return ['kvkNumber' => $kvk, 'tradeName' => $name, 'isActive' => $active];
	}//end prospect()

	/**
	 * Mocks for every collaborator.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<string, mixed>
	 */
	private function collaborators(array $case): array {
		$icp = $this->createMock(originalClassName: IcpConfigService::class);
		$icp->method('isConfigured')->willReturn($case['configured']);
		$icp->method('getIcpHash')->willReturn('hash1');
		$icp->method('getCriteria')->willReturn($case['criteria']);
		$icp->method('getKvkApiKey')->willReturn('secret-key');
		$icp->method('getSettings')->willReturn(['openCorporatesEnabled' => ['off' => false, 'rows' => true, 'throw' => true, 'truthy' => 1][$case['oc']]]);

		$kvk = $this->createMock(originalClassName: KvkApiClient::class);
		$kvk->method('search')->willReturnCallback(
			function (string $apiKey, array $criteria) use ($case): array {
				$this->recordCall(call: 'kvk.search', args: ['apiKey' => $apiKey, 'criteria' => $criteria]);
				if ($case['kvk'] === 'throw') {
					throw new RuntimeException('kvk down');
				}

				return [
					self::prospect(kvk: '1', name: 'Alpha BV'),
					self::prospect(kvk: '2', name: 'Existing Client'),
					self::prospect(kvk: '3', name: 'Gamma', active: false),
					self::prospect(kvk: '4', name: ''),
					self::prospect(kvk: '5', name: 'Delta Group'),
				];
			}
		);

		$oc = $this->createMock(originalClassName: OpenCorporatesApiClient::class);
		$oc->method('search')->willReturnCallback(
			function (array $criteria) use ($case): array {
				$this->recordCall(call: 'oc.search', args: ['criteria' => $criteria]);
				if ($case['oc'] === 'throw') {
					throw new RuntimeException('oc down');
				}

				return [self::prospect(kvk: '1', name: 'Alpha Other'), self::prospect(kvk: '9', name: 'Omega')];
			}
		);

		$scoring = $this->createMock(originalClassName: ProspectScoringService::class);
		$scoring->method('scoreAll')->willReturnCallback(
			function (array $prospects, array $criteria): array {
				$this->recordCall(
					call: 'scoreAll',
					args: ['keys' => array_keys($prospects), 'kvkNumbers' => array_column($prospects, 'kvkNumber'), 'criteria' => $criteria]
				);
				foreach ($prospects as $i => $prospect) {
					$prospects[$i]['score'] = (int) $prospect['kvkNumber'];
				}

				usort($prospects, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
				return $prospects;
			}
		);

		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key, string $default='') use ($case): string {
				if ($key === 'register') {
					return $case['register'];
				}

				return 'client';
			}
		);
		$settings->method('getIntValue')->willReturn(3600);

		$clientRow = new class {
			/**
			 * The serialised client.
			 *
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return ['name' => '  EXISTING client '];
			}//end jsonSerialize()
		};
		$objectService = new class ($this, $clientRow) {
			/**
			 * @param ProspectDiscoveryGoldenTest $test   The recording test.
			 * @param object                      $client A client entity.
			 */
			public function __construct(private ProspectDiscoveryGoldenTest $test, private object $client) {
			}//end __construct()

			/**
			 * The stored clients.
			 *
			 * @param array<string, mixed> $config The query.
			 *
			 * @return array<int, mixed>
			 */
			public function findAll(array $config): array {
				$this->test->recordCall(call: 'findAll', args: ['config' => $config]);
				return [$this->client, ['tradeName' => 'Delta Group'], ['name' => ''], ['other' => 'x']];
			}//end findAll()
		};

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($case, $objectService): object {
				$this->recordCall(call: 'container.get', args: ['id' => $id]);
				if ($case['container'] === 'throw') {
					throw new RuntimeException('no ObjectService');
				}

				return $objectService;
			}
		);

		$appManager = $this->createMock(originalClassName: IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn($case['installed']);

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		foreach (['error', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string|\Stringable $message, array $context=[]) use ($level): void {
					$this->recordCall(call: 'log.' . $level, args: ['message' => (string) $message, 'context' => $context]);
				}
			);
		}

		return [
			'icp'        => $icp,
			'kvk'        => $kvk,
			'oc'         => $oc,
			'scoring'    => $scoring,
			'settings'   => $settings,
			'logger'     => $logger,
			'container'  => $container,
			'appManager' => $appManager,
		];
	}//end collaborators()

	/**
	 * Build the service with an in-memory cache that records its use.
	 *
	 * @param array<string, mixed>      $c      The collaborators.
	 * @param array<string, mixed>|null $cached The entry the cache starts with.
	 *
	 * @return ProspectDiscoveryService
	 */
	private function makeService(array $c, ?array $cached): ProspectDiscoveryService {
		$clients = new ExistingClientNames(settings: $c['settings'], container: $c['container'], appManager: $c['appManager'], logger: $c['logger']);
		$sources = new ProspectSources(icpConfig: $c['icp'], kvkClient: $c['kvk'], ocClient: $c['oc'], logger: $c['logger']);
		$ranking = new ProspectRanking(scoring: $c['scoring'], clients: $clients);
		$service = new class ($c['icp'], $sources, $ranking, $c['settings']) extends ProspectDiscoveryService {
			/**
			 * The recording test.
			 *
			 * @var ProspectDiscoveryGoldenTest|null
			 */
			public ?ProspectDiscoveryGoldenTest $test = null;

			/**
			 * The cached entries by key.
			 *
			 * @var array<string, array>
			 */
			public array $memory = [];

			/**
			 * Read an entry from memory.
			 *
			 * @param string $key The cache key.
			 *
			 * @return array|null
			 */
			protected function getFromCache(string $key): ?array {
				$this->test?->recordCall(call: 'cache.get', args: ['key' => $key]);
				return $this->memory[$key] ?? null;
			}//end getFromCache()

			/**
			 * Write an entry to memory.
			 *
			 * @param string $key  The cache key.
			 * @param array  $data The data.
			 *
			 * @return void
			 */
			protected function setInCache(string $key, array $data): void {
				$data['cachedAt'] = '<now>';
				$this->test?->recordCall(call: 'cache.set', args: ['key' => $key, 'data' => $data]);
				$this->memory[$key] = $data;
			}//end setInCache()
		};
		$service->test = $this;
		if ($cached !== null) {
			$service->memory['pipelinq_prospects_v2_hash1'] = $cached;
		}

		return $service;
	}//end makeService()

	/**
	 * The result and the calls match the golden.
	 *
	 * @param string $name The case name.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testDiscoveryMatchesTheGolden(string $name): void {
		$case         = self::definitions()[$name];
		$this->events = [];
		$cached       = null;
		if ($case['cached'] === 'hit') {
			$cached = [
				'prospects' => [
					self::prospect(kvk: '8', name: 'Cached One'),
					self::prospect(kvk: '7', name: 'Existing Client'),
					self::prospect(kvk: '6', name: 'Cached Two'),
				],
				'total'     => 3,
				'cachedAt'  => 'then',
				'icpHash'   => 'hash1',
			];
		}

		$service = $this->makeService(c: $this->collaborators(case: $case), cached: $cached);
		$result  = $service->discover(refresh: $case['refresh'], limit: $case['limit']);
		if (isset($result['cachedAt']) === true && $result['cachedAt'] !== 'then') {
			$result['cachedAt'] = '<now>';
		}

		$actual = json_decode((string) json_encode(['result' => $result, 'events' => $this->events]), true);
		if (getenv('PROSPECT_GOLDEN_RECORD') !== false) {
			$golden = [];
			if (file_exists(self::GOLDEN) === true) {
				$golden = json_decode((string) file_get_contents(self::GOLDEN), true);
			}

			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::GOLDEN, json_encode($golden, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n");
		}

		$golden = json_decode((string) file_get_contents(self::GOLDEN), true);
		$this->assertArrayHasKey(key: $name, array: $golden);
		$this->assertSame(expected: $golden[$name], actual: $actual);
	}//end testDiscoveryMatchesTheGolden()
}//end class
