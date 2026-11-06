<?php

/**
 * Unit tests for XWikiService.
 *
 * Covers XML parsing, HTML sanitisation, content extraction and the
 * unconfigured-fallback paths.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/xwiki-integration/tasks.md#10.1
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
 * Tests for XWikiService.
 */
class XWikiServiceTest extends TestCase {
	/**
	 * Build a XWikiService with stub dependencies.
	 *
	 * By default the injected HTTP client THROWS on every request, which makes
	 * the OR-first search path return null (unavailable) and fall back to the
	 * legacy direct path — preserving the original test semantics. Pass a
	 * pre-wired $clientService to exercise the OR-first path explicitly.
	 *
	 * @param string $directUrl Direct URL override (empty disables fallback).
	 * @param IClientService|null $clientService Optional client-service override.
	 *
	 * @return XWikiService
	 */
	private function makeService(string $directUrl = '', ?IClientService $clientService = null): XWikiService {
		if ($clientService === null) {
			$clientService = $this->createMock(originalClassName: IClientService::class);
			$throwingClient = $this->createMock(originalClassName: IClient::class);
			$throwingClient->method('get')->willThrowException(new \RuntimeException('no http in unit test'));
			$clientService->method('newClient')->willReturn($throwingClient);
		}

		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$cacheFactory = $this->createMock(originalClassName: ICacheFactory::class);
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$urlGenerator = $this->createMock(originalClassName: IURLGenerator::class);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);

		$urlGenerator->method('getAbsoluteURL')
			->willReturnCallback(static fn (string $path): string => 'http://localhost' . $path);

		$cache = $this->createMock(originalClassName: ICache::class);
		// Empty cache: always miss → service must perform "fetch" path.
		$cache->method('get')->willReturn(null);
		$cacheFactory->method('createDistributed')->willReturn($cache);

		$appConfig->method('getValueString')
			->willReturnCallback(static function (string $app, string $key, string $default) use ($directUrl): string {
				if ($key === 'xwiki_direct_url') {
					return $directUrl;
				}
				if ($key === 'xwiki_cache_ttl') {
					return '300';
				}
				return $default;
			});

		// No OCA\Xwiki app registered in the container.
		$container->method('has')->willReturn(false);

