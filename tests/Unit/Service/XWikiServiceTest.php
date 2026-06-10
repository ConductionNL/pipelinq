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
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for XWikiService.
 */
class XWikiServiceTest extends TestCase
{
    /**
     * Build a XWikiService with stub dependencies.
     *
     * @param string $directUrl Direct URL override (empty disables fallback).
     *
     * @return XWikiService
     */
    private function makeService(string $directUrl = ''): XWikiService
    {
        $clientService = $this->createMock(IClientService::class);
        $appConfig     = $this->createMock(IAppConfig::class);
        $cacheFactory  = $this->createMock(ICacheFactory::class);
        $container     = $this->createMock(ContainerInterface::class);
        $logger        = $this->createMock(LoggerInterface::class);

        $cache = $this->createMock(ICache::class);
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

        return new XWikiService($clientService, $appConfig, $cacheFactory, $container, $logger);
    }//end makeService()

    /**
     * Unconfigured fallback should return empty result envelope without
     * attempting HTTP.
     *
     * @return void
     */
    public function testSearchUnconfiguredReturnsEmpty(): void
    {
        $service = $this->makeService('');
        $result  = $service->search('q', null, [], 5, 0);

        $this->assertSame([], $result['results']);
        $this->assertSame(0, $result['total']);
        $this->assertSame(5, $result['limit']);
        $this->assertSame(0, $result['offset']);
    }//end testSearchUnconfiguredReturnsEmpty()

    /**
     * Pages endpoint with empty space should return empty envelope.
     *
     * @return void
     */
    public function testGetPagesEmptySpaceReturnsEmpty(): void
    {
        $service = $this->makeService('http://xwiki.example/xwiki');
        $result  = $service->getPages('', 10, 0);

        $this->assertSame([], $result['results']);
        $this->assertSame(0, $result['total']);
    }//end testGetPagesEmptySpaceReturnsEmpty()

    /**
     * Status without a base URL reports unconfigured.
     *
     * @return void
     */
    public function testStatusUnconfigured(): void
    {
        $service = $this->makeService('');
        $status  = $service->getStatus();

        $this->assertFalse($status['available']);
        $this->assertSame('', $status['baseUrl']);
        $this->assertSame('unconfigured', $status['source']);
    }//end testStatusUnconfigured()

    /**
     * parseSearchResults reads xWiki XML.
     *
     * @return void
     */
    public function testParseSearchResults(): void
    {
        $service = $this->makeService('');
        $xml     = '<?xml version="1.0"?><results>'
                . '<searchResult><id>xwiki:Kennisbank.Paspoort</id><title>Paspoort</title>'
                . '<space>Kennisbank</space><modified>2026-03-20T10:30:00Z</modified>'
                . '<pageUrl>http://x/p</pageUrl></searchResult>'
                . '</results>';

        $parsed = $service->parseSearchResults($xml);

        $this->assertCount(1, $parsed);
        $this->assertSame('Paspoort', $parsed[0]['title']);
        $this->assertSame('Kennisbank', $parsed[0]['space']);
        $this->assertSame('xwiki:Kennisbank.Paspoort', $parsed[0]['id']);
    }//end testParseSearchResults()

    /**
     * parseSearchResults returns empty on empty input.
     *
     * @return void
     */
    public function testParseSearchResultsEmpty(): void
    {
        $service = $this->makeService('');
        $this->assertSame([], $service->parseSearchResults(''));
        $this->assertSame([], $service->parseSearchResults('not xml'));
    }//end testParseSearchResultsEmpty()

