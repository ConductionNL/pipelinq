<?php

/**
 * Golden test for the KvK and OpenCorporates clients: every leaf and direct-path outcome.
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

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\KvkApiClient;
use OCA\Pipelinq\Service\KvkResultMapper;
use OCA\Pipelinq\Service\OpenCorporatesApiClient;
use OCA\Pipelinq\Service\OpenCorporatesResultMapper;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Runs both company-registry clients through every answer of the OpenRegister
 * leaf (rows, non-200, unusable bodies, an exception) and of the direct API
 * (JSON, not JSON, an exception), and compares the GET calls made, the log lines
 * and the results with a recorded golden file.
 */
class CompanyRegistryClientGoldenTest extends TestCase {
	private const GOLDEN = __DIR__ . '/CompanyRegistryClientGolden.json';

	/**
	 * The calls made, in order.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $trace = [];

	/**
	 * Run every case and compare with the golden file.
	 *
	 * @return void
	 */
	public function testGolden(): void {
		$leaf = [
			'rows' => [200, '{"results":[{"kvkNummer":"111","naam":"A"},"skip",{"kvkNummer":"222","naam":"B"}]}'],
			'empty rows' => [200, '{"results":[]}'],
			'http 503' => [503, '{"results":[{"kvkNummer":"111"}]}'],
			'not json' => [200, 'nope'],
			'no results key' => [200, '{"data":[]}'],
			'results not a list' => [200, '{"results":"x"}'],
			'throws' => null,
		];
		$direct = [
			'json' => '{"resultaten":[{"kvkNummer":"333"}],"results":{"companies":[{"company":{"company_number":"444"}},{"other":1}]}}',
			'not json' => 'nope',
			'throws' => null,
		];

		$actual = [];
		foreach ($leaf as $leafName => $leafReply) {
			foreach ($direct as $directName => $directReply) {
				foreach (['', 'key-1'] as $apiKey) {
					$label = 'kvk search, key ' . ($apiKey === '' ? 'none' : 'set') . ', leaf ' . $leafName . ', direct ' . $directName;
					$actual[$label] = $this->runKvk(leafReply: $leafReply, directReply: $directReply, call: static fn (KvkApiClient $c): array => $c->search($apiKey, ['sbiCodes' => ['6201', '6202']]));
				}

				$actual['opencorporates search, leaf ' . $leafName . ', direct ' . $directName] = $this->runOpenCorporates(leafReply: $leafReply, directReply: $directReply);
			}

			$actual['kvk companyByNumber, leaf ' . $leafName] = $this->runKvk(leafReply: $leafReply, directReply: null, call: static fn (KvkApiClient $c): array => $c->companyByNumber('69599084'));
			$actual['kvk searchByName, leaf ' . $leafName] = $this->runKvk(leafReply: $leafReply, directReply: null, call: static fn (KvkApiClient $c): array => $c->searchByName('Conduction & Co'));
		}

		$actual['opencorporates, no keywords'] = $this->runOpenCorporates(leafReply: null, directReply: null, criteria: ['keywords' => []]);

		$flags = (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if (getenv('COMPANY_REGISTRY_GOLDEN_RECORD') !== false) {
			file_put_contents(self::GOLDEN, json_encode($actual, $flags) . "\n");
			$this->markTestSkipped(message: 'recorded');
		}

		$expected = json_decode((string)file_get_contents(self::GOLDEN), true);
		foreach ($expected as $name => $want) {
			$this->assertSame(expected: json_encode($want, $flags), actual: json_encode(($actual[$name] ?? null), $flags), message: $name);
		}

		$this->assertSame(expected: array_keys($expected), actual: array_keys($actual));
	}//end testGolden()

	/**
	 * Run one KvK client call.
	 *
	 * @param array{0: int, 1: string}|null $leafReply   The leaf's status and body, or null to throw.
	 * @param string|null                   $directReply The direct API's body, or null to throw.
	 * @param callable                      $call        The call to make.
	 *
	 * @return array<string, mixed>
	 */
	private function runKvk(?array $leafReply, ?string $directReply, callable $call): array {
		$this->trace = [];
		$mapper = $this->createMock(KvkResultMapper::class);
		$mapper->method('mapResult')->willReturnCallback(
			fn (array $item, string $sbiCode): ?array => (isset($item['kvkNummer']) === true ? ['kvkNumber' => $item['kvkNummer'] . '@' . $sbiCode, 'item' => $item] : null)
		);
		$client = new KvkApiClient($this->clients($leafReply, $directReply), $this->config(), $this->logger(), $mapper, $this->urls());
		return ['result' => $call($client), 'calls' => $this->trace];
	}//end runKvk()

	/**
	 * Run one OpenCorporates search.
	 *
	 * @param array{0: int, 1: string}|null $leafReply   The leaf's status and body, or null to throw.
	 * @param string|null                   $directReply The direct API's body, or null to throw.
	 * @param array<string, mixed>          $criteria    The search criteria.
	 *
	 * @return array<string, mixed>
	 */
	private function runOpenCorporates(?array $leafReply, ?string $directReply, array $criteria = ['keywords' => ['bakker', 'smid']]): array {
		$this->trace = [];
		$mapper = $this->createMock(OpenCorporatesResultMapper::class);
		$mapper->method('mapResult')->willReturnCallback(
			fn (array $company): ?array => (isset($company['company_number']) === true || isset($company['kvkNummer']) === true
				? ['kvkNumber' => (string)($company['company_number'] ?? $company['kvkNummer']), 'company' => $company]
				: null)
		);
		$client = new OpenCorporatesApiClient($this->clients($leafReply, $directReply), $this->config(), $this->logger(), $mapper, $this->urls());
		return ['result' => $client->search($criteria), 'calls' => $this->trace];
	}//end runOpenCorporates()

	/**
	 * A client service whose client answers the leaf and the direct API as given.
	 *
	 * @param array{0: int, 1: string}|null $leafReply   The leaf's status and body, or null to throw.
	 * @param string|null                   $directReply The direct API's body, or null to throw.
	 *
	 * @return IClientService
	 */
	private function clients(?array $leafReply, ?string $directReply): IClientService {
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(
			function (string $uri, array $options = []) use ($leafReply, $directReply): IResponse {
				$this->trace[] = ['get', $uri, $options];
				$isLeaf = str_contains($uri, '/apps/openregister/');
				if (($isLeaf === true && $leafReply === null) || ($isLeaf === false && $directReply === null)) {
					throw new \RuntimeException('down');
				}

				$response = $this->createMock(IResponse::class);
				$response->method('getStatusCode')->willReturn(($isLeaf === true ? $leafReply[0] : 200));
				$response->method('getBody')->willReturn(($isLeaf === true ? $leafReply[1] : $directReply));
				return $response;
			}
		);
		$service = $this->createMock(IClientService::class);
		$service->method('newClient')->willReturnCallback(
			function () use ($client): IClient {
				$this->trace[] = ['newClient'];
				return $client;
			}
		);
		return $service;
	}//end clients()

	/**
	 * An app config with the default API bases.
	 *
	 * @return IAppConfig
	 */
	private function config(): IAppConfig {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $default . '/'
		);
		return $config;
	}//end config()

	/**
	 * A logger recording warnings and debug lines.
	 *
	 * @return LoggerInterface
	 */
	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					if (isset($context['exception']) === true) {
						$context['exception'] = get_class($context['exception']) . ': ' . $context['exception']->getMessage();
					}

					$this->trace[] = [$level, $message, $context];
				}
			);
		}

		return $logger;
	}//end logger()

	/**
	 * A URL generator on http://nc.test.
	 *
	 * @return IURLGenerator
	 */
	private function urls(): IURLGenerator {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'http://nc.test' . $path);
		return $urls;
	}//end urls()
}//end class
