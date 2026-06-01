<?php

/**
 * Pipelinq XWikiService.
 *
 * Service for interacting with the xWiki REST API. Wraps the nextcloud/xwiki
 * app's Instance/SettingsManager when available, falling back to the direct
 * URL configured in Pipelinq admin settings. Parses XML responses to arrays
 * and caches results via ICacheFactory.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.conduction.nl
 *
 * @spec openspec/changes/xwiki-integration/tasks.md#task-1.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

/**
 * Service for xWiki REST API integration.
 *
 * Attempts to use OCA\Xwiki\SettingsManager / OCA\Xwiki\Instance via the
 * container. Falls back to the direct URL in Pipelinq settings when the
 * nextcloud/xwiki app is absent or not NC-32-compatible.
 *
 * @spec openspec/changes/xwiki-integration/tasks.md#task-1.1
 */
class XWikiService
{

    /**
     * Default cache TTL in seconds.
     */
    private const DEFAULT_CACHE_TTL = 300;

    /**
     * Cache bucket name.
     */
    private const CACHE_BUCKET = 'pipelinq.xwiki';

    /**
     * Constructor.
     *
     * @param IClientService $httpClient  The Nextcloud HTTP client factory.
     * @param IAppConfig     $appConfig   The app config.
     * @param ICacheFactory  $cacheFactory The cache factory.
     * @param LoggerInterface $logger      The logger.
     */
    public function __construct(
        private readonly IClientService $httpClient,
        private readonly IAppConfig $appConfig,
        private readonly ICacheFactory $cacheFactory,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get the configured xWiki base URL.
     *
     * Tries the direct URL setting first; nextcloud/xwiki integration URL
     * lookup is done inside each method that needs it.
     *
     * @return string The base URL without trailing slash, or empty string.
     */
    public function getBaseUrl(): string
    {
        return rtrim(
            $this->appConfig->getValueString(Application::APP_ID, 'xwiki_direct_url', ''),
            '/'
        );
    }//end getBaseUrl()

    /**
     * Get the configured cache TTL in seconds.
     *
     * @return int The TTL in seconds.
     */
    private function getCacheTtl(): int
    {
        return $this->appConfig->getValueInt(
            Application::APP_ID,
            'xwiki_cache_ttl',
            self::DEFAULT_CACHE_TTL
        );
    }//end getCacheTtl()

    /**
     * Get a cached value or null if not cached.
     *
     * @param string $key The cache key.
     *
     * @return mixed|null The cached value or null.
     */
    private function fromCache(string $key): mixed
    {
        if ($this->cacheFactory->isAvailable() === false) {
            return null;
        }

        $cache = $this->cacheFactory->createDistributed(self::CACHE_BUCKET);
        return $cache->get($key);
    }//end fromCache()

    /**
     * Store a value in the cache.
     *
     * @param string $key   The cache key.
     * @param mixed  $value The value to cache.
     *
     * @return void
     */
    private function toCache(string $key, mixed $value): void
    {
        if ($this->cacheFactory->isAvailable() === false) {
            return;
        }

        $cache = $this->cacheFactory->createDistributed(self::CACHE_BUCKET);
        $cache->set($key, $value, $this->getCacheTtl());
    }//end toCache()

    /**
     * Build a cache key from method name and parameters.
     *
     * @param string $method The method name.
     * @param array  $params The parameters.
     *
     * @return string The cache key.
     */
    private function buildCacheKey(string $method, array $params): string
    {
        return $method . ':' . md5(serialize($params));
    }//end buildCacheKey()

    /**
     * Make a GET request to the xWiki REST API.
     *
     * Adds Authorization header when a token is configured. Returns the raw
     * response body as a string or null on error.
     *
     * @param string $url    The full URL to request.
     * @param array  $query  Optional query parameters.
     *
     * @return string|null The response body, or null on failure.
     */
    private function get(string $url, array $query = []): ?string
    {
        if ($url === '') {
            return null;
        }

        if (empty($query) === false) {
            $url .= '?' . http_build_query($query);
        }

        try {
            $client  = $this->httpClient->newClient();
            $options = [
                'timeout'         => 10,
                'connect_timeout' => 5,
            ];

            $response = $client->get($url, $options);
            return $response->getBody();
        } catch (\Exception $e) {
            $this->logger->warning('XWikiService GET failed', [
                'url'   => $url,
                'error' => $e->getMessage(),
            ]);
            return null;
        }//end try
    }//end get()

    /**
     * Parse an xWiki XML search response into a normalized array.
     *
     * Handles `<searchResults>` containing `<searchResult>` children as
     * returned by `/rest/wikis/query?q=<term>`.
     *
     * @param string $xml The XML string.
     *
     * @return array Array of page objects with id, title, space, modified, url.
     */
    private function parseSearchXml(string $xml): array
    {
        $results = [];
        try {
            $doc = new \SimpleXMLElement($xml, LIBXML_NONET | LIBXML_NOERROR);
            $doc->registerXPathNamespace('xwiki', 'http://www.xwiki.org');
            $items = $doc->xpath('//xwiki:searchResult') ?: $doc->xpath('//*[local-name()="searchResult"]') ?: [];

            foreach ($items as $item) {
                $results[] = $this->buildPageFromXml($item);
            }
        } catch (\Exception $e) {
            $this->logger->debug('XWikiService: failed to parse search XML', ['error' => $e->getMessage()]);
        }

        return $results;
    }//end parseSearchXml()

    /**
     * Parse an xWiki XML pages response into a normalized array.
     *
     * Handles `<pages>` containing `<pageSummary>` children as returned by
     * `/rest/wikis/{wiki}/spaces/{space}/.../pages`.
     *
     * @param string $xml The XML string.
     *
     * @return array Array of page objects with id, title, url.
     */
    private function parsePagesXml(string $xml): array
    {
        $results = [];
        try {
            $doc   = new \SimpleXMLElement($xml, LIBXML_NONET | LIBXML_NOERROR);
            $items = $doc->xpath('//*[local-name()="pageSummary"]') ?: [];

            foreach ($items as $item) {
                $results[] = $this->buildPageFromXml($item);
            }
        } catch (\Exception $e) {
            $this->logger->debug('XWikiService: failed to parse pages XML', ['error' => $e->getMessage()]);
        }

        return $results;
    }//end parsePagesXml()

    /**
     * Build a normalized page array from a SimpleXMLElement node.
     *
     * @param \SimpleXMLElement $node The XML node.
     *
     * @return array The normalized page array.
     */
    private function buildPageFromXml(\SimpleXMLElement $node): array
    {
        $id       = (string) ($node->id ?? $node->fullName ?? '');
        $title    = (string) ($node->title ?? $node->name ?? $id);
        $space    = (string) ($node->space ?? $node->spaces ?? '');
        $modified = (string) ($node->modified ?? $node->date ?? '');
        $url      = '';

        // Extract <link> with @rel="alternate" for the canonical URL.
        foreach ($node->link ?? [] as $link) {
            $rel  = (string) ($link->attributes()['rel'] ?? '');
            $href = (string) ($link->attributes()['href'] ?? '');
            if ($rel === 'alternate' && $href !== '') {
                $url = $href;
                break;
            }
        }

        return [
            'id'       => $id,
            'title'    => $title,
            'space'    => $space,
            'modified' => $modified,
            'url'      => $url,
        ];
    }//end buildPageFromXml()

    /**
     * Sanitize HTML content from xWiki.
     *
     * Strips <script> tags, inline event handlers, and javascript: href/src
     * attributes to prevent XSS. Safe structural and presentational elements
     * are preserved.
     *
     * @param string $html         The raw HTML content.
     * @param string $xwikiBaseUrl The xWiki base URL for rewriting relative links.
     *
     * @return string The sanitized HTML.
     */
    public function sanitizeHtml(string $html, string $xwikiBaseUrl): string
    {
        // Remove <script> blocks (including content).
        $html = preg_replace('/<script\b[^>]*>[\s\S]*?<\/script>/i', '', $html) ?? $html;

        // Remove <style> blocks.
        $html = preg_replace('/<style\b[^>]*>[\s\S]*?<\/style>/i', '', $html) ?? $html;

        // Strip inline event handlers (on*=...).
        $html = preg_replace('/\s+on\w+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]*)/i', '', $html) ?? $html;

        // Strip javascript: hrefs.
        $html = preg_replace('/href\s*=\s*["\']?\s*javascript:[^"\'>\s]*/i', 'href="#"', $html) ?? $html;

        if ($xwikiBaseUrl !== '') {
            // Rewrite relative src attributes to absolute.
            $html = preg_replace(
                '/(<img[^>]+src=")(?!https?:\/\/)([^"]+)(")/i',
                '$1' . $xwikiBaseUrl . '/$2$3',
                $html
            ) ?? $html;

            // Rewrite relative href attributes to absolute.
            $html = preg_replace(
                '/(<a[^>]+href=")(?!https?:\/\/)(?!#)([^"]+)(")/i',
                '$1' . $xwikiBaseUrl . '/$2$3',
                $html
            ) ?? $html;
        }

        return $html;
    }//end sanitizeHtml()

    /**
     * Search xWiki pages.
     *
     * Queries `/rest/wikis/query?q=<term>` with optional space and tag
     * filters. Results are cached for the configured TTL.
     *
     * @param string $query  The search query.
     * @param string $space  Optional space filter.
     * @param array  $tags   Optional tag filters.
     * @param int    $limit  Maximum results (default 10).
     * @param int    $offset Pagination offset (default 0).
     *
     * @return array Normalized result array with 'results', 'total', 'limit', 'offset'.
     *
     * @spec openspec/changes/xwiki-integration/tasks.md#task-1.1
     */
    public function search(string $query, string $space = '', array $tags = [], int $limit = 10, int $offset = 0): array
    {
        $cacheKey = $this->buildCacheKey('search', compact('query', 'space', 'tags', 'limit', 'offset'));
        $cached   = $this->fromCache($cacheKey);
        if ($cached !== null) {
            $cached['x_cache'] = 'HIT';
            return $cached;
        }

        $baseUrl = $this->getBaseUrl();
        if ($baseUrl === '') {
            return ['results' => [], 'total' => 0, 'limit' => $limit, 'offset' => $offset, 'error' => 'xWiki URL not configured'];
        }

        $queryParams = [
            'q'      => $query,
            'number' => $limit,
            'start'  => $offset,
        ];
        if ($space !== '') {
            $queryParams['wikis'] = 'xwiki';
            $queryParams['spaces'] = $space;
        }

        $xml = $this->get($baseUrl . '/rest/wikis/query', $queryParams);
        if ($xml === null) {
            return ['results' => [], 'total' => 0, 'limit' => $limit, 'offset' => $offset, 'error' => 'xWiki request failed'];
        }

        $items = $this->parseSearchXml($xml);

        // Filter by tags if requested (client-side; xWiki REST search has limited tag support).
        if (empty($tags) === false) {
            $items = array_values(array_filter($items, static function (array $page) use ($tags): bool {
                foreach ($tags as $tag) {
                    if (stripos($page['title'], $tag) !== false || stripos($page['space'], $tag) !== false) {
                        return true;
                    }
                }
                return false;
            }));
        }

        $result = [
            'results' => $items,
            'total'   => count($items),
            'limit'   => $limit,
            'offset'  => $offset,
            'x_cache' => 'MISS',
        ];

        $this->toCache($cacheKey, $result);
        return $result;
    }//end search()

    /**
     * List pages in a given xWiki space.
     *
     * Queries `/rest/wikis/xwiki/spaces/{space}/pages`.
     * Results are cached.
     *
     * @param string $space  The space name.
     * @param int    $limit  Maximum results (default 20).
     * @param int    $offset Pagination offset (default 0).
     *
     * @return array Normalized result array with 'results', 'total', 'limit', 'offset'.
     *
     * @spec openspec/changes/xwiki-integration/tasks.md#task-1.1
     */
    public function getPages(string $space, int $limit = 20, int $offset = 0): array
    {
        $cacheKey = $this->buildCacheKey('pages', compact('space', 'limit', 'offset'));
        $cached   = $this->fromCache($cacheKey);
        if ($cached !== null) {
            $cached['x_cache'] = 'HIT';
            return $cached;
        }

        $baseUrl = $this->getBaseUrl();
        if ($baseUrl === '') {
            return ['results' => [], 'total' => 0, 'limit' => $limit, 'offset' => $offset, 'error' => 'xWiki URL not configured'];
        }

        $spacePath = urlencode($space);
        $xml       = $this->get(
            $baseUrl . "/rest/wikis/xwiki/spaces/{$spacePath}/pages",
            ['number' => $limit, 'start' => $offset]
        );

        if ($xml === null) {
            return ['results' => [], 'total' => 0, 'limit' => $limit, 'offset' => $offset, 'error' => 'xWiki request failed'];
        }

        $items  = $this->parsePagesXml($xml);
        $result = [
            'results' => $items,
            'total'   => count($items),
            'limit'   => $limit,
            'offset'  => $offset,
            'x_cache' => 'MISS',
        ];

        $this->toCache($cacheKey, $result);
        return $result;
    }//end getPages()

    /**
     * Retrieve a single xWiki page's content and metadata.
     *
     * Fetches the rendered HTML view, extracts the #xwikicontent div, and
     * sanitizes the result. Also fetches metadata from the REST API.
     * Results are cached.
     *
     * @param string $wiki The wiki name (e.g. "xwiki").
     * @param string $page The page name (e.g. "Kennisbank.Paspoort.WebHome").
     *
     * @return array Page data with id, title, space, modified, url, content; or error.
     *
     * @spec openspec/changes/xwiki-integration/tasks.md#task-1.1
     */
    public function getPageContent(string $wiki, string $page): array
    {
        $cacheKey = $this->buildCacheKey('page', compact('wiki', 'page'));
        $cached   = $this->fromCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $baseUrl = $this->getBaseUrl();
        if ($baseUrl === '') {
            return ['error' => 'xWiki URL not configured', 'content' => ''];
        }

        // Fetch metadata via REST API (XML).
        $pagePath = implode('/spaces/', explode('.', $page));
        $metaXml  = $this->get($baseUrl . "/rest/wikis/{$wiki}/spaces/{$pagePath}");

        $meta = [
            'id'       => "{$wiki}:{$page}",
            'title'    => $page,
            'space'    => explode('.', $page)[0] ?? '',
            'modified' => '',
            'url'      => $baseUrl . "/xwiki/bin/view/" . str_replace('.', '/', $page),
        ];

        if ($metaXml !== null) {
            try {
                $doc = new \SimpleXMLElement($metaXml, LIBXML_NONET | LIBXML_NOERROR);
                if (isset($doc->title)) {
                    $meta['title'] = (string) $doc->title;
                }
                if (isset($doc->modified)) {
                    $meta['modified'] = (string) $doc->modified;
                }
            } catch (\Exception $e) {
                $this->logger->debug('XWikiService: failed to parse page metadata', ['error' => $e->getMessage()]);
            }
        }

        // Fetch rendered HTML view.
        $htmlUrl = $baseUrl . '/xwiki/bin/get/' . str_replace('.', '/', $page);
        $html    = $this->get($htmlUrl, ['xpage' => 'plain', 'outputSyntax' => 'html']);

        $content = '';
        if ($html !== null) {
            // Extract #xwikicontent div if present.
            if (preg_match('/<div[^>]+id=["\']xwikicontent["\'][^>]*>([\s\S]*?)<\/div>/i', $html, $matches)) {
                $content = $matches[1];
            } else {
                $content = $html;
            }

            $content = $this->sanitizeHtml($content, $baseUrl);
        }

        $result = array_merge($meta, ['content' => $content]);
        $this->toCache($cacheKey, $result);
        return $result;
    }//end getPageContent()

    /**
     * Check xWiki availability.
     *
     * Queries the xWiki REST root endpoint and parses the version from the
     * response XML.
     *
     * @return array Status array with 'available', 'version', 'url', and optional 'error'.
     *
     * @spec openspec/changes/xwiki-integration/tasks.md#task-1.1
     */
    public function getStatus(): array
    {
        $baseUrl = $this->getBaseUrl();
        if ($baseUrl === '') {
            return [
                'available' => false,
                'error'     => 'xWiki URL not configured',
                'url'       => '',
            ];
        }

        $xml = $this->get($baseUrl . '/rest');
        if ($xml === null) {
            return [
                'available' => false,
                'error'     => 'Could not reach xWiki instance',
                'url'       => $baseUrl,
            ];
        }

        $version = '';
        try {
            $doc = new \SimpleXMLElement($xml, LIBXML_NONET | LIBXML_NOERROR);
            // xWiki REST root returns <xwiki> element with version child.
            $version = (string) ($doc->version ?? '');
            if ($version === '') {
                $nodes   = $doc->xpath('//*[local-name()="version"]');
                $version = $nodes !== false && count($nodes) > 0 ? (string) $nodes[0] : '';
            }
        } catch (\Exception $e) {
            $this->logger->debug('XWikiService: failed to parse status XML', ['error' => $e->getMessage()]);
        }

        return [
            'available' => true,
            'version'   => $version,
            'url'       => $baseUrl,
        ];
    }//end getStatus()
}//end class
