<?php

/**
 * Pipelinq KennisbankService.
 *
 * Service for the knowledge base (kennisbank) REST API: public full-text search,
 * category collections, bulk export, article version history and diffing, and the
 * compliance audit log. All heavy lifting delegates to OpenRegister's ObjectService
 * (find/findAll/getLogs); this service only adds public-visibility filtering and
 * internal-field stripping.
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
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/kennisbank/tasks.md#task-1
 * @spec openspec/changes/kennisbank/tasks.md#task-2
 * @spec openspec/changes/kennisbank/tasks.md#task-3
 * @spec openspec/changes/kennisbank/tasks.md#task-4
 * @spec openspec/changes/kennisbank/tasks.md#task-5
 * @spec openspec/changes/kennisbank/tasks.md#task-6
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for knowledge base API operations.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/kennisbank/tasks.md#task-1
 */
class KennisbankService
{

    /**
     * Article lifecycle status that marks an article as published.
     *
     * @var string
     */
    public const STATUS_PUBLISHED = 'gepubliceerd';

    /**
     * Visibility value that marks an article as publicly accessible.
     *
     * @var string
     */
    public const VISIBILITY_PUBLIC = 'openbaar';

    /**
     * Constructor.
     *
     * @param ContainerInterface $container The DI container.
     * @param IAppConfig         $appConfig The app config.
     * @param LoggerInterface    $logger    The logger.
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get the OpenRegister ObjectService.
     *
     * Returns `object` rather than the concrete type because OpenRegister is a
     * cross-app dependency that is resolved dynamically from the container and is
     * not on this app's classpath at static-analysis time (mirrors the
     * established pattern in PosTransactionService / ForecastService).
     *
     * @return object The OpenRegister ObjectService.
     *
     * @throws \RuntimeException If OpenRegister is not available.
     *
     * @spec openspec/changes/kennisbank/tasks.md#task-1
     */
    public function getObjectService(): object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Exception $e) {
            throw new \RuntimeException('OpenRegister service is not available.');
        }
    }//end getObjectService()

    /**
     * Resolve the configured register id.
     *
     * @return string The register id.
     *
     * @throws \RuntimeException If not configured.
     */
    private function getRegisterId(): string
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        if ($register === '') {
            $this->logger->warning('Pipelinq kennisbank register is not configured.');
            throw new \RuntimeException('Knowledge base register is not configured.');
        }

        return $register;
    }//end getRegisterId()

    /**
     * Resolve a configured schema id by its setting key.
     *
     * @param string $schemaKey The schema config key (e.g. kennisartikel_schema).
     *
     * @return string The schema id.
     *
     * @throws \RuntimeException If not configured.
     */
    private function getSchemaId(string $schemaKey): string
    {
        $schema = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');
        if ($schema === '') {
            $this->logger->warning('Pipelinq kennisbank schema is not configured.', ['schemaKey' => $schemaKey]);
            throw new \RuntimeException('Knowledge base schema "'.$schemaKey.'" is not configured.');
        }

        return $schema;
    }//end getSchemaId()

    /**
     * Normalise an object (entity or array) returned by ObjectService to an array.
     *
     * @param mixed $object The object.
     *
     * @return array<string, mixed> The object data.
     */
    private function toArray(mixed $object): array
    {
        if (is_array($object) === true) {
            return $object;
        }

        if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
            $serialized = $object->jsonSerialize();
            if (is_array($serialized) === true) {
                return $serialized;
            }
        }

        if (is_object($object) === true && method_exists($object, 'getObject') === true) {
            $data = $object->getObject();
            if (is_array($data) === true) {
                return $data;
            }
        }

        return (array) $object;
    }//end toArray()

    /**
     * Clamp pagination arguments to safe bounds.
     *
     * @param int $page  The requested page (1-based).
     * @param int $limit The requested page size.
     *
     * @return array{0: int, 1: int} The clamped page and limit.
     */
    private function clampPaging(int $page, int $limit): array
    {
        $page  = max(1, $page);
        $limit = max(1, min(100, $limit));
        return [$page, $limit];
    }//end clampPaging()

    /**
     * Reduce a full article object to a public list entry, stripping internal fields.
     *
     * @param array<string, mixed> $article The full article data.
     * @param string|null          $query   The search query (for snippet generation).
     *
     * @return array<string, mixed> The sanitised public entry.
     */
    private function toPublicEntry(array $article, ?string $query=null): array
    {
        // Public entries are built from a strict allowlist of fields: internal
        // fields (author, lastUpdatedBy, zaaktypeLinks, usefulnessScore, body)
        // are never copied across, so they can never leak. This is a positive
        // allowlist, not a denylist.
        $entry = [
            'id'          => ($article['id'] ?? $article['uuid'] ?? ''),
            'title'       => ($article['title'] ?? ''),
            'summary'     => ($article['summary'] ?? ''),
            'categories'  => ($article['categories'] ?? []),
            'tags'        => ($article['tags'] ?? []),
            'publishedAt' => ($article['publishedAt'] ?? null),
        ];

        if ($query !== null && $query !== '') {
            $entry['snippet'] = $this->buildSnippet(body: (string) ($article['body'] ?? ($article['summary'] ?? '')), query: $query);
        }

        return $entry;
    }//end toPublicEntry()

    /**
     * Build an HTML snippet around the first match of the query in the body.
     *
     * The query term is wrapped in <em> for highlighting. Returns an empty string
     * when the body is empty.
     *
     * @param string $body  The article body (plain text).
     * @param string $query The search query.
     *
     * @return string The highlighted snippet.
     */
    private function buildSnippet(string $body, string $query): string
    {
        $body = trim(strip_tags($body));
        if ($body === '') {
            return '';
        }

        $pos = stripos($body, $query);
        if ($pos === false) {
            return mb_substr($body, 0, 160);
        }

        $start   = max(0, ($pos - 60));
        $snippet = mb_substr($body, $start, 160);

        $prefix = '';
        if ($start > 0) {
            $prefix = '...';
        }

        $snippet = $prefix.$snippet.'...';

        // Highlight the first case-insensitive occurrence only.
        $highlighted = preg_replace(
            '/('.preg_quote($query, '/').')/i',
            '<em>$1</em>',
            $snippet,
            1
        );

        return ($highlighted ?? $snippet);
    }//end buildSnippet()

    /**
     * Fetch published+public articles, optionally filtered, and return as arrays.
     *
     * @param array<string, mixed> $extraFilters Additional equality filters.
     *
     * @return array<int, array<string, mixed>> The matching article arrays.
     */
    private function fetchPublicArticles(array $extraFilters=[]): array
    {
        $filters = array_merge(
            [
                'register'   => $this->getRegisterId(),
                'schema'     => $this->getSchemaId(schemaKey: 'kennisartikel_schema'),
                'status'     => self::STATUS_PUBLISHED,
                'visibility' => self::VISIBILITY_PUBLIC,
            ],
            $extraFilters
        );

        $results  = $this->getObjectService()->findAll(config: ['filters' => $filters]);
        $articles = [];
        foreach (($results ?? []) as $result) {
            $articles[] = $this->toArray(object: $result);
        }

        return $articles;
    }//end fetchPublicArticles()

    /**
     * Apply free-text matching over title, summary, body and tags.
     *
     * Used to narrow the published/public article set for the public search
     * endpoint. Matching is case-insensitive substring matching.
     *
     * @param array<int, array<string, mixed>> $articles The candidate articles.
     * @param string                           $query    The query string.
     *
     * @return array<int, array<string, mixed>> The matching articles.
     */
    private function applyTextMatch(array $articles, string $query): array
    {
        if ($query === '') {
            return $articles;
        }

        $needle  = mb_strtolower($query);
        $matched = [];
        foreach ($articles as $article) {
            $haystack = mb_strtolower(
                implode(
                    ' ',
                    [
                        (string) ($article['title'] ?? ''),
                        (string) ($article['summary'] ?? ''),
                        (string) ($article['body'] ?? ''),
                        implode(' ', (array) ($article['tags'] ?? [])),
                    ]
                )
            );

            if (str_contains($haystack, $needle) === true) {
                $matched[] = $article;
            }
        }

        return $matched;
    }//end applyTextMatch()

    /**
     * Full-text search over published+public articles.
     *
     * Filters status=gepubliceerd AND visibility=openbaar, applies optional
     * category and tag filters, free-text matching, pagination and strips
     * internal fields before returning.
     *
     * @param string   $query      The search query.
     * @param string[] $categories Optional category UUID filter (any-of).
     * @param string[] $tags       Optional tag filter (any-of).
     * @param int      $page       The page (1-based).
     * @param int      $limit      The page size.
     *
     * @return array{total: int, page: int, pages: int, results: array<int, array<string, mixed>>} The result page.
     *
     * @spec openspec/changes/kennisbank/tasks.md#task-1
     */
    public function searchPublicArticles(string $query, array $categories, array $tags, int $page, int $limit): array
    {
        [$page, $limit] = $this->clampPaging(page: $page, limit: $limit);

        $articles = $this->fetchPublicArticles();
        $articles = $this->applyTextMatch(articles: $articles, query: trim($query));

        if (empty($categories) === false) {
            $articles = array_values(
                array_filter(
                    $articles,
                    static function (array $a) use ($categories): bool {
                        return count(array_intersect((array) ($a['categories'] ?? []), $categories)) > 0;
                    }
                )
            );
        }

        if (empty($tags) === false) {
            $articles = array_values(
                array_filter(
                    $articles,
                    static function (array $a) use ($tags): bool {
                        return count(array_intersect((array) ($a['tags'] ?? []), $tags)) > 0;
                    }
                )
            );
        }

        return $this->paginatePublic(articles: $articles, page: $page, limit: $limit, query: trim($query));
    }//end searchPublicArticles()

    /**
     * Paginate and sanitise a list of articles into the public response envelope.
     *
     * @param array<int, array<string, mixed>> $articles The matching articles.
     * @param int                              $page     The page (1-based, clamped).
     * @param int                              $limit    The page size (clamped).
     * @param string|null                      $query    Optional query for snippets.
     *
     * @return array{total: int, page: int, pages: int, results: array<int, array<string, mixed>>} The page.
     */
    private function paginatePublic(array $articles, int $page, int $limit, ?string $query=null): array
    {
        $total = count($articles);
        $pages = (int) ceil($total / $limit);
        $slice = array_slice($articles, (($page - 1) * $limit), $limit);

        $results = [];
        foreach ($slice as $article) {
            $results[] = $this->toPublicEntry(article: $article, query: $query);
        }

        return [
            'total'   => $total,
            'page'    => $page,
            'pages'   => $pages,
            'results' => $results,
        ];
    }//end paginatePublic()

    /**
     * Build the public category tree with published+public article counts.
     *
     * @return array<int, array<string, mixed>> The nested category tree.
     *
     * @spec openspec/changes/kennisbank/tasks.md#task-2
     */
    public function getCategoryTree(): array
    {
        $catResults = $this->getObjectService()->findAll(
            config: [
                'filters' => [
                    'register' => $this->getRegisterId(),
                    'schema'   => $this->getSchemaId(schemaKey: 'kenniscategorie_schema'),
                ],
            ]
        );

        $categories = [];
        foreach (($catResults ?? []) as $result) {
            $categories[] = $this->toArray(object: $result);
        }

        // Pre-compute published+public article counts per category UUID.
        $articles = $this->fetchPublicArticles();
        $counts   = [];
        foreach ($articles as $article) {
            foreach ((array) ($article['categories'] ?? []) as $catId) {
                $counts[(string) $catId] = (($counts[(string) $catId] ?? 0) + 1);
            }
        }

        return $this->buildTree(categories: $categories, counts: $counts, parent: null);
    }//end getCategoryTree()

    /**
     * Recursively assemble a category tree node list for a given parent.
     *
     * @param array<int, array<string, mixed>> $categories All category records.
     * @param array<string, int>               $counts     Article counts keyed by category UUID.
     * @param string|null                      $parent     The parent UUID (null = roots).
     *
     * @return array<int, array<string, mixed>> The nodes at this level.
     */
    private function buildTree(array $categories, array $counts, ?string $parent): array
    {
        $nodes = [];
        foreach ($categories as $category) {
            $catParent = ($category['parent'] ?? null);
            if ($catParent === '') {
                $catParent = null;
            }

            if ($catParent !== $parent) {
                continue;
            }

            $id      = (string) ($category['id'] ?? $category['uuid'] ?? '');
            $nodes[] = [
                'id'           => $id,
                'name'         => ($category['name'] ?? ''),
                'slug'         => ($category['slug'] ?? ''),
                'description'  => ($category['description'] ?? ''),
                'icon'         => ($category['icon'] ?? ''),
                'order'        => ($category['order'] ?? 0),
                'articleCount' => ($counts[$id] ?? 0),
                'children'     => $this->buildTree(categories: $categories, counts: $counts, parent: $id),
            ];
        }//end foreach

        usort(
            $nodes,
            static function (array $a, array $b): int {
                return ((int) $a['order'] <=> (int) $b['order']);
            }
        );

        return $nodes;
    }//end buildTree()

    /**
     * Resolve a category slug to its UUID.
     *
     * @param string $slug The category slug.
     *
     * @return string|null The category UUID, or null when unknown.
     */
    private function resolveCategoryId(string $slug): ?string
    {
        $results = $this->getObjectService()->findAll(
            config: [
                'filters' => [
                    'register' => $this->getRegisterId(),
                    'schema'   => $this->getSchemaId(schemaKey: 'kenniscategorie_schema'),
                    'slug'     => $slug,
                ],
                'limit'   => 1,
            ]
        );

        foreach (($results ?? []) as $result) {
            $category = $this->toArray(object: $result);
            return (string) ($category['id'] ?? $category['uuid'] ?? '');
        }

        return null;
    }//end resolveCategoryId()

    /**
     * List published+public articles within a category, by slug.
     *
     * @param string $slug  The category slug.
     * @param int    $page  The page (1-based).
     * @param int    $limit The page size.
     *
     * @return array{total: int, page: int, pages: int, results: array<int, array<string, mixed>>}|null The page, or null when slug unknown.
     *
     * @spec openspec/changes/kennisbank/tasks.md#task-2
     */
    public function getArticlesByCategory(string $slug, int $page, int $limit): ?array
    {
        [$page, $limit] = $this->clampPaging(page: $page, limit: $limit);

        $categoryId = $this->resolveCategoryId(slug: $slug);
        if ($categoryId === null || $categoryId === '') {
            return null;
        }

        $articles = $this->fetchPublicArticles();
        $articles = array_values(
            array_filter(
                $articles,
                static function (array $a) use ($categoryId): bool {
                    return in_array($categoryId, (array) ($a['categories'] ?? []), true);
                }
            )
        );

        return $this->paginatePublic(articles: $articles, page: $page, limit: $limit);
    }//end getArticlesByCategory()

    /**
     * Export articles in the requested format.
     *
     * Authorization (admin) is enforced by the controller before this is called.
     *
     * @param string               $format  The export format: json | csv.
     * @param array<string, mixed> $filters Optional equality filters (e.g. status).
     *
     * @return array{contentType: string, filename: string, body: string} The export payload.
     *
     * @throws \InvalidArgumentException If the format is unsupported.
     *
     * @spec openspec/changes/kennisbank/tasks.md#task-3
     */
    public function exportArticles(string $format, array $filters): array
    {
        $format = strtolower($format);
        if (in_array($format, ['json', 'csv'], true) === false) {
            throw new \InvalidArgumentException('Unsupported export format.');
        }

        $baseFilters = array_merge(
            [
                'register' => $this->getRegisterId(),
                'schema'   => $this->getSchemaId(schemaKey: 'kennisartikel_schema'),
            ],
            $filters
        );

        $results  = $this->getObjectService()->findAll(config: ['filters' => $baseFilters]);
        $articles = [];
        foreach (($results ?? []) as $result) {
            $articles[] = $this->toArray(object: $result);
        }

        if ($format === 'json') {
            return [
                'contentType' => 'application/json',
                'filename'    => 'kennisartikelen.json',
                'body'        => (string) json_encode($articles, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            ];
        }

        return [
            'contentType' => 'text/csv',
            'filename'    => 'kennisartikelen.csv',
            'body'        => $this->toCsv(rows: $articles),
        ];
    }//end exportArticles()

    /**
     * Serialise a list of flat article arrays to CSV.
     *
     * Nested array/object values are JSON-encoded so the CSV stays flat. The
     * header row is the union of all top-level keys across the rows.
     *
     * @param array<int, array<string, mixed>> $rows The article rows.
     *
     * @return string The CSV document.
     */
    private function toCsv(array $rows): string
    {
        if (empty($rows) === true) {
            return '';
        }

        $columns = [];
        foreach ($rows as $row) {
            foreach (array_keys($row) as $key) {
                $columns[$key] = true;
            }
        }

        $columns = array_keys($columns);
        $handle  = fopen('php://temp', 'r+');
        fputcsv($handle, $columns);

        foreach ($rows as $row) {
            $line = [];
            foreach ($columns as $column) {
                $value = ($row[$column] ?? '');
                if (is_array($value) === true || is_object($value) === true) {
                    $value = (string) json_encode($value, JSON_UNESCAPED_UNICODE);
                }

                $line[] = $value;
            }

            fputcsv($handle, $line);
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }//end toCsv()

    /**
     * Fetch the raw audit trail entries for an article.
     *
     * @param string $id The article UUID.
     *
     * @return array<int, array<string, mixed>>|null The audit entries (newest first), or null when the article does not exist.
     */
    private function fetchArticleLogs(string $id): ?array
    {
        $register = $this->getRegisterId();
        $schema   = $this->getSchemaId(schemaKey: 'kennisartikel_schema');

        $object = $this->getObjectService()->find(id: $id, register: $register, schema: $schema);
        if ($object === null) {
            return null;
        }

        $logs    = $this->getObjectService()->getLogs(uuid: $id);
        $entries = [];
        foreach (($logs ?? []) as $log) {
            $entries[] = $this->toArray(object: $log);
        }

        return $entries;
    }//end fetchArticleLogs()

    /**
     * List the version history of an article from its audit trail.
     *
     * @param string $id The article UUID.
     *
     * @return array<int, array<string, mixed>>|null The version list, or null when the article does not exist.
     *
     * @spec openspec/changes/kennisbank/tasks.md#task-4
     */
    public function getArticleVersions(string $id): ?array
    {
        $entries = $this->fetchArticleLogs(id: $id);
        if ($entries === null) {
            return null;
        }

        $versions = [];
        foreach ($entries as $entry) {
            $versions[] = [
                'version'    => ($entry['version'] ?? null),
                'editedAt'   => ($entry['created'] ?? null),
                'editedBy'   => ($entry['user'] ?? null),
                'changeType' => ($entry['action'] ?? null),
            ];
        }

        return $versions;
    }//end getArticleVersions()

    /**
     * Compute a field-level diff between two article versions.
     *
     * @param string $id          The article UUID.
     * @param int    $fromVersion The base version number.
     * @param int    $toVersion   The target version number.
     *
     * @return array<string, mixed>|null The diff structure, or null when the article does not exist.
     *
     * @throws \OutOfRangeException If either version is not present in the audit trail.
     *
     * @spec openspec/changes/kennisbank/tasks.md#task-5
     */
    public function compareVersions(string $id, int $fromVersion, int $toVersion): ?array
    {
        $entries = $this->fetchArticleLogs(id: $id);
        if ($entries === null) {
            return null;
        }

        $byVersion = [];
        foreach ($entries as $entry) {
            $byVersion[(string) ($entry['version'] ?? '')] = $entry;
        }

        $fromKey = (string) $fromVersion;
        $toKey   = (string) $toVersion;
        if (isset($byVersion[$fromKey]) === false || isset($byVersion[$toKey]) === false) {
            throw new \OutOfRangeException('Requested version not found in the audit trail.');
        }

        return [
            'from' => [
                'version'  => $fromVersion,
                'editedAt' => ($byVersion[$fromKey]['created'] ?? null),
            ],
            'to'   => [
                'version'  => $toVersion,
                'editedAt' => ($byVersion[$toKey]['created'] ?? null),
            ],
            'diff' => $this->buildDiff(from: $byVersion[$fromKey], to: $byVersion[$toKey]),
        ];
    }//end compareVersions()

    /**
     * Build a field-level diff from two audit entries.
     *
     * Each audit `changed` entry is a map of field => {old, new}. We reconcile the
     * two snapshots into a single { field, before, after } list spanning the range.
     *
     * @param array<string, mixed> $from The base audit entry.
     * @param array<string, mixed> $to   The target audit entry.
     *
     * @return array<int, array{field: string, before: mixed, after: mixed}> The diff.
     */
    private function buildDiff(array $from, array $to): array
    {
        $fromChanged = (array) ($from['changed'] ?? []);
        $toChanged   = (array) ($to['changed'] ?? []);

        $fields = array_unique(array_merge(array_keys($fromChanged), array_keys($toChanged)));

        $diff = [];
        foreach ($fields as $field) {
            // Use the "old" value of the base snapshot as the before, and the
            // "new" value of the target snapshot as the after.
            $before = ($fromChanged[$field]['old'] ?? ($toChanged[$field]['old'] ?? null));
            $after  = ($toChanged[$field]['new'] ?? ($fromChanged[$field]['new'] ?? null));

            if ($before === $after) {
                continue;
            }

            $diff[] = [
                'field'  => (string) $field,
                'before' => $before,
                'after'  => $after,
            ];
        }

        return $diff;
    }//end buildDiff()

    /**
     * Fetch the consolidated audit log across all knowledge base schemas.
     *
     * Authorization (admin) is enforced by the controller before this is called.
     *
     * @param array<string, mixed> $filters Filters: schema, action, actor, dateFrom, dateTo.
     * @param int                  $page    The page (1-based).
     * @param int                  $limit   The page size.
     *
     * @return array{total: int, page: int, pages: int, results: array<int, array<string, mixed>>} The audit page.
     *
     * @spec openspec/changes/kennisbank/tasks.md#task-6
     */
    public function getAuditLog(array $filters, int $page, int $limit): array
    {
        [$page, $limit] = $this->clampPaging(page: $page, limit: $limit);

        $schemaMap = [
            'kennisartikel'   => $this->getSchemaId(schemaKey: 'kennisartikel_schema'),
            'kenniscategorie' => $this->getSchemaId(schemaKey: 'kenniscategorie_schema'),
            'kennisfeedback'  => $this->getSchemaId(schemaKey: 'kennisfeedback_schema'),
        ];

        $wantSchema = ($filters['schema'] ?? '');
        $entries    = [];
        foreach ($schemaMap as $slug => $schemaId) {
            if ($wantSchema !== '' && $wantSchema !== $slug) {
                continue;
            }

            foreach ($this->collectSchemaAudit(schemaSlug: $slug, schemaId: $schemaId) as $entry) {
                $entries[] = $entry;
            }
        }

        $entries = $this->filterAuditEntries(entries: $entries, filters: $filters);

        // Sort newest-first by created timestamp.
        usort(
            $entries,
            static function (array $a, array $b): int {
                return strcmp((string) ($b['created'] ?? ''), (string) ($a['created'] ?? ''));
            }
        );

        $total = count($entries);
        $pages = (int) ceil($total / $limit);
        $slice = array_slice($entries, (($page - 1) * $limit), $limit);

        return [
            'total'   => $total,
            'page'    => $page,
            'pages'   => $pages,
            'results' => array_values($slice),
        ];
    }//end getAuditLog()

    /**
     * Collect audit entries for every object of a given schema.
     *
     * @param string $schemaSlug The schema slug label.
     * @param string $schemaId   The schema id.
     *
     * @return array<int, array<string, mixed>> The audit entries with a `schemaSlug` label.
     */
    private function collectSchemaAudit(string $schemaSlug, string $schemaId): array
    {
        $objects = $this->getObjectService()->findAll(
            config: [
                'filters' => [
                    'register' => $this->getRegisterId(),
                    'schema'   => $schemaId,
                ],
            ]
        );

        $entries = [];
        foreach (($objects ?? []) as $object) {
            $data = $this->toArray(object: $object);
            $uuid = (string) ($data['id'] ?? $data['uuid'] ?? '');
            if ($uuid === '') {
                continue;
            }

            $logs = $this->getObjectService()->getLogs(uuid: $uuid);
            foreach (($logs ?? []) as $log) {
                $entry = $this->toArray(object: $log);
                $entry['schemaSlug'] = $schemaSlug;
                $entries[]           = $entry;
            }
        }

        return $entries;
    }//end collectSchemaAudit()

    /**
     * Apply action/actor/date filters to a list of audit entries.
     *
     * @param array<int, array<string, mixed>> $entries The audit entries.
     * @param array<string, mixed>             $filters The filter set.
     *
     * @return array<int, array<string, mixed>> The filtered entries.
     */
    private function filterAuditEntries(array $entries, array $filters): array
    {
        return array_values(
            array_filter(
                $entries,
                function (array $entry) use ($filters): bool {
                    return $this->auditEntryMatches(entry: $entry, filters: $filters);
                }
            )
        );
    }//end filterAuditEntries()

    /**
     * Test whether a single audit entry satisfies the filter set.
     *
     * @param array<string, mixed> $entry   The audit entry.
     * @param array<string, mixed> $filters The filter set (action, actor, dateFrom, dateTo).
     *
     * @return bool True when the entry passes all active filters.
     */
    private function auditEntryMatches(array $entry, array $filters): bool
    {
        $action = ($filters['action'] ?? '');
        if ($action !== '' && ($entry['action'] ?? '') !== $action) {
            return false;
        }

        $actor = ($filters['actor'] ?? '');
        if ($actor !== '' && ($entry['user'] ?? '') !== $actor) {
            return false;
        }

        return $this->auditEntryInDateRange(
            created: (string) ($entry['created'] ?? ''),
            dateFrom: (string) ($filters['dateFrom'] ?? ''),
            dateTo: (string) ($filters['dateTo'] ?? ''),
        );
    }//end auditEntryMatches()

    /**
     * Test whether an audit entry's created timestamp falls within the range.
     *
     * @param string $created  The entry's created timestamp.
     * @param string $dateFrom The inclusive lower bound (empty = no bound).
     * @param string $dateTo   The inclusive upper bound (empty = no bound).
     *
     * @return bool True when within range (or when no bounds apply).
     */
    private function auditEntryInDateRange(string $created, string $dateFrom, string $dateTo): bool
    {
        if ($created === '') {
            return true;
        }

        if ($dateFrom !== '' && strtotime($created) < strtotime($dateFrom)) {
            return false;
        }

        if ($dateTo !== '' && strtotime($created) > strtotime($dateTo)) {
            return false;
        }

        return true;
    }//end auditEntryInDateRange()
}//end class
