<?php

/**
 * Pipelinq XWikiService.
 *
 * Thin proxy/service layer that wraps the xWiki REST API for the Pipelinq
 * frontend (xwiki-integration change). When the optional `OCA\Xwiki` Nextcloud
 * app is installed its `SettingsManager` is used to discover the configured
 * xWiki instance; otherwise the service falls back to the admin-configured
 * `xwiki_direct_url` from Pipelinq settings. Responses are parsed from XML to
 * arrays, sanitised, and cached via ICacheFactory for the configured TTL.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/xwiki-integration/tasks.md#1.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IURLGenerator;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * XWiki REST proxy service.
 *
 * @spec openspec/changes/xwiki-integration/tasks.md#1.1
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) thin proxy over the xWiki REST
 *  API with discovery, caching, XML parsing and sanitisation kept together as one
 *  cohesive read path
 */
class XWikiService
{
    /**
     * Default cache TTL (seconds) when admin has not configured a value.
     *
     * @var int
     */
    private const DEFAULT_CACHE_TTL = 300;

    /**
     * Default xWiki REST API endpoint used by the dev docker stack.
     *
     * @var string
     */
    private const DEFAULT_DIRECT_URL = 'http://xwiki:8080/xwiki';

    /**
     * In-memory marker for "xWiki unreachable" status checks so repeated calls
     * within a single request do not re-issue HTTP traffic.
     *
     * @var boolean|null
     */
    private ?bool $availabilityMemo = null;

    /**
     * Resolved cache bucket for the xWiki proxy.
     *
     * @var ICache|null
     */
    private ?ICache $cache = null;