		return new XWikiService(
			clientService: $clientService,
			appConfig: $appConfig,
			cacheFactory: $cacheFactory,
			container: $container,
			urlGenerator: $urlGenerator,
			logger: $logger
		);
	}//end makeService()

	/**
	 * Build a client-service mock whose single GET returns a canned response.
	 *
	 * @param int $status HTTP status code.
	 * @param string $body Response body.
	 *
	 * @return IClientService
	 */
	private function makeRespondingClientService(int $status, string $body): IClientService {
		$response = $this->createMock(originalClassName: IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn($body);

		$client = $this->createMock(originalClassName: IClient::class);
		$client->method('get')->willReturn($response);

		$clientService = $this->createMock(originalClassName: IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		return $clientService;
	}//end makeRespondingClientService()

	/**
	 * Unconfigured fallback should return empty result envelope without
	 * attempting HTTP.
	 *
	 * @return void
	 */
	public function testSearchUnconfiguredReturnsEmpty(): void {
		$service = $this->makeService(directUrl: '');
		$result = $service->search(query: 'q', filter: ['limit' => 5]);

		$this->assertSame(expected: [], actual: $result['results']);
		$this->assertSame(expected: 0, actual: $result['total']);
		$this->assertSame(expected: 5, actual: $result['limit']);
		$this->assertSame(expected: 0, actual: $result['offset']);
	}//end testSearchUnconfiguredReturnsEmpty()

	/**
	 * Pages endpoint with empty space should return empty envelope.
	 *
	 * @return void
	 */
	public function testGetPagesEmptySpaceReturnsEmpty(): void {
		$service = $this->makeService(directUrl: 'http://xwiki.example/xwiki');
		$result = $service->getPages('', 10, 0);

		$this->assertSame(expected: [], actual: $result['results']);
		$this->assertSame(expected: 0, actual: $result['total']);
	}//end testGetPagesEmptySpaceReturnsEmpty()

	/**
	 * Status without a base URL reports unconfigured.
	 *
	 * @return void
	 */
	public function testStatusUnconfigured(): void {
		$service = $this->makeService(directUrl: '');
		$status = $service->getStatus();

		$this->assertFalse(condition: $status['available']);
		$this->assertSame(expected: '', actual: $status['baseUrl']);
		$this->assertSame(expected: 'unconfigured', actual: $status['source']);
	}//end testStatusUnconfigured()

	/**
	 * parseSearchResults reads xWiki XML.
	 *
	 * @return void
	 */
	public function testParseSearchResults(): void {
		$service = $this->makeService(directUrl: '');
		$xml = '<?xml version="1.0"?><results>'
				. '<searchResult><id>xwiki:Kennisbank.Paspoort</id><title>Paspoort</title>'
				. '<space>Kennisbank</space><modified>2026-03-20T10:30:00Z</modified>'
				. '<pageUrl>http://x/p</pageUrl></searchResult>'
				. '</results>';

		$parsed = $service->parseSearchResults($xml);

		$this->assertCount(expectedCount: 1, haystack: $parsed);
		$this->assertSame(expected: 'Paspoort', actual: $parsed[0]['title']);
		$this->assertSame(expected: 'Kennisbank', actual: $parsed[0]['space']);
		$this->assertSame(expected: 'xwiki:Kennisbank.Paspoort', actual: $parsed[0]['id']);
	}//end testParseSearchResults()

	/**
	 * parseSearchResults returns empty on empty input.
	 *
	 * @return void
	 */
	public function testParseSearchResultsEmpty(): void {
		$service = $this->makeService(directUrl: '');
		$this->assertSame(expected: [], actual: $service->parseSearchResults(''));
		$this->assertSame(expected: [], actual: $service->parseSearchResults('not xml'));
	}//end testParseSearchResultsEmpty()

	/**
	 * parsePageSummaries reads xWiki XML.
	 *
	 * @return void
	 */
	public function testParsePageSummaries(): void {
		$service = $this->makeService(directUrl: '');
		$xml = '<?xml version="1.0"?><pages>'
				. '<pageSummary><id>1</id><title>Paspoort</title><modified>2026-03-20T10:30:00Z</modified>'
				. '<xwikiAbsoluteUrl>http://x/p</xwikiAbsoluteUrl></pageSummary>'
				. '<pageSummary><id>2</id><title>Verhuizing</title><modified>2026-03-22T10:30:00Z</modified>'
				. '<xwikiAbsoluteUrl>http://x/v</xwikiAbsoluteUrl></pageSummary>'
				. '</pages>';

		$parsed = $service->parsePageSummaries($xml, 'Kennisbank');

		$this->assertCount(expectedCount: 2, haystack: $parsed);
		$this->assertSame(expected: 'Paspoort', actual: $parsed[0]['title']);
		$this->assertSame(expected: 'Kennisbank', actual: $parsed[0]['space']);
		$this->assertSame(expected: 'Verhuizing', actual: $parsed[1]['title']);
	}//end testParsePageSummaries()

	/**
	 * parsePage returns metadata from XML page.
	 *
	 * @return void
	 */
	public function testParsePage(): void {
		$service = $this->makeService(directUrl: '');
		$xml = '<?xml version="1.0"?><page>'
				. '<id>Kennisbank.Paspoort</id><title>Paspoort</title>'
				. '<modified>2026-03-20T10:30:00Z</modified><space>Kennisbank</space>'
				. '<xwikiAbsoluteUrl>http://x/p</xwikiAbsoluteUrl></page>';

		$parsed = $service->parsePage($xml);

		$this->assertSame(expected: 'Paspoort', actual: $parsed['title']);
		$this->assertSame(expected: 'Kennisbank', actual: $parsed['space']);
		$this->assertSame(expected: 'Kennisbank.Paspoort', actual: $parsed['id']);
	}//end testParsePage()

	/**
	 * sanitiseHtml strips script tags, on-handlers, and javascript: URLs.
	 *
	 * @return void
	 */
	public function testSanitiseHtmlStripsDangerousMarkup(): void {
		$service = $this->makeService(directUrl: '');
		$html = '<p>Hello</p><script>alert(1)</script><a href="javascript:alert(2)" onclick="alert(3)">click</a>';

		$clean = $service->sanitiseHtml($html);

		$this->assertStringNotContainsString(needle: '<script', haystack: $clean);
		$this->assertStringNotContainsString(needle: 'javascript:', haystack: $clean);
		$this->assertStringNotContainsString(needle: 'onclick=', haystack: $clean);
		$this->assertStringContainsString(needle: 'Hello', haystack: $clean);
	}//end testSanitiseHtmlStripsDangerousMarkup()

	/**
	 * sanitiseHtml strips style blocks and single-quoted event handlers.
	 *
	 * @return void
	 */
	public function testSanitiseHtmlStripsStyleAndSingleQuotedHandlers(): void {
		$service = $this->makeService(directUrl: '');
		$html = "<style>body{color:red}</style><img src='x' onerror='alert(1)'>";

		$clean = $service->sanitiseHtml($html);

		$this->assertStringNotContainsString(needle: '<style', haystack: $clean);
		$this->assertStringNotContainsString(needle: 'onerror=', haystack: $clean);
	}//end testSanitiseHtmlStripsStyleAndSingleQuotedHandlers()

	/**
	 * extractAndSanitiseHtml extracts the xwikicontent fragment when present.
	 *
	 * @return void
	 */
	public function testExtractAndSanitiseHtmlExtractsFragment(): void {
		$service = $this->makeService(directUrl: '');
		$html = '<html><body>'
				. '<div id="xwikicontent" class="xwiki-content"><p>Body</p><script>alert(1)</script></div>'
				. '<div id="xwikidocfooter">footer</div>'
				. '</body></html>';

		$extracted = $service->extractAndSanitiseHtml($html);

		$this->assertStringContainsString(needle: '<p>Body</p>', haystack: $extracted);
		$this->assertStringNotContainsString(needle: 'footer', haystack: $extracted);
		$this->assertStringNotContainsString(needle: '<script', haystack: $extracted);
	}//end testExtractAndSanitiseHtmlExtractsFragment()

	/**
	 * extractAndSanitiseHtml is a no-op on empty input.
	 *
	 * @return void
	 */
	public function testExtractAndSanitiseHtmlEmpty(): void {
		$service = $this->makeService(directUrl: '');
		$this->assertSame(expected: '', actual: $service->extractAndSanitiseHtml(''));
	}//end testExtractAndSanitiseHtmlEmpty()

	/**
	 * OR-first: a 200 from the OpenRegister xWiki search endpoint is mapped to
	 * the widget result shape and returned WITHOUT touching the legacy direct
	 * path (pipelinq-xwiki-through-or).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pipelinq-xwiki-through-or/specs/xwiki-proxy/spec.md
	 */
	public function testSearchPrefersOpenRegisterOnSuccess(): void {
		$body = json_encode(
			[
				'results' => [
					['id' => 'Kennisbank.Paspoort', 'title' => 'Paspoort', 'space' => 'Kennisbank', 'url' => 'http://x/p', 'modified' => '2026-01-01'],
				],
				'total' => 1,
				'limit' => 5,
				'offset' => 0,
			]
		);
		// Direct URL is empty → if the OR path were NOT taken, the result would
		// be the empty envelope. A non-empty result proves OR-first was used.
		$service = $this->makeService(directUrl: '', clientService: $this->makeRespondingClientService(status: 200, body: $body));
		$result = $service->search(query: 'paspoort', filter: ['limit' => 5]);

		$this->assertCount(expectedCount: 1, haystack: $result['results']);
		$this->assertSame(expected: 'Paspoort', actual: $result['results'][0]['title']);
		$this->assertSame(expected: 'Kennisbank', actual: $result['results'][0]['space']);
		$this->assertSame(expected: 'Kennisbank.Paspoort', actual: $result['results'][0]['id']);
		$this->assertSame(expected: [], actual: $result['results'][0]['tags']);
	}//end testSearchPrefersOpenRegisterOnSuccess()

	/**
	 * OR-first space filter: results from OR are still passed through the shared
	 * client-side space filter (finishSearch), proving both paths share filtering.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pipelinq-xwiki-through-or/specs/xwiki-proxy/spec.md
	 */
	public function testSearchViaOpenRegisterAppliesSpaceFilter(): void {
		$body = json_encode(
			[
				'results' => [
					['id' => 'A.One', 'title' => 'One', 'space' => 'Kennisbank'],
					['id' => 'B.Two', 'title' => 'Two', 'space' => 'Other'],
				],
				'total' => 2,
				'limit' => 10,
				'offset' => 0,
			]
		);
		$service = $this->makeService(directUrl: '', clientService: $this->makeRespondingClientService(status: 200, body: $body));
		$result = $service->search(query: 'q', filter: ['space' => 'Kennisbank']);

		$this->assertCount(expectedCount: 1, haystack: $result['results']);
		$this->assertSame(expected: 'Kennisbank', actual: $result['results'][0]['space']);
	}//end testSearchViaOpenRegisterAppliesSpaceFilter()

	/**
	 * Safe-partial fallback: when OR returns 503 (source dormant) the HTTP client
	 * throws, the OR path returns null, and search() falls back to the legacy
	 * direct path. With an empty direct URL that yields the empty envelope —
	 * proving a configured env with a real direct URL would keep working (no
	 * regression).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pipelinq-xwiki-through-or/specs/xwiki-proxy/spec.md
	 */
	public function testSearchFallsBackToDirectPathWhenOrUnavailable(): void {
		// Default makeService injects a throwing client (simulates OR 503 / absent)
		// AND an empty direct URL → empty envelope via the legacy fallback path.
		$service = $this->makeService(directUrl: '');
		$result = $service->search(query: 'q', filter: ['limit' => 7]);

		$this->assertSame(expected: [], actual: $result['results']);
		$this->assertSame(expected: 0, actual: $result['total']);
		$this->assertSame(expected: 7, actual: $result['limit']);
	}//end testSearchFallsBackToDirectPathWhenOrUnavailable()

	/**
	 * A non-200 (e.g. 500) from OR also triggers the legacy fallback rather than
	 * surfacing a broken result.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pipelinq-xwiki-through-or/specs/xwiki-proxy/spec.md
	 */
	public function testSearchFallsBackOnNon200(): void {
		$body = '{"error":"x","details":{"cause":"upstream-service-down"}}';
		$service = $this->makeService(directUrl: '', clientService: $this->makeRespondingClientService(status: 503, body: $body));
		$result = $service->search(query: 'q', filter: ['limit' => 5]);

		// Direct URL empty → fallback yields empty envelope, no fatal.
		$this->assertSame(expected: [], actual: $result['results']);
		$this->assertSame(expected: 0, actual: $result['total']);
	}//end testSearchFallsBackOnNon200()
}//end class