    /**
     * parsePageSummaries reads xWiki XML.
     *
     * @return void
     */
    public function testParsePageSummaries(): void
    {
        $service = $this->makeService('');
        $xml     = '<?xml version="1.0"?><pages>'
                . '<pageSummary><id>1</id><title>Paspoort</title><modified>2026-03-20T10:30:00Z</modified>'
                . '<xwikiAbsoluteUrl>http://x/p</xwikiAbsoluteUrl></pageSummary>'
                . '<pageSummary><id>2</id><title>Verhuizing</title><modified>2026-03-22T10:30:00Z</modified>'
                . '<xwikiAbsoluteUrl>http://x/v</xwikiAbsoluteUrl></pageSummary>'
                . '</pages>';

        $parsed = $service->parsePageSummaries($xml, 'Kennisbank');

        $this->assertCount(2, $parsed);
        $this->assertSame('Paspoort', $parsed[0]['title']);
        $this->assertSame('Kennisbank', $parsed[0]['space']);
        $this->assertSame('Verhuizing', $parsed[1]['title']);
    }//end testParsePageSummaries()

    /**
     * parsePage returns metadata from XML page.
     *
     * @return void
     */
    public function testParsePage(): void
    {
        $service = $this->makeService('');
        $xml     = '<?xml version="1.0"?><page>'
                . '<id>Kennisbank.Paspoort</id><title>Paspoort</title>'
                . '<modified>2026-03-20T10:30:00Z</modified><space>Kennisbank</space>'
                . '<xwikiAbsoluteUrl>http://x/p</xwikiAbsoluteUrl></page>';

        $parsed = $service->parsePage($xml);

        $this->assertSame('Paspoort', $parsed['title']);
        $this->assertSame('Kennisbank', $parsed['space']);
        $this->assertSame('Kennisbank.Paspoort', $parsed['id']);
    }//end testParsePage()

    /**
     * sanitiseHtml strips script tags, on-handlers, and javascript: URLs.
     *
     * @return void
     */
    public function testSanitiseHtmlStripsDangerousMarkup(): void
    {
        $service = $this->makeService('');
        $html    = '<p>Hello</p><script>alert(1)</script><a href="javascript:alert(2)" onclick="alert(3)">click</a>';

        $clean = $service->sanitiseHtml($html);

        $this->assertStringNotContainsString('<script', $clean);
        $this->assertStringNotContainsString('javascript:', $clean);
        $this->assertStringNotContainsString('onclick=', $clean);
        $this->assertStringContainsString('Hello', $clean);
    }//end testSanitiseHtmlStripsDangerousMarkup()

    /**
     * sanitiseHtml strips style blocks and single-quoted event handlers.
     *
     * @return void
     */
    public function testSanitiseHtmlStripsStyleAndSingleQuotedHandlers(): void
    {
        $service = $this->makeService('');
        $html    = "<style>body{color:red}</style><img src='x' onerror='alert(1)'>";

        $clean = $service->sanitiseHtml($html);

        $this->assertStringNotContainsString('<style', $clean);
        $this->assertStringNotContainsString('onerror=', $clean);
    }//end testSanitiseHtmlStripsStyleAndSingleQuotedHandlers()

    /**
     * extractAndSanitiseHtml extracts the xwikicontent fragment when present.
     *
     * @return void
     */
    public function testExtractAndSanitiseHtmlExtractsFragment(): void
    {
        $service = $this->makeService('');
        $html    = '<html><body>'
                . '<div id="xwikicontent" class="xwiki-content"><p>Body</p><script>alert(1)</script></div>'
                . '<div id="xwikidocfooter">footer</div>'
                . '</body></html>';

        $extracted = $service->extractAndSanitiseHtml($html);

        $this->assertStringContainsString('<p>Body</p>', $extracted);
        $this->assertStringNotContainsString('footer', $extracted);
        $this->assertStringNotContainsString('<script', $extracted);
    }//end testExtractAndSanitiseHtmlExtractsFragment()

    /**
     * extractAndSanitiseHtml is a no-op on empty input.
     *
     * @return void
     */
    public function testExtractAndSanitiseHtmlEmpty(): void
    {
        $service = $this->makeService('');
        $this->assertSame('', $service->extractAndSanitiseHtml(''));
    }//end testExtractAndSanitiseHtmlEmpty()
}//end class
