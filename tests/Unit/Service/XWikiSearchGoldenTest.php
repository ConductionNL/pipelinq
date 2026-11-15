<?php

/**
 * Golden test for the xWiki search proxy (XWikiController::search and XWikiService::search).
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
 *
 * @spec openspec/specs/xwiki-proxy/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Controller\XWikiController;
use OCA\Pipelinq\Service\XWikiService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The search response, every HTTP GET (URL and options) and every cache read
 * and write, for request parameters, OpenRegister answers, direct xWiki
 * answers and cache states, compared with XWikiSearchGolden.json. Set
 * XWIKI_SEARCH_GOLDEN_RECORD=1 to rewrite the file, then run once more
 * without it.
 *
 * @covers \OCA\Pipelinq\Service\XWikiService
 * @covers \OCA\Pipelinq\Controller\XWikiController
 * @uses   \OCA\Pipelinq\AppInfo\OpenRegisterAutoloader
 *
 * @spec openspec/specs/xwiki-proxy/spec.md
 */
class XWikiSearchGoldenTest extends TestCase {
	private const GOLDEN = __DIR__ . '/XWikiSearchGolden.json';

	private const SETTINGS_MANAGER = 'OCA\Xwiki\SettingsManager';

	private const XML = '<searchResults><searchResult><id>Kennisbank.A</id><title>A</title><space>Kennisbank</space>'
		. '<modified>2026-01-01</modified><pageUrl>http://wiki/a</pageUrl></searchResult>'
		. '<searchResult><id>Other.B</id><space>Other</space></searchResult></searchResults>';

