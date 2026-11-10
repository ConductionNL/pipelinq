<?php

/**
 * Golden test for XWikiService::getPageContent, ::getStatus and ::getPages.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\XWikiService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Pins the URLs fetched, the options sent, the cache reads and writes, the debug
 * lines and the results of the page, status and page-list reads.
 */
class XWikiPageStatusGoldenTest extends TestCase {
	/**
	 * The calls made, in order.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $trace = [];

	/**
	 * Bodies by URL; a URL that is absent throws.
	 *
	 * @var array<string, string>
	 */
	private array $bodies = [];

	/**
	 * Cached values by key.
	 *
	 * @var array<string, string>
	 */
	private array $cached = [];

	/**
	 * A page read: the REST page, the rendered view, the cache write; a cache hit; unconfigured and empty pages.
	 *
	 * @return void
	 */
	public function testPageContent(): void {
		$this->bodies = [
			'https://wiki.test/rest/wikis/main%20wiki/spaces/Kennis%20bank/spaces/Pas/pages/WebHome?media=xml' => '<page><id>main:Kennis bank.Pas.WebHome</id><title>Paspoort</title><modified>2026-01-01</modified></page>',
			'https://wiki.test/bin/view/Kennis%20bank/Pas/WebHome' => '<html><body><div id="xwikicontent"><p onclick="x()">Hallo</p><script>bad()</script></div></body></html>',
		];
		$page = $this->service(base: 'https://wiki.test')->getPageContent(' main wiki ', ' Kennis bank.Pas.WebHome ');
		if (getenv('XWIKI_PAGE_GOLDEN_RECORD') !== false) {
			file_put_contents(__DIR__ . '/XWikiPageStatusGolden.json', json_encode(['page' => ['page' => $page, 'calls' => $this->trace]], (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n");
		}

		$this->assertSame(
			['page' => $page, 'calls' => $this->trace],
			json_decode((string)file_get_contents(__DIR__ . '/XWikiPageStatusGolden.json'), true)['page']
		);

		$this->trace = [];
		$this->cached = [array_key_first($this->cached) => json_encode(['title' => 'cached'])];
		$this->assertSame(['title' => 'cached'], $this->service(base: 'https://wiki.test')->getPageContent('main wiki', 'Kennis bank.Pas.WebHome'));

		$this->cached = [];
		$empty = ['title' => '', 'content' => '', 'url' => '', 'modified' => '', 'space' => '', 'id' => ''];
		$this->assertSame($empty, $this->service(base: '')->getPageContent('xwiki', 'A.B'));
		$this->assertSame($empty, $this->service(base: 'https://wiki.test')->getPageContent('', '  '));

		$this->bodies = [];
		$this->trace = [];
		$failed = $this->service(base: 'https://wiki.test')->getPageContent('', 'Solo');
		$this->assertSame(['space' => 'Solo', 'content' => '', 'url' => 'https://wiki.test/bin/view/Solo'], array_intersect_key($failed, ['url' => 1, 'space' => 1, 'content' => 1]));
		$gets = array_values(array_filter($this->trace, static fn (array $call): bool => $call[0] === 'get'));
		$this->assertSame(['https://wiki.test/rest/wikis/xwiki/spaces//pages/Solo?media=xml', 'https://wiki.test/bin/view/Solo'], array_column($gets, 1));
	}//end testPageContent()

	/**
	 * Status: unconfigured, reachable (version read, then memoised), unreachable (memoised).
	 *
	 * @return void
	 */
	public function testStatus(): void {
		$this->assertSame(['available' => false, 'version' => '', 'baseUrl' => '', 'source' => 'unconfigured'], $this->service(base: '')->getStatus());

		$this->bodies = ['https://wiki.test/rest' => '<xwiki><version>16.4.1</version></xwiki>'];
		$service = $this->service(base: 'https://wiki.test');
		$this->assertSame(['available' => true, 'version' => '16.4.1', 'baseUrl' => 'https://wiki.test', 'source' => 'xwiki-app'], $service->getStatus());
		$this->assertSame(['available' => true, 'version' => '', 'baseUrl' => 'https://wiki.test', 'source' => 'xwiki-app'], $service->getStatus());

		$this->bodies = ['https://wiki.test/rest' => '<xwiki/>'];
		$this->assertSame(['available' => true, 'version' => '', 'baseUrl' => 'https://wiki.test', 'source' => 'xwiki-app'], $this->service(base: 'https://wiki.test')->getStatus());

		$this->bodies = [];
		$down = $this->service(base: 'https://wiki.test');
		$this->assertSame(['available' => false, 'version' => '', 'baseUrl' => 'https://wiki.test', 'source' => 'xwiki-app'], $down->getStatus());
		$this->assertSame(['available' => false, 'version' => '', 'baseUrl' => 'https://wiki.test', 'source' => 'xwiki-app'], $down->getStatus());
		$this->assertSame(
			[
				['get', 'https://wiki.test/rest', ['timeout' => 5, 'connect_timeout' => 3]],
				['get', 'https://wiki.test/rest', ['timeout' => 5, 'connect_timeout' => 3]],
				['get', 'https://wiki.test/rest', ['timeout' => 5, 'connect_timeout' => 3]],
				['debug', 'xWiki status check failed', ['exception' => 'RuntimeException']],
			],
			array_values(array_filter($this->trace, static fn (array $call): bool => $call[0] !== 'newClient'))
		);
	}//end testStatus()

	/**
	 * Page list: clamping, the REST URL, the cache write; a failed fetch is an empty list.
	 *
	 * @return void
	 */
	public function testPages(): void {
		$this->bodies = ['https://wiki.test/rest/wikis/xwiki/spaces/Kennis%20bank/pages?media=xml&number=100&start=0' => '<pages><pageSummary><id>p1</id><title>T</title><modified>m</modified><xwikiAbsoluteUrl>u</xwikiAbsoluteUrl></pageSummary></pages>'];
		$this->assertSame(
			['results' => [['id' => 'p1', 'title' => 'T', 'space' => 'Kennis bank', 'modified' => 'm', 'url' => 'u', 'tags' => []]], 'total' => 1, 'limit' => 100, 'offset' => 0],
			$this->service(base: 'https://wiki.test')->getPages('Kennis bank', 500, -3)
		);
		$this->assertSame(['results' => [], 'total' => 0, 'limit' => 1, 'offset' => 2], $this->service(base: 'https://wiki.test')->getPages('Other', 0, 2));
		$this->assertSame(['debug', 'xWiki XML fetch failed', ['url' => 'https://wiki.test/rest/wikis/xwiki/spaces/Other/pages?media=xml&number=1&start=2', 'exception' => 'RuntimeException']], $this->trace[count($this->trace) - 2]);
	}//end testPages()

	/**
	 * A service on recording doubles, with the xWiki app reporting the base URL.
	 *
	 * @param string $base The base URL the xWiki app reports.
	 *
	 * @return XWikiService
	 */
	private function service(string $base): XWikiService {
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(
			function (string $uri, array $options = []): IResponse {
				$this->trace[] = ['get', $uri, $options];
				if (isset($this->bodies[$uri]) === false) {
					throw new \RuntimeException('unreachable');
				}

				$response = $this->createMock(IResponse::class);
				$response->method('getStatusCode')->willReturn(200);
				$response->method('getBody')->willReturn($this->bodies[$uri]);
				return $response;
			}
		);
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturnCallback(
			function () use ($client): IClient {
				$this->trace[] = ['newClient'];
				return $client;
			}
		);

		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(
			function (string $key): mixed {
				$this->trace[] = ['cache get'];
				return ($this->cached[$key] ?? null);
			}
		);
		$cache->method('set')->willReturnCallback(
			function (string $key, mixed $value, int $ttl = 0): bool {
				$this->trace[] = ['cache set', json_decode($value, true), $ttl];
				$this->cached[$key] = $value;
				return true;
			}
		);
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($cache);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'xwiki_cache_ttl' => '300',
				default => $default,
			}
		);
		$manager = new \OCA\Xwiki\SettingsManager(baseUrl: $base);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturn(true);
		$container->method('get')->willReturn($manager);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('debug')->willReturnCallback(
			function (string $message, array $context = []): void {
				if (isset($context['exception']) === true) {
					$context['exception'] = get_class($context['exception']);
				}

				$this->trace[] = ['debug', $message, $context];
			}
		);

		return new XWikiService(
			clientService: $clients,
			appConfig: $appConfig,
			cacheFactory: $cacheFactory,
			container: $container,
			urlGenerator: $this->createMock(IURLGenerator::class),
			logger: $logger
		);
	}//end service()
}//end class

namespace OCA\Xwiki;

if (class_exists(SettingsManager::class) === false) {
	/**
	 * Stand-in for the xWiki app's settings manager, which reports the base URL.
	 */
	class SettingsManager {
		/**
		 * @param string $baseUrl The base URL to report.
		 */
		public function __construct(private string $baseUrl = '') {
		}

		/**
		 * @return string The base URL.
		 */
		public function getBaseUrl(): string {
			return $this->baseUrl;
		}
	}
}
