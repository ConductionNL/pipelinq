<?php

/**
 * Unit tests for ConfigFileLoaderService.
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

use OCA\Pipelinq\Service\ConfigFileLoaderService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ConfigFileLoaderService.
 */
class ConfigFileLoaderServiceTest extends TestCase
{
    /**
     * The service under test.
     *
     * @var ConfigFileLoaderService
     */
    private ConfigFileLoaderService $service;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $appManager    = $this->createMock(IAppManager::class);
        $this->service = new ConfigFileLoaderService($appManager);
    }//end setUp()

    /**
     * Test ensureSourceType adds x-openregister if missing.
     *
     * @return void
     */
    public function testEnsureSourceTypeAddsIfMissing(): void
    {
        $data   = ['key' => 'value'];
        $result = $this->service->ensureSourceType($data);

        $this->assertSame('local', $result['x-openregister']['sourceType']);
    }//end testEnsureSourceTypeAddsIfMissing()

    /**
     * Test ensureSourceType preserves existing sourceType.
     *
     * @return void
     */
    public function testEnsureSourceTypePreservesExisting(): void
    {
        $data   = ['x-openregister' => ['sourceType' => 'remote']];
        $result = $this->service->ensureSourceType($data);

        $this->assertSame('remote', $result['x-openregister']['sourceType']);
    }//end testEnsureSourceTypePreservesExisting()

    /**
     * Test ensureSourceType adds sourceType when x-openregister exists but no sourceType.
     *
     * @return void
     */
    public function testEnsureSourceTypeAddsSourceTypeToExisting(): void
    {
        $data   = ['x-openregister' => ['other' => 'val']];
        $result = $this->service->ensureSourceType($data);

        $this->assertSame('local', $result['x-openregister']['sourceType']);
        $this->assertSame('val', $result['x-openregister']['other']);
    }//end testEnsureSourceTypeAddsSourceTypeToExisting()

    /**
     * Test loadConfigurationFile throws on missing file.
     *
     * @return void
     */
    public function testLoadConfigurationFileThrowsOnMissingFile(): void
    {
        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('getAppPath')->willReturn('/nonexistent/path');

        $service = new ConfigFileLoaderService($appManager);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Configuration file not found');

        $service->loadConfigurationFile();
    }//end testLoadConfigurationFileThrowsOnMissingFile()

    /**
     * Invoke the private deepMergeConfig() via reflection.
     *
     * @param array $base     The base configuration.
     * @param array $override The fragment to merge on top.
     *
     * @return array The merged result.
     */
    private function deepMerge(array $base, array $override): array
    {
        $method = new \ReflectionMethod(ConfigFileLoaderService::class, 'deepMergeConfig');
        $method->setAccessible(true);

        return $method->invoke(null, $base, $override, null);
    }//end deepMerge()

    /**
     * A fragment's components.objects[] must be UNIONED onto the monolith's
     * objects, not replace them (ADR-037 seed-object fleet-standard rule).
     *
     * @return void
     */
    public function testFragmentSeedObjectsAreUnionedNotReplaced(): void
    {
        $base = [
            'components' => [
                'objects' => [
                    ['@self' => ['register' => 'pipelinq', 'schema' => 'queue', 'slug' => 'queue-existing'], 'title' => 'Existing'],
                ],
            ],
        ];

        $fragment = [
            'components' => [
                'objects' => [
                    ['@self' => ['register' => 'pipelinq', 'schema' => 'queue', 'slug' => 'queue-new'], 'title' => 'New'],
                ],
            ],
        ];

        $merged = $this->deepMerge($base, $fragment);
        $slugs  = array_map(
            static fn(array $object): string => $object['@self']['slug'],
            $merged['components']['objects']
        );

        $this->assertContains('queue-existing', $slugs, 'Existing seed must survive the merge');
        $this->assertContains('queue-new', $slugs, 'Fragment seed must be added');
        $this->assertCount(2, $merged['components']['objects']);
    }//end testFragmentSeedObjectsAreUnionedNotReplaced()

    /**
     * A seed object with a matching @self identity must REPLACE the base
     * object rather than create a duplicate (idempotency by slug).
     *
     * @return void
     */
    public function testFragmentSeedObjectWithSameIdentityReplaces(): void
    {
        $base = [
            'components' => [
                'objects' => [
                    ['@self' => ['register' => 'pipelinq', 'schema' => 'skill', 'slug' => 'skill-a'], 'title' => 'Old'],
                ],
            ],
        ];

        $fragment = [
            'components' => [
                'objects' => [
                    ['@self' => ['register' => 'pipelinq', 'schema' => 'skill', 'slug' => 'skill-a'], 'title' => 'New'],
                ],
            ],
        ];

        $merged = $this->deepMerge($base, $fragment);

        $this->assertCount(1, $merged['components']['objects'], 'Same identity must not duplicate');
        $this->assertSame('New', $merged['components']['objects'][0]['title']);
    }//end testFragmentSeedObjectWithSameIdentityReplaces()

    /**
     * A register's schemas[] membership list must be UNIONED by value so a
     * fragment can add a schema without dropping the monolith's schemas.
     *
     * @return void
     */
    public function testRegisterSchemasMembershipIsUnioned(): void
    {
        $base = [
            'components' => [
                'registers' => [
                    'pipelinq' => ['schemas' => ['client', 'request']],
                ],
            ],
        ];

        $fragment = [
            'components' => [
                'registers' => [
                    'pipelinq' => ['schemas' => ['request', 'agentProfile']],
                ],
            ],
        ];

        $merged = $this->deepMerge($base, $fragment);
        $schemas = $merged['components']['registers']['pipelinq']['schemas'];

        $this->assertSame(['client', 'request', 'agentProfile'], $schemas);
    }//end testRegisterSchemasMembershipIsUnioned()

    /**
     * Non-union lists (e.g. a schema's required[] array) must still be
     * REPLACED by the fragment value, preserving prior merge semantics.
     *
     * @return void
     */
    public function testNonUnionListsAreReplaced(): void
    {
        $base = [
            'components' => [
                'schemas' => [
                    'queue' => ['required' => ['title']],
                ],
            ],
        ];

        $fragment = [
            'components' => [
                'schemas' => [
                    'queue' => ['required' => ['title', 'isActive']],
                ],
            ],
        ];

        $merged = $this->deepMerge($base, $fragment);

        $this->assertSame(['title', 'isActive'], $merged['components']['schemas']['queue']['required']);
    }//end testNonUnionListsAreReplaced()
}//end class