    /**
     * Constructor.
     *
     * @param IClientService     $clientService HTTP client factory.
     * @param IAppConfig         $appConfig     Pipelinq app config.
     * @param ICacheFactory      $cacheFactory  Distributed cache factory.
     * @param ContainerInterface $container     PSR container (used to lazily
     *                                          load `OCA\Xwiki\SettingsManager`
     *                                          when the xWiki app is present).
     * @param IURLGenerator      $urlGenerator  URL generator (resolves the OR
     *                                          xWiki search endpoint — ADR-022).
     * @param LoggerInterface    $logger        Logger.
     */
    public function __construct(
        private IClientService $clientService,
        private IAppConfig $appConfig,
        private ICacheFactory $cacheFactory,
        private ContainerInterface $container,
        private IURLGenerator $urlGenerator,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Search xWiki pages.
     *
     * @param string      $query  Full-text query, may be empty.
     * @param string|null $space  Optional space filter.
     * @param array       $tags   Optional list of tags to filter on (best-effort
     *                            client-side filter — xWiki REST search has no
     *                            generic tag filter).
     * @param int         $limit  Max results (1..100).
     * @param int         $offset Pagination offset (>=0).
     *
     * @return array<string, mixed> {results: array, total: int, limit: int, offset: int}
     *
     * @spec openspec/changes/pipelinq-xwiki-through-or/specs/xwiki-proxy/spec.md
     */
    public function search(string $query, ?string $space=null, array $tags=[], int $limit=10, int $offset=0): array
    {
        $limit  = max(1, min(100, $limit));
        $offset = max(0, $offset);

        $cacheKey = $this->makeCacheKey(bucket: 'search', parts: [$query, $space ?? '', implode(',', $tags), $limit, $offset]);
        $cached   = $this->fromCache(key: $cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        // OR-first (ADR-022, pipelinq-xwiki-through-or): prefer OpenRegister's
        // OpenConnector-routed xWiki search so the consumer never holds a direct
        // xWiki URL. When OR signals the `xwiki` source is not usable yet
        // (openconnector-down / -source-missing / upstream-service-down) we fall
        // back to the legacy SettingsManager/direct-URL path below so configured
        // envs keep working until an operator enables the OR source. The
        // post-fetch space/tags filters + caching are shared by both paths.
        $orResults = $this->searchViaOpenRegister(query: $query, limit: $limit, offset: $offset);
        if ($orResults !== null) {
            return $this->finishSearch(results: $orResults, space: $space, tags: $tags, limit: $limit, offset: $offset, cacheKey: $cacheKey);
        }

        $base = $this->getBaseUrl();
        if ($base === '') {
            return $this->emptyResult(limit: $limit, offset: $offset);
        }

        $params = [
            'q'      => $query,
            'number' => $limit,
            'start'  => $offset,
            'media'  => 'xml',
        ];
        if ($space !== null && $space !== '') {
            $params['scope'] = 'name,content,title';
            $params['wikis'] = 'xwiki';
        }

        $url     = $base.'/rest/wikis/query?'.http_build_query($params);
        $xml     = $this->fetchXml(url: $url);
        $results = $this->parseSearchResults(xml: $xml);

        return $this->finishSearch(results: $results, space: $space, tags: $tags, limit: $limit, offset: $offset, cacheKey: $cacheKey);
    }//end search()

    /**
     * Apply the shared space/tags client-side filter, slice to the limit, wrap
     * in the standard `{results,total,limit,offset}` envelope and cache it.
     *
     * Used by both the OR-first path and the legacy direct path so the result
     * shape + post-filter semantics stay identical regardless of source.
     *
     * @param array<int,array<string,mixed>> $results  Raw normalised result rows.
     * @param string|null                    $space    Optional space filter.
     * @param array<int,string>              $tags     Optional tag filter.
     * @param int                            $limit    Max results.
     * @param int                            $offset   Pagination offset.
     * @param string                         $cacheKey Cache key to store under.
     *
     * @return array<string, mixed>
     *
     * @spec openspec/changes/pipelinq-xwiki-through-or/specs/xwiki-proxy/spec.md
     */
    private function finishSearch(array $results, ?string $space, array $tags, int $limit, int $offset, string $cacheKey): array
    {
        if ($space !== null && $space !== '') {
            $results = array_values(array_filter($results, static fn(array $item): bool => ($item['space'] ?? '') === $space));
        }

        if (count($tags) > 0) {
            $results = array_values(
                    array_filter(
                    $results,
                    static function (array $item) use ($tags): bool {
                        $itemTags = $item['tags'] ?? [];
                        foreach ($tags as $tag) {
                            if (in_array($tag, $itemTags, true) === true) {
                                return true;
                            }
                        }

                        return false;
                    }
                    )
                    );
        }

        $payload = [
            'results' => array_slice($results, 0, $limit),
            'total'   => count($results),
            'limit'   => $limit,
            'offset'  => $offset,
        ];

        $this->toCache(key: $cacheKey, value: $payload);
        return $payload;
    }//end finishSearch()

    /**
     * Search xWiki via OpenRegister's OpenConnector-routed endpoint (ADR-022).
     *
     * Calls `GET /apps/openregister/api/integrations/xwiki/search` server-side.
     * Returns the normalised result rows on success (HTTP 200), or `null` when
     * the OR `xwiki` source is not usable yet — i.e. OR returns a 503 whose
     * `details.cause` is `openconnector-down` / `openconnector-source-missing`
     * / `upstream-service-down`, or OR/openregister is absent. A `null` return
     * tells {@see search()} to fall back to the legacy direct path so configured
     * envs keep working until an operator enables the OR source. The OR row
     * shape (`{id|reference,title,space,url,...}`) is mapped to the widget shape
     * (`{id,title,space,modified,url,tags}`).
     *
     * @param string $query  Full-text query.
     * @param int    $limit  Max results.
     * @param int    $offset Pagination offset.
     *
     * @return array<int,array<string,mixed>>|null Normalised rows, or null to fall back.
     *
     * @spec openspec/changes/pipelinq-xwiki-through-or/specs/xwiki-proxy/spec.md
     */
    private function searchViaOpenRegister(string $query, int $limit, int $offset): ?array
    {
        $params = http_build_query(['q' => $query, 'limit' => $limit, 'offset' => $offset]);
        $url    = $this->urlGenerator->getAbsoluteURL('/apps/openregister/api/integrations/xwiki/search?'.$params);

        try {
            $client   = $this->clientService->newClient();
            $response = $client->get(
                    $url,
                    [
                        'timeout'         => 10,
                        'connect_timeout' => 3,
                        'headers'         => ['OCS-APIREQUEST' => 'true', 'Accept' => 'application/json'],
                        'nextcloud'       => ['allow_local_address' => true],
                    ]
                    );
        } catch (Throwable $e) {
            // 503 (source not usable) surfaces as a client exception here — fall
            // back to the legacy path. Also covers connection refused / OR absent.
            $this->logger->debug('xWiki OR search unavailable, falling back to direct path', ['exception' => $e]);
            return null;
        }

        $status = $response->getStatusCode();
        if ($status !== 200) {
            $this->logger->debug('xWiki OR search returned non-200, falling back', ['status' => $status]);
            return null;
        }

        $decoded = json_decode((string) $response->getBody(), true);
        if (is_array($decoded) === false || isset($decoded['results']) === false || is_array($decoded['results']) === false) {
            return null;
        }

        return $this->mapOpenRegisterRows(rows: $decoded['results']);
    }//end searchViaOpenRegister()

    /**
     * Map raw OpenRegister search result rows to the widget row shape.
     *
     * @param array<int, mixed> $rows Raw rows from the OR search response.
     *
     * @return array<int, array<string, mixed>> Normalised rows.
     */
    private function mapOpenRegisterRows(array $rows): array
    {
        $mapped = [];
        foreach ($rows as $row) {
            if (is_array($row) === false) {
                continue;
            }

            $tags = [];
            if (isset($row['tags']) === true && is_array($row['tags']) === true) {
                $tags = $row['tags'];
            }

            $mapped[] = [
                'id'       => (string) ($row['id'] ?? $row['reference'] ?? ''),
                'title'    => (string) ($row['title'] ?? $row['id'] ?? $row['reference'] ?? ''),
                'space'    => (string) ($row['space'] ?? ''),
                'modified' => (string) ($row['modified'] ?? ''),
                'url'      => (string) ($row['url'] ?? ''),
                'tags'     => $tags,
            ];
        }

        return $mapped;
    }//end mapOpenRegisterRows()

    /**
     * List pages in a space.
     *
     * @param string $space  xWiki space name (e.g. "Kennisbank").
     * @param int    $limit  Max results.
     * @param int    $offset Offset.
     *
     * @return array<string, mixed>
     *
     * @spec openspec/changes/xwiki-integration/tasks.md#1.1
     */
    public function getPages(string $space, int $limit=10, int $offset=0): array
    {
        $limit  = max(1, min(100, $limit));
        $offset = max(0, $offset);

        $cacheKey = $this->makeCacheKey(bucket: 'pages', parts: [$space, $limit, $offset]);
        $cached   = $this->fromCache(key: $cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $base = $this->getBaseUrl();
        if ($base === '' || $space === '') {
            return $this->emptyResult(limit: $limit, offset: $offset);
        }

        $wiki    = 'xwiki';
        $url     = sprintf('%s/rest/wikis/%s/spaces/%s/pages?media=xml&number=%d&start=%d', $base, $wiki, rawurlencode($space), $limit, $offset);
        $xml     = $this->fetchXml(url: $url);
        $results = $this->parsePageSummaries(xml: $xml, space: $space);

        $payload = [
            'results' => $results,
            'total'   => count($results),
            'limit'   => $limit,
            'offset'  => $offset,
        ];
        $this->toCache(key: $cacheKey, value: $payload);
        return $payload;
    }//end getPages()

    /**
     * Fetch rendered HTML for a single page.
     *
     * @param string $wiki xWiki wiki id (typically "xwiki").
     * @param string $page Dotted page reference, e.g. "Kennisbank.Paspoort.WebHome".
     *
     * @return array<string, mixed> {title, content, url, modified, space, id}
     *
     * @spec openspec/changes/xwiki-integration/tasks.md#1.1
     */
    public function getPageContent(string $wiki, string $page): array
    {
        $wiki = trim($wiki);
        if ($wiki === '') {
            $wiki = 'xwiki';
        }

        $page = trim($page);

        $cacheKey = $this->makeCacheKey(bucket: 'page', parts: [$wiki, $page]);
        $cached   = $this->fromCache(key: $cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $base = $this->getBaseUrl();
        if ($base === '' || $page === '') {
            return [
                'title'    => '',
                'content'  => '',
                'url'      => '',
                'modified' => '',
                'space'    => '',
                'id'       => '',
            ];
        }

        $segments   = array_map('rawurlencode', explode('.', $page));
        $space      = (string) ($segments[0] ?? '');
        $pageName   = (string) end($segments);
        $spacesPath = implode('/spaces/', array_slice($segments, 0, -1));
        $url        = sprintf('%s/rest/wikis/%s/spaces/%s/pages/%s?media=xml', $base, rawurlencode($wiki), $spacesPath, $pageName);
        $xml        = $this->fetchXml(url: $url);
        $page       = $this->parsePage(xml: $xml);

        // Fetch rendered HTML separately via the bin/view URL and extract the
        // body content (#xwikicontent) so the proxy can return a sanitised HTML
        // fragment for inline viewing.
        $viewUrl = sprintf('%s/bin/view/%s', $base, implode('/', $segments));
        $rawHtml = $this->fetchRaw(url: $viewUrl);
        $content = $this->extractAndSanitiseHtml(html: $rawHtml);

        $page['content'] = $content;
        $page['url']     = $viewUrl;
        $page['space']   = $space;

        $this->toCache(key: $cacheKey, value: $page);
        return $page;
    }//end getPageContent()

    /**
     * Check xWiki status / availability.
     *
     * @return array<string, mixed> {available: bool, version: string, baseUrl: string, source: string}
     *
     * @spec openspec/changes/xwiki-integration/tasks.md#1.1
     */
    public function getStatus(): array
    {
        $base = $this->getBaseUrl();
        if ($base === '') {
            return [
                'available' => false,
                'version'   => '',
                'baseUrl'   => '',
                'source'    => 'unconfigured',
            ];
        }

        if ($this->availabilityMemo !== null) {
            return [
                'available' => $this->availabilityMemo,
                'version'   => '',
                'baseUrl'   => $base,
                'source'    => $this->getInstanceSource(),
            ];
        }

        try {
            $client   = $this->clientService->newClient();
            $response = $client->get($base.'/rest', ['timeout' => 5, 'connect_timeout' => 3]);
            $body     = (string) $response->getBody();
            $version  = '';
            if (preg_match('#<version>([^<]+)</version>#', $body, $matches) === 1) {
                $version = (string) $matches[1];
            }

            $this->availabilityMemo = true;
            return [
                'available' => true,
                'version'   => $version,
                'baseUrl'   => $base,
                'source'    => $this->getInstanceSource(),
            ];
        } catch (Throwable $e) {
            $this->logger->debug('xWiki status check failed', ['exception' => $e]);
            $this->availabilityMemo = false;
            return [
                'available' => false,
                'version'   => '',
                'baseUrl'   => $base,
                'source'    => $this->getInstanceSource(),
            ];
        }//end try
    }//end getStatus()

    /**
     * Resolve the xWiki base URL. Tries the `OCA\Xwiki` app's SettingsManager
     * first when present, then falls back to admin-configured direct URL.
     *
     * @return string Base URL without trailing slash, or '' when unavailable.
     */
    private function getBaseUrl(): string
    {
        // Attempt to load the xWiki app's SettingsManager via the container.
        try {
            $cls = '\\OCA\\Xwiki\\SettingsManager';
            if (class_exists($cls) === true && $this->container->has($cls) === true) {
                $manager = $this->container->get($cls);
                if (method_exists($manager, 'getBaseUrl') === true) {
                    $url = (string) $manager->getBaseUrl();
                    if ($url !== '') {
                        return rtrim($url, '/');
                    }
                }
            }
        } catch (Throwable $e) {
            $this->logger->debug('xWiki SettingsManager unavailable, using direct URL', ['exception' => $e]);
        }

        $direct = $this->appConfig->getValueString(Application::APP_ID, 'xwiki_direct_url', self::DEFAULT_DIRECT_URL);
        return rtrim($direct, '/');
    }//end getBaseUrl()

    /**
     * Source label for status (which path discovered the base URL).
     *
     * @return string
     */
    private function getInstanceSource(): string
    {
        try {
            if (class_exists('\\OCA\\Xwiki\\SettingsManager') === true && $this->container->has('\\OCA\\Xwiki\\SettingsManager') === true) {
                return 'xwiki-app';
            }
        } catch (Throwable $e) {
            // Fall through to direct.
        }

        return 'direct-url';
    }//end getInstanceSource()

    /**
     * Get configured cache TTL.
     *
     * @return int
     */
    private function getCacheTtl(): int
    {
        $raw = (int) $this->appConfig->getValueString(Application::APP_ID, 'xwiki_cache_ttl', (string) self::DEFAULT_CACHE_TTL);
        if ($raw > 0) {
            return $raw;
        }

        return self::DEFAULT_CACHE_TTL;
    }//end getCacheTtl()

    /**
     * Build a stable cache key.
     *
     * @param string $bucket Cache bucket name.
     * @param array  $parts  Key parts.
     *
     * @return string
     */
    private function makeCacheKey(string $bucket, array $parts): string
    {
        return $bucket.':'.sha1(implode('|', array_map('strval', $parts)));
    }//end makeCacheKey()

    /**
     * Fetch from local cache. Returns null on miss.
     *
     * @param string $key Cache key.
     *
     * @return array|null
     */
    private function fromCache(string $key): ?array
    {
        if ($this->cache === null) {
            $this->cache = $this->cacheFactory->createDistributed('pipelinq-xwiki');
        }

        $raw = $this->cache->get($key);
        if (is_string($raw) === true) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) === true) {
                return $decoded;
            }

            return null;
        }

        if (is_array($raw) === true) {
            return $raw;
        }

        return null;
    }//end fromCache()

    /**
     * Store in cache.
     *
     * @param string $key   Cache key.
     * @param array  $value Value to cache.
     *
     * @return void
     */
    private function toCache(string $key, array $value): void
    {
        if ($this->cache === null) {
            $this->cache = $this->cacheFactory->createDistributed('pipelinq-xwiki');
        }

        $this->cache->set($key, json_encode($value), $this->getCacheTtl());
    }//end toCache()

    /**
     * GET an XML resource from xWiki, return body string. Returns '' on
     * failure rather than throwing so callers can fall back to empty payloads.
     *
     * @param string $url Absolute URL.
     *
     * @return string
     */
    private function fetchXml(string $url): string
    {
        try {
            $client   = $this->clientService->newClient();
            $response = $client->get(
                    $url,
                    [
                        'timeout'         => 10,
                        'connect_timeout' => 3,
                        'headers'         => ['Accept' => 'application/xml'],
                    ]
                    );
            return (string) $response->getBody();
        } catch (Throwable $e) {
            $this->logger->debug('xWiki XML fetch failed', ['url' => $url, 'exception' => $e]);
            return '';
        }
    }//end fetchXml()

    /**
     * GET a raw HTML resource from xWiki.
     *
     * @param string $url Absolute URL.
     *
     * @return string
     */
    private function fetchRaw(string $url): string
    {
        try {
            $client   = $this->clientService->newClient();
            $response = $client->get(
                    $url,
                    [
                        'timeout'         => 10,
                        'connect_timeout' => 3,
                    ]
                    );
            return (string) $response->getBody();
        } catch (Throwable $e) {
            $this->logger->debug('xWiki raw fetch failed', ['url' => $url, 'exception' => $e]);
            return '';
        }
    }//end fetchRaw()

    /**
     * Parse xWiki searchResult XML into array list.
     *
     * @param string $xml Raw XML body.
     *
     * @return array<int, array<string, mixed>>
     *
     * @spec openspec/changes/xwiki-integration/tasks.md#1.1
     */
    public function parseSearchResults(string $xml): array
    {
        if ($xml === '') {
            return [];
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $doc = simplexml_load_string($xml);
            if ($doc === false) {
                return [];
            }

            $results = [];
            foreach ($doc->xpath('//searchResult') as $node) {
                $results[] = $this->resultNodeToArray(node: $node);
            }

            return $results;
        } finally {
            libxml_use_internal_errors($previous);
        }
    }//end parseSearchResults()

    /**
     * Parse xWiki pageSummary list XML.
     *
     * @param string $xml   Raw XML.
     * @param string $space Space name (for tagging the result).
     *
     * @return array<int, array<string, mixed>>
     *
     * @spec openspec/changes/xwiki-integration/tasks.md#1.1
     */
    public function parsePageSummaries(string $xml, string $space): array
    {
        if ($xml === '') {
            return [];
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $doc = simplexml_load_string($xml);
            if ($doc === false) {
                return [];
            }

            $results = [];
            foreach ($doc->xpath('//pageSummary') as $node) {
                $item      = [
                    'id'       => (string) ($node->id ?? ''),
                    'title'    => (string) ($node->title ?? ''),
                    'space'    => $space,
                    'modified' => (string) ($node->modified ?? ''),
                    'url'      => (string) ($node->xwikiAbsoluteUrl ?? ''),
                    'tags'     => [],
                ];
                $results[] = $item;
            }

            return $results;
        } finally {
            libxml_use_internal_errors($previous);
        }//end try
    }//end parsePageSummaries()

    /**
     * Parse a single page XML payload.
     *
     * @param string $xml Raw XML.
     *
     * @return array<string, mixed>
     *
     * @spec openspec/changes/xwiki-integration/tasks.md#1.1
     */
    public function parsePage(string $xml): array
    {
        if ($xml === '') {
            return ['title' => '', 'modified' => '', 'id' => '', 'space' => '', 'content' => '', 'url' => ''];
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $doc = simplexml_load_string($xml);
            if ($doc === false) {
                return ['title' => '', 'modified' => '', 'id' => '', 'space' => '', 'content' => '', 'url' => ''];
            }

            return [
                'id'       => (string) ($doc->id ?? ''),
                'title'    => (string) ($doc->title ?? ''),
                'modified' => (string) ($doc->modified ?? ''),
                'space'    => (string) ($doc->space ?? ''),
                'content'  => '',
                'url'      => (string) ($doc->xwikiAbsoluteUrl ?? ''),
            ];
        } finally {
            libxml_use_internal_errors($previous);
        }
    }//end parsePage()

    /**
     * Extract xwikicontent and sanitise HTML (strip script/style/event handlers).
     *
     * @param string $html Raw HTML page.
     *
     * @return string Sanitised HTML fragment.
     *
     * @spec openspec/changes/xwiki-integration/tasks.md#1.1
     */
    public function extractAndSanitiseHtml(string $html): string
    {
        if ($html === '') {
            return '';
        }

        $fragment = $html;
        $pattern  = '#<div[^>]*id=["\']xwikicontent["\'][^>]*>(.*?)</div>\s*'
            .'(?:<!--\s*end\s*xwikicontent|<div[^>]*id=["\']xwikidocfooter)#is';
        if (preg_match($pattern, $html, $matches) === 1) {
            $fragment = (string) $matches[1];
        }

        return $this->sanitiseHtml(html: $fragment);
    }//end extractAndSanitiseHtml()

    /**
     * Sanitise an HTML fragment: strip script/style tags and on* event handlers
     * and javascript: URLs. Conservative deny-list — xWiki content is treated
     * as semi-trusted.
     *
     * @param string $html Fragment.
     *
     * @return string
     *
     * @spec openspec/changes/xwiki-integration/tasks.md#1.1
     */
    public function sanitiseHtml(string $html): string
    {
        if ($html === '') {
            return '';
        }

        // Strip <script>...</script> and <style>...</style>.
        $clean = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html) ?? '';

        // Strip on*="..." / on*='...' event handlers.
        $clean = preg_replace('#\s+on[a-z]+\s*=\s*"[^"]*"#i', '', $clean) ?? $clean;
        $clean = preg_replace("#\\s+on[a-z]+\\s*=\\s*'[^']*'#i", '', $clean) ?? $clean;

        // Strip javascript: hrefs/src.
        $clean = preg_replace('#(href|src)\s*=\s*"\s*javascript:[^"]*"#i', '$1="#"', $clean) ?? $clean;
        $clean = preg_replace("#(href|src)\\s*=\\s*'\\s*javascript:[^']*'#i", '$1="#"', $clean) ?? $clean;

        return $clean;
    }//end sanitiseHtml()

    /**
     * Build an empty result envelope.
     *
     * @param int $limit  Limit.
     * @param int $offset Offset.
     *
     * @return array<string, mixed>
     */
    private function emptyResult(int $limit, int $offset): array
    {
        return ['results' => [], 'total' => 0, 'limit' => $limit, 'offset' => $offset];
    }//end emptyResult()

    /**
     * Convert a single SimpleXML result node into an array.
     *
     * @param \SimpleXMLElement $node Search result node.
     *
     * @return array<string, mixed>
     */
    private function resultNodeToArray(\SimpleXMLElement $node): array
    {
        $space = (string) ($node->space ?? '');
        $title = (string) ($node->title ?? '');
        $id    = (string) ($node->id ?? '');
        if ($title === '' && $id !== '') {
            $title = $id;
        }

        return [
            'id'       => $id,
            'title'    => $title,
            'space'    => $space,
            'modified' => (string) ($node->modified ?? ''),
            'url'      => (string) ($node->pageUrl ?? $node->xwikiAbsoluteUrl ?? ''),
            'tags'     => [],
        ];
    }//end resultNodeToArray()
}//end class
