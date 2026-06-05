<?php

/**
 * Unit tests for KennisbankService (search, collections, export, versions, audit).
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

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Pipelinq\Service\KennisbankService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for KennisbankService.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class KennisbankServiceSearchTest extends TestCase
{

    /**
     * The service under test.
     *
     * @var KennisbankService
     */
    private KennisbankService $service;

    /**
     * Mock container.
     *
     * @var ContainerInterface
     */
    private ContainerInterface $container;

    /**
     * Mock app config.
     *
     * @var IAppConfig
     */
    private IAppConfig $appConfig;

    /**
     * Mock object service.
     *
     * @var ObjectService
     */
    private ObjectService $objectService;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->container     = $this->createMock(ContainerInterface::class);
        $this->appConfig     = $this->createMock(IAppConfig::class);
        $this->objectService = $this->createMock(ObjectService::class);
        $logger              = $this->createMock(LoggerInterface::class);

        $this->container->method('get')->willReturn($this->objectService);

        $this->appConfig->method('getValueString')->willReturnMap(
            [
                ['pipelinq', 'register', '', 'reg-1'],
                ['pipelinq', 'kennisartikel_schema', '', 'art-schema'],
                ['pipelinq', 'kenniscategorie_schema', '', 'cat-schema'],
                ['pipelinq', 'kennisfeedback_schema', '', 'fb-schema'],
            ]
        );

        $this->service = new KennisbankService(
            $this->container,
            $this->appConfig,
            $logger,
        );
    }//end setUp()

    /**
     * Build a realistic published+public article fixture.
     *
     * @param string   $id         The article id.
     * @param string   $title      The title.
     * @param string[] $categories The category UUIDs.
     * @param string[] $tags       The tags.
     *
     * @return array<string, mixed> The article.
     */
    private function article(string $id, string $title, array $categories = [], array $tags = []): array
    {
        return [
            'id'              => $id,
            'title'           => $title,
            'summary'         => $title.' summary',
            'body'            => 'Volledige tekst over '.$title.' bij de gemeente.',
            'status'          => 'gepubliceerd',
            'visibility'      => 'openbaar',
            'categories'      => $categories,
            'tags'            => $tags,
            'publishedAt'     => '2026-03-15T09:00:00+00:00',
            'author'          => 'redacteur-uid',
            'lastUpdatedBy'   => 'redacteur-uid',
            'zaaktypeLinks'   => ['zt-1'],
            'usefulnessScore' => 42,
        ];
    }//end article()

    // ----- searchPublicArticles -----

    /**
     * Search returns matching published+public articles in the envelope shape.
     *
     * @return void
     */
    public function testSearchReturnsMatchingArticles(): void
    {
        $this->objectService->method('findAll')->willReturn(
            [
                $this->article('a1', 'Paspoort aanvragen', ['cat-burger'], ['paspoort']),
                $this->article('a2', 'Rijbewijs verlengen', ['cat-burger'], ['rijbewijs']),
            ]
        );

        $result = $this->service->searchPublicArticles('paspoort', [], [], 1, 20);

        $this->assertSame(1, $result['total']);
        $this->assertSame(1, $result['page']);
        $this->assertCount(1, $result['results']);
        $this->assertSame('Paspoort aanvragen', $result['results'][0]['title']);
    }//end testSearchReturnsMatchingArticles()

    /**
     * Search responses MUST NOT leak internal fields.
     *
     * @return void
     */
    public function testSearchStripsInternalFields(): void
    {
        $this->objectService->method('findAll')->willReturn(
            [$this->article('a1', 'Paspoort aanvragen', ['cat-burger'], ['paspoort'])]
        );

        $result = $this->service->searchPublicArticles('paspoort', [], [], 1, 20);
        $entry  = $result['results'][0];

        $this->assertArrayNotHasKey('author', $entry);
        $this->assertArrayNotHasKey('lastUpdatedBy', $entry);
        $this->assertArrayNotHasKey('zaaktypeLinks', $entry);
        $this->assertArrayNotHasKey('usefulnessScore', $entry);
        $this->assertArrayHasKey('snippet', $entry);
        $this->assertStringContainsString('<em>', $entry['snippet']);
    }//end testSearchStripsInternalFields()

    /**
     * Search applies the tag filter.
     *
     * @return void
     */
    public function testSearchFiltersByTag(): void
    {
        $this->objectService->method('findAll')->willReturn(
            [
                $this->article('a1', 'Paspoort aanvragen', ['cat-burger'], ['paspoort']),
                $this->article('a2', 'Rijbewijs verlengen', ['cat-burger'], ['rijbewijs']),
            ]
        );

        $result = $this->service->searchPublicArticles('', [], ['rijbewijs'], 1, 20);

        $this->assertSame(1, $result['total']);
        $this->assertSame('Rijbewijs verlengen', $result['results'][0]['title']);
    }//end testSearchFiltersByTag()

    /**
     * Search paginates the result set.
     *
     * @return void
     */
    public function testSearchPaginates(): void
    {
        $this->objectService->method('findAll')->willReturn(
            [
                $this->article('a1', 'Artikel een'),
                $this->article('a2', 'Artikel twee'),
                $this->article('a3', 'Artikel drie'),
            ]
        );

        $result = $this->service->searchPublicArticles('', [], [], 2, 2);

        $this->assertSame(3, $result['total']);
        $this->assertSame(2, $result['pages']);
        $this->assertSame(2, $result['page']);
        $this->assertCount(1, $result['results']);
    }//end testSearchPaginates()

    // ----- getCategoryTree -----

    /**
     * Category tree nests children and counts published+public articles.
     *
     * @return void
     */
    public function testCategoryTreeBuildsNestedCountsAndChildren(): void
    {
        $categories = [
            ['id' => 'cat-burger', 'name' => 'Burgerzaken', 'slug' => 'burgerzaken', 'parent' => null, 'order' => 1],
            ['id' => 'cat-reis', 'name' => 'Reisdocumenten', 'slug' => 'reisdocumenten', 'parent' => 'cat-burger', 'order' => 2],
        ];
        $articles   = [
            $this->article('a1', 'Paspoort aanvragen', ['cat-reis'], ['paspoort']),
            $this->article('a2', 'Verhuizen doorgeven', ['cat-burger'], ['verhuizen']),
        ];

        $this->objectService->method('findAll')->willReturnOnConsecutiveCalls($categories, $articles);

        $tree = $this->service->getCategoryTree();

        $this->assertCount(1, $tree);
        $this->assertSame('Burgerzaken', $tree[0]['name']);
        $this->assertSame(1, $tree[0]['articleCount']);
        $this->assertCount(1, $tree[0]['children']);
        $this->assertSame('Reisdocumenten', $tree[0]['children'][0]['name']);
        $this->assertSame(1, $tree[0]['children'][0]['articleCount']);
    }//end testCategoryTreeBuildsNestedCountsAndChildren()

    // ----- getArticlesByCategory -----

    /**
     * Unknown slug returns null (404 at the controller).
     *
     * @return void
     */
    public function testArticlesByCategoryReturnsNullForUnknownSlug(): void
    {
        $this->objectService->method('findAll')->willReturn([]);

        $this->assertNull($this->service->getArticlesByCategory('does-not-exist', 1, 20));
    }//end testArticlesByCategoryReturnsNullForUnknownSlug()

    /**
     * Known slug returns only articles in that category.
     *
     * @return void
     */
    public function testArticlesByCategoryFiltersByResolvedId(): void
    {
        $this->objectService->method('findAll')->willReturnCallback(
            function (array $config) {
                $schema = ($config['filters']['schema'] ?? '');
                if ($schema === 'cat-schema') {
                    return [['id' => 'cat-burger', 'slug' => 'burgerzaken']];
                }

                return [
                    $this->article('a1', 'Paspoort aanvragen', ['cat-burger'], ['paspoort']),
                    $this->article('a2', 'Iets anders', ['cat-other'], ['x']),
                ];
            }
        );

        $result = $this->service->getArticlesByCategory('burgerzaken', 1, 20);

        $this->assertNotNull($result);
        $this->assertSame(1, $result['total']);
        $this->assertSame('Paspoort aanvragen', $result['results'][0]['title']);
    }//end testArticlesByCategoryFiltersByResolvedId()

    // ----- exportArticles -----

    /**
     * JSON export returns the articles serialised as JSON.
     *
     * @return void
     */
    public function testExportJson(): void
    {
        $this->objectService->method('findAll')->willReturn(
            [$this->article('a1', 'Paspoort aanvragen')]
        );

        $export = $this->service->exportArticles('json', []);

        $this->assertSame('application/json', $export['contentType']);
        $decoded = json_decode($export['body'], true);
        $this->assertSame('Paspoort aanvragen', $decoded[0]['title']);
    }//end testExportJson()

    /**
     * CSV export returns a header row and a flattened data row.
     *
     * @return void
     */
    public function testExportCsvFlattensArrays(): void
    {
        $this->objectService->method('findAll')->willReturn(
            [$this->article('a1', 'Paspoort aanvragen', ['cat-burger'], ['paspoort'])]
        );

        $export = $this->service->exportArticles('csv', []);

        $this->assertSame('text/csv', $export['contentType']);
        $this->assertStringContainsString('title', $export['body']);
        $this->assertStringContainsString('Paspoort aanvragen', $export['body']);
    }//end testExportCsvFlattensArrays()

    /**
     * Unsupported export format throws.
     *
     * @return void
     */
    public function testExportRejectsUnknownFormat(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->exportArticles('xml', []);
    }//end testExportRejectsUnknownFormat()

    // ----- getArticleVersions / compareVersions -----

    /**
     * Versions map audit-trail entries to the version list shape.
     *
     * @return void
     */
    public function testGetArticleVersionsMapsAuditTrail(): void
    {
        $this->objectService->method('find')->willReturn(['id' => 'a1']);
        $this->objectService->method('getLogs')->willReturn(
            [
                ['version' => 2, 'created' => '2026-03-16T10:00:00+00:00', 'user' => 'u1', 'action' => 'update', 'changed' => []],
                ['version' => 1, 'created' => '2026-03-15T09:00:00+00:00', 'user' => 'u1', 'action' => 'create', 'changed' => []],
            ]
        );

        $versions = $this->service->getArticleVersions('a1');

        $this->assertNotNull($versions);
        $this->assertCount(2, $versions);
        $this->assertSame(2, $versions[0]['version']);
        $this->assertSame('update', $versions[0]['changeType']);
        $this->assertSame('u1', $versions[0]['editedBy']);
    }//end testGetArticleVersionsMapsAuditTrail()

    /**
     * Versions for a non-existent article return null.
     *
     * @return void
     */
    public function testGetArticleVersionsReturnsNullWhenMissing(): void
    {
        $this->objectService->method('find')->willReturn(null);

        $this->assertNull($this->service->getArticleVersions('nope'));
    }//end testGetArticleVersionsReturnsNullWhenMissing()

    /**
     * Compare computes a field-level diff between two versions.
     *
     * @return void
     */
    public function testCompareVersionsComputesDiff(): void
    {
        $this->objectService->method('find')->willReturn(['id' => 'a1']);
        $this->objectService->method('getLogs')->willReturn(
            [
                ['version' => 2, 'created' => '2026-03-16T10:00:00+00:00', 'changed' => ['title' => ['old' => 'Oud', 'new' => 'Nieuw']]],
                ['version' => 1, 'created' => '2026-03-15T09:00:00+00:00', 'changed' => ['title' => ['old' => 'Eerste', 'new' => 'Oud']]],
            ]
        );

        $diff = $this->service->compareVersions('a1', 1, 2);

        $this->assertNotNull($diff);
        $this->assertSame(1, $diff['from']['version']);
        $this->assertSame(2, $diff['to']['version']);
        $this->assertCount(1, $diff['diff']);
        $this->assertSame('title', $diff['diff'][0]['field']);
        $this->assertSame('Eerste', $diff['diff'][0]['before']);
        $this->assertSame('Nieuw', $diff['diff'][0]['after']);
    }//end testCompareVersionsComputesDiff()

    /**
     * Compare throws when a requested version is absent.
     *
     * @return void
     */
    public function testCompareVersionsThrowsForUnknownVersion(): void
    {
        $this->objectService->method('find')->willReturn(['id' => 'a1']);
        $this->objectService->method('getLogs')->willReturn(
            [['version' => 1, 'created' => '2026-03-15T09:00:00+00:00', 'changed' => []]]
        );

        $this->expectException(\OutOfRangeException::class);
        $this->service->compareVersions('a1', 1, 9);
    }//end testCompareVersionsThrowsForUnknownVersion()

    // ----- getAuditLog -----

    /**
     * Audit log aggregates entries across schemas and applies the action filter.
     *
     * @return void
     */
    public function testAuditLogAggregatesAndFiltersByAction(): void
    {
        $this->objectService->method('findAll')->willReturnCallback(
            function (array $config) {
                $schema = ($config['filters']['schema'] ?? '');
                if ($schema === 'art-schema') {
                    return [['id' => 'a1']];
                }

                return [];
            }
        );

        $this->objectService->method('getLogs')->willReturn(
            [
                ['version' => 2, 'created' => '2026-03-16T10:00:00+00:00', 'user' => 'u1', 'action' => 'update'],
                ['version' => 1, 'created' => '2026-03-15T09:00:00+00:00', 'user' => 'u1', 'action' => 'create'],
            ]
        );

        $result = $this->service->getAuditLog(['action' => 'create'], 1, 20);

        $this->assertSame(1, $result['total']);
        $this->assertSame('create', $result['results'][0]['action']);
        $this->assertSame('kennisartikel', $result['results'][0]['schemaSlug']);
    }//end testAuditLogAggregatesAndFiltersByAction()

    /**
     * The object service throws when OpenRegister is unavailable.
     *
     * @return void
     */
    public function testGetObjectServiceThrowsWhenUnavailable(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willThrowException(new \Exception('missing'));
        $service = new KennisbankService($container, $this->appConfig, $this->createMock(LoggerInterface::class));

        $this->expectException(\RuntimeException::class);
        $service->getObjectService();
    }//end testGetObjectServiceThrowsWhenUnavailable()
}//end class