	/**
	 * Calls made in the current case.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $calls = [];

	/**
	 * Run every case and compare with the golden file.
	 *
	 * @return void
	 */
	public function testGolden(): void {
		if (class_exists(self::SETTINGS_MANAGER) === false) {
			class_alias(get_class(new class {
			}), self::SETTINGS_MANAGER);
		}

		$actual = [];
		foreach ($this->cases() as $name => $case) {
			$this->calls = [];
			$response = $this->controller(case: $case)->search();
			$actual[$name] = ['response' => $response->getData(), 'calls' => $this->calls];
		}

		$flags = (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if (getenv('XWIKI_SEARCH_GOLDEN_RECORD') !== false) {
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
	 * The cases: request parameters, the OpenRegister answer, the direct base URL and answer, and the cache.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		$rows = [
			['id' => 'Kennisbank.A', 'title' => 'A', 'space' => 'Kennisbank', 'tags' => ['woo', 'paspoort'],
				'url' => 'http://x/a', 'modified' => '2026-01-01',
			],
			['reference' => 'Other.B', 'space' => 'Other', 'tags' => ['woo']],
			['id' => 'Kennisbank.C', 'space' => 'Kennisbank', 'tags' => 'notalist'],
			'not a row',
			['title' => 'D', 'space' => 'Kennisbank', 'tags' => ['rijbewijs']],
		];
		$ok = ['status' => 200, 'body' => json_encode(['results' => $rows])];
		$down = ['throw' => true];
		$wiki = ['base' => 'http://wiki', 'xml' => self::XML];
		return [
			'or plain'                  => ['params' => ['q' => 'paspoort'], 'or' => $ok],
			'or space'                  => ['params' => ['q' => 'x', 'space' => 'Kennisbank'], 'or' => $ok],
			'or tags'                   => ['params' => ['q' => 'x', 'tags' => ' woo , ,rijbewijs '], 'or' => $ok],
			'or space and tags'         => ['params' => ['q' => 'x', 'space' => 'Kennisbank', 'tags' => 'paspoort'], 'or' => $ok],
			'or limit and offset'       => ['params' => ['q' => 'x', 'limit' => '2', 'offset' => '3'], 'or' => $ok],
			'or limit clamped high'     => ['params' => ['q' => 'x', 'limit' => '500', 'offset' => '-4'], 'or' => $ok],
			'or limit clamped low'      => ['params' => ['q' => 'x', 'limit' => '0'], 'or' => $ok],
			'or empty space ignored'    => ['params' => ['q' => 'x', 'space' => ''], 'or' => $ok],
			'or no results key'         => ['params' => ['q' => 'x'], 'or' => ['status' => 200, 'body' => '{"items":[]}'], ...$wiki],
			'or results not a list'     => ['params' => ['q' => 'x'], 'or' => ['status' => 200, 'body' => '{"results":"x"}']],
			'or bad json'               => ['params' => ['q' => 'x'], 'or' => ['status' => 200, 'body' => 'nope']],
			'or 503 then direct'        => [
				'params' => ['q' => 'woo', 'space' => 'Kennisbank', 'limit' => '1'],
				'or' => ['status' => 503, 'body' => '{}'], ...$wiki, 'base' => 'http://wiki/',
			],
			'or throws then direct'     => ['params' => ['q' => 'woo', 'offset' => '5'], 'or' => $down, ...$wiki],
			'direct tags filter all'    => ['params' => ['q' => 'woo', 'tags' => 'woo'], 'or' => $down, ...$wiki],
			'direct fetch throws'       => ['params' => ['q' => 'woo'], 'or' => ['throw' => true], 'base' => 'http://wiki', 'xml' => null],
			'direct bad xml'            => ['params' => ['q' => 'woo'], 'or' => ['throw' => true], 'base' => 'http://wiki', 'xml' => '<broken'],
			'no direct base'            => ['params' => ['q' => 'woo', 'limit' => '4', 'offset' => '2'], 'or' => ['throw' => true]],
			'cache hit string'          => ['params' => ['q' => 'x'], 'or' => $ok, 'cached' => '{"results":[],"total":9,"limit":1,"offset":0}'],
			'cache hit array'           => ['params' => ['q' => 'x'], 'or' => $ok, 'cached' => ['results' => [], 'total' => 8]],
			'cache bad string'          => ['params' => ['q' => 'x'], 'or' => $ok, 'cached' => 'not json'],
			'missing query'             => ['params' => [], 'or' => $ok],
			'space not a string'        => ['params' => ['q' => 'x', 'space' => ['a']], 'or' => $ok],
			'cache ttl from config'     => ['params' => ['q' => 'x'], 'or' => $ok, 'ttl' => '42'],
		];
	}//end cases()

	/**
	 * A controller over a service wired for one case.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return XWikiController
	 */
	private function controller(array $case): XWikiController {
		$client = $this->createMock(originalClassName: IClient::class);
		$client->method('get')->willReturnCallback(
			function (string $url, array $options) use ($case): IResponse {
				$this->calls[] = ['get' => $url, 'options' => $options];
				$answer = ['status' => 200, 'body' => (string)($case['xml'] ?? '')];
				if (str_contains($url, '/apps/openregister/') === true) {
					$answer = $case['or'];
				}

				if (($answer['throw'] ?? false) === true || (str_contains($url, '/apps/openregister/') === false && ($case['xml'] ?? null) === null)) {
					throw new RuntimeException('http down');
				}

				$response = $this->createMock(originalClassName: IResponse::class);
				$response->method('getStatusCode')->willReturn($answer['status']);
				$response->method('getBody')->willReturn($answer['body']);
				return $response;
			}
		);
		$clientService = $this->createMock(originalClassName: IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$cache = $this->createMock(originalClassName: ICache::class);
		$cache->method('get')->willReturnCallback(
			function (string $key) use ($case): mixed {
				$this->calls[] = ['cacheGet' => $key];
				return ($case['cached'] ?? null);
			}
		);
		$cache->method('set')->willReturnCallback(
			function (string $key, mixed $value, int $ttl): bool {
				$this->calls[] = ['cacheSet' => $key, 'value' => $value, 'ttl' => $ttl];
				return true;
			}
		);
		$cacheFactory = $this->createMock(originalClassName: ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($cache);

		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => (['xwiki_cache_ttl' => ($case['ttl'] ?? $default)][$key] ?? $default)
		);

		$base = (string)($case['base'] ?? '');
		$manager = new class($base) {
			/**
			 * @param string $base The base URL.
			 */
			public function __construct(private string $base) {
			}

			/**
			 * @return string
			 */
			public function getBaseUrl(): string {
				return $this->base;
			}
		};
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('has')->willReturn($base !== '');
		$container->method('get')->willReturn($manager);

		$urls = $this->createMock(originalClassName: IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://cloud.example' . $path);

		$service = new XWikiService(
			clientService: $clientService,
			appConfig: $appConfig,
			cacheFactory: $cacheFactory,
			container: $container,
			urlGenerator: $urls,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);

		$params = $case['params'];
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default));

		return new XWikiController(request: $request, xwiki: $service, logger: $this->createMock(originalClassName: LoggerInterface::class));
	}//end controller()
}//end class
