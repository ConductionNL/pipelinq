<?php

/**
 * Unit tests for XWikiService.
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
 *
 * @spec openspec/changes/xwiki-integration/tasks.md#task-10.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\XWikiService;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for XWikiService.
 *
 * @spec openspec/changes/xwiki-integration/tasks.md#task-10.1
 */
class XWikiServiceTest extends TestCase
{

    /**
     * The service under test.
     *
     * @var XWikiService
     */
    private XWikiService $service;

    /**
     * Mock app config.
     *
     * @var IAppConfig
     */
    private IAppConfig $appConfig;

    /**
     * Mock cache factory (unavailable by default to simplify tests).
     *
     * @var ICacheFactory
     */
    private ICacheFactory $cacheFactory;

    /**
     * Mock HTTP client service.
     *
     * @var IClientService
     */
    private IClientService $httpClient;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->appConfig    = $this->createMock(IAppConfig::class);
        $this->cacheFactory = $this->createMock(ICacheFactory::class);
        $this->httpClient   = $this->createMock(IClientService::class);
        $logger             = $this->createMock(LoggerInterface::class);

        // Disable cache by default so tests exercise the HTTP path.
        $this->cacheFactory->method('isAvailable')->willReturn(false);

        $this->service = new XWikiService(
            $this->httpClient,
            $this->appConfig,
            $this->cacheFactory,
            $logger,
        );
    }//end setUp()

    // -------------------------------------------------------------------------
    // getBaseUrl
    // -------------------------------------------------------------------------

    /**
     * getBaseUrl returns empty string when not configured.
     *
     * @return void
     */
    public function testGetBaseUrlReturnsEmptyWhenNotConfigured(): void
    {
        $this->appConfig->method('getValueString')->willReturn('');

        $result = $this->service->getBaseUrl();

        $this->assertSame('', $result);
    }//end testGetBaseUrlReturnsEmptyWhenNotConfigured()

    /**
     * getBaseUrl strips trailing slash.
     *
     * @return void
     */
    public function testGetBaseUrlStripsTrailingSlash(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturnCallback(static function (string $app, string $key, string $default): string {
                if ($key === 'xwiki_direct_url') {
                    return 'http://localhost:8088/';
                }
                return $default;
            });

        $result = $this->service->getBaseUrl();

        $this->assertSame('http://localhost:8088', $result);
    }//end testGetBaseUrlStripsTrailingSlash()

    // -------------------------------------------------------------------------
    // sanitizeHtml
    // -------------------------------------------------------------------------

    /**
     * sanitizeHtml removes script tags.
     *
     * @return void
     */
    public function testSanitizeHtmlRemovesScriptTags(): void
    {
        $html   = '<p>Hello</p><script>alert("xss")</script><p>World</p>';
        $result = $this->service->sanitizeHtml($html, '');

        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringNotContainsString('alert("xss")', $result);
        $this->assertStringContainsString('<p>Hello</p>', $result);
        $this->assertStringContainsString('<p>World</p>', $result);
    }//end testSanitizeHtmlRemovesScriptTags()

    /**
     * sanitizeHtml removes inline event handlers.
     *
     * @return void
     */
    public function testSanitizeHtmlRemovesInlineEventHandlers(): void
    {
        $html   = '<img src="img.png" onerror="alert(1)">';
        $result = $this->service->sanitizeHtml($html, '');

        $this->assertStringNotContainsString('onerror', $result);
        $this->assertStringContainsString('<img', $result);
    }//end testSanitizeHtmlRemovesInlineEventHandlers()

    /**
     * sanitizeHtml rewrites relative image src to absolute.
     *
     * @return void
     */
    public function testSanitizeHtmlRewritesRelativeImageSrc(): void
    {
        $html   = '<img src="/xwiki/images/logo.png">';
        $result = $this->service->sanitizeHtml($html, 'http://localhost:8088');

        $this->assertStringContainsString('http://localhost:8088/', $result);
    }//end testSanitizeHtmlRewritesRelativeImageSrc()

    /**
     * sanitizeHtml preserves safe HTML elements.
     *
     * @return void
     */
    public function testSanitizeHtmlPreservesSafeElements(): void
    {
        $html = '<h1>Title</h1><p>Paragraph</p><a href="http://safe.example">Link</a><ul><li>Item</li></ul>';

        $result = $this->service->sanitizeHtml($html, '');

        $this->assertStringContainsString('<h1>', $result);
        $this->assertStringContainsString('<p>', $result);
        $this->assertStringContainsString('<a href="http://safe.example">', $result);
        $this->assertStringContainsString('<ul>', $result);
    }//end testSanitizeHtmlPreservesSafeElements()

    /**
     * sanitizeHtml strips javascript: hrefs.
     *
     * @return void
     */
    public function testSanitizeHtmlStripsJavascriptHrefs(): void
    {
        $html   = '<a href="javascript:void(0)">Click</a>';
        $result = $this->service->sanitizeHtml($html, '');

        $this->assertStringNotContainsString('javascript:', $result);
    }//end testSanitizeHtmlStripsJavascriptHrefs()

    // -------------------------------------------------------------------------
    // getStatus
    // -------------------------------------------------------------------------

    /**
     * getStatus returns unavailable when URL is not configured.
     *
     * @return void
     */
    public function testGetStatusReturnsUnavailableWhenNotConfigured(): void
    {
        $this->appConfig->method('getValueString')->willReturn('');

        $result = $this->service->getStatus();

        $this->assertFalse($result['available']);
        $this->assertStringContainsString('not configured', $result['error']);
    }//end testGetStatusReturnsUnavailableWhenNotConfigured()

    /**
     * getStatus returns unavailable when HTTP request fails.
     *
     * @return void
     */
    public function testGetStatusReturnsUnavailableOnHttpFailure(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturnCallback(static function (string $app, string $key, string $default): string {
                if ($key === 'xwiki_direct_url') {
                    return 'http://localhost:8088';
                }
                return $default;
            });

        $client = $this->createMock(\OCP\Http\Client\IClient::class);
        $client->method('get')->willThrowException(new \Exception('Connection refused'));
        $this->httpClient->method('newClient')->willReturn($client);

        $result = $this->service->getStatus();

        $this->assertFalse($result['available']);
        $this->assertStringContainsString('Could not reach xWiki', $result['error']);
    }//end testGetStatusReturnsUnavailableOnHttpFailure()

    // -------------------------------------------------------------------------
    // search
    // -------------------------------------------------------------------------

    /**
     * search returns error result when URL is not configured.
     *
     * @return void
     */
    public function testSearchReturnsErrorWhenNotConfigured(): void
    {
        $this->appConfig->method('getValueString')->willReturn('');

        $result = $this->service->search('paspoort');

        $this->assertSame([], $result['results']);
        $this->assertArrayHasKey('error', $result);
    }//end testSearchReturnsErrorWhenNotConfigured()

    /**
     * search returns empty results on HTTP failure with graceful error message.
     *
     * @return void
     */
    public function testSearchReturnsEmptyResultsOnHttpFailure(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturnCallback(static function (string $app, string $key, string $default): string {
                if ($key === 'xwiki_direct_url') {
                    return 'http://localhost:8088';
                }
                return $default;
            });

        $client = $this->createMock(\OCP\Http\Client\IClient::class);
        $client->method('get')->willThrowException(new \Exception('Timeout'));
        $this->httpClient->method('newClient')->willReturn($client);

        $result = $this->service->search('paspoort');

        $this->assertSame([], $result['results']);
        $this->assertSame(0, $result['total']);
    }//end testSearchReturnsEmptyResultsOnHttpFailure()

    // -------------------------------------------------------------------------
    // getPages
    // -------------------------------------------------------------------------

    /**
     * getPages returns error result when space is empty and URL not configured.
     *
     * @return void
     */
    public function testGetPagesReturnsErrorWhenNotConfigured(): void
    {
        $this->appConfig->method('getValueString')->willReturn('');

        $result = $this->service->getPages('Kennisbank');

        $this->assertSame([], $result['results']);
        $this->assertArrayHasKey('error', $result);
    }//end testGetPagesReturnsErrorWhenNotConfigured()

    // -------------------------------------------------------------------------
    // caching
    // -------------------------------------------------------------------------

    /**
     * When cache is available, search uses the cache.
     *
     * @return void
     */
    public function testSearchUsesCacheWhenAvailable(): void
    {
        $this->cacheFactory = $this->createMock(ICacheFactory::class);
        $this->cacheFactory->method('isAvailable')->willReturn(true);

        $cache = $this->createMock(\OCP\ICache::class);
        $cache->method('get')->willReturn([
            'results' => [['id' => 'xwiki:KB.Test', 'title' => 'Test', 'space' => 'KB', 'modified' => '', 'url' => '']],
            'total'   => 1,
            'limit'   => 10,
            'offset'  => 0,
        ]);
        $this->cacheFactory->method('createDistributed')->willReturn($cache);

        $logger = $this->createMock(LoggerInterface::class);
        $service = new XWikiService(
            $this->httpClient,
            $this->appConfig,
            $this->cacheFactory,
            $logger,
        );

        // HTTP should NOT be called because cache returns a value.
        $this->httpClient->expects($this->never())->method('newClient');

        $result = $service->search('test');

        $this->assertCount(1, $result['results']);
        $this->assertSame('HIT', $result['x_cache']);
    }//end testSearchUsesCacheWhenAvailable()
}//end class
