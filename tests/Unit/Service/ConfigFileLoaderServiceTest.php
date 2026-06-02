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
     * Invoke the private static deepMergeConfig via reflection.
     *
     * @param array $base     The base configuration.
     * @param array $override The fragment to merge.
     *
     * @return array The merged result.
     */
    private function deepMerge(array $base, array $override): array
    {
        $method = new \ReflectionMethod(ConfigFileLoaderService::class, 'deepMergeConfig');
        $method->setAccessible(true);

        return $method->invoke(null, $base, $override);
    }//end deepMerge()

    /**
     * Seed-object lists (components.objects[]) are additively unioned, not replaced.
     *
     * @return void
     */
    public function testFragmentObjectsAreUnionedNotReplaced(): void
    {
        $base = [
            'components' => [
                'objects' => [
                    ['@self' => ['slug' => 'base-a'], 'name' => 'A'],
                    ['@self' => ['slug' => 'base-b'], 'name' => 'B'],
                ],
            ],
        ];

        $override = [
            'components' => [
                'objects' => [
                    ['@self' => ['slug' => 'frag-c'], 'name' => 'C'],
                ],
            ],
        ];

        $merged = $this->deepMerge($base, $override);
        $slugs  = array_map(static fn (array $o): string => $o['@self']['slug'], $merged['components']['objects']);

        $this->assertCount(3, $merged['components']['objects']);
        $this->assertSame(['base-a', 'base-b', 'frag-c'], $slugs);
    }//end testFragmentObjectsAreUnionedNotReplaced()

    /**
     * A fragment seed object with a colliding slug replaces the base entry in place.
     *
     * @return void
     */
    public function testFragmentObjectSameSlugReplacesInPlace(): void
    {
        $base = [
            'components' => [
                'objects' => [
                    ['@self' => ['slug' => 'shared'], 'name' => 'Old'],
                    ['@self' => ['slug' => 'keep'], 'name' => 'Keep'],
                ],
            ],
        ];

        $override = [
            'components' => [
                'objects' => [
                    ['@self' => ['slug' => 'shared'], 'name' => 'New'],
                ],
            ],
        ];

        $merged = $this->deepMerge($base, $override);

        $this->assertCount(2, $merged['components']['objects']);
        $bySlug = [];
        foreach ($merged['components']['objects'] as $object) {
            $bySlug[$object['@self']['slug']] = $object['name'];
        }

        $this->assertSame('New', $bySlug['shared']);
        $this->assertSame('Keep', $bySlug['keep']);
    }//end testFragmentObjectSameSlugReplacesInPlace()

    /**
     * Register schema-membership lists (schemas[]) are unioned and de-duplicated.
     *
     * @return void
     */
    public function testFragmentRegisterSchemasAreUnioned(): void
    {
        $base = [
            'components' => [
                'registers' => [
                    'pipelinq' => ['schemas' => ['client', 'lead']],
                ],
            ],
        ];

        $override = [
            'components' => [
                'registers' => [
                    'pipelinq' => ['schemas' => ['lead', 'project', 'projectTask']],
                ],
            ],
        ];

        $merged = $this->deepMerge($base, $override);

        $this->assertSame(
            ['client', 'lead', 'project', 'projectTask'],
            $merged['components']['registers']['pipelinq']['schemas']
        );
    }//end testFragmentRegisterSchemasAreUnioned()

    /**
     * Schema definition dicts (components.schemas) still merge recursively, and
     * other lists still replace, so the union is scoped to the two known paths.
     *
     * @return void
     */
    public function testSchemaDefinitionsMergeAndOtherListsReplace(): void
    {
        $base = [
            'components' => [
                'schemas' => [
                    'client' => ['title' => 'Client'],
                ],
            ],
            'info' => ['tags' => ['old']],
        ];

        $override = [
            'components' => [
                'schemas' => [
                    'project' => ['title' => 'Project'],
                ],
            ],
            'info' => ['tags' => ['new']],
        ];

        $merged = $this->deepMerge($base, $override);

        // Schema definitions (assoc) merge: both keys present.
        $this->assertArrayHasKey('client', $merged['components']['schemas']);
        $this->assertArrayHasKey('project', $merged['components']['schemas']);
        // A non-union list (info.tags) is replaced, not unioned.
        $this->assertSame(['new'], $merged['info']['tags']);
    }//end testSchemaDefinitionsMergeAndOtherListsReplace()
}//end class
