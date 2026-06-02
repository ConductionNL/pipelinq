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
    private function invokeDeepMerge(array $base, array $override): array
    {
        $method = new \ReflectionMethod(ConfigFileLoaderService::class, 'deepMergeConfig');
        $method->setAccessible(true);

        return $method->invoke(null, $base, $override);
    }//end invokeDeepMerge()

    /**
     * Seed objects lists (components.objects[]) must be CONCATENATED, not
     * replaced, so a fragment's seeds extend the monolith's seeds (ADR-037).
     *
     * @return void
     */
    public function testDeepMergeAppendsSeedObjects(): void
    {
        $base = [
            'components' => [
                'objects' => [
                    ['@self' => ['slug' => 'base-1']],
                    ['@self' => ['slug' => 'base-2']],
                ],
            ],
        ];

        $override = [
            'components' => [
                'objects' => [
                    ['@self' => ['slug' => 'frag-1']],
                ],
            ],
        ];

        $merged = $this->invokeDeepMerge($base, $override);
        $slugs  = array_map(
            static function (array $object): string {
                return $object['@self']['slug'];
            },
            $merged['components']['objects']
        );

        $this->assertSame(['base-1', 'base-2', 'frag-1'], $slugs);
    }//end testDeepMergeAppendsSeedObjects()

    /**
     * A fragment may seed objects even when the base has none.
     *
     * @return void
     */
    public function testDeepMergeAddsSeedObjectsWhenBaseEmpty(): void
    {
        $base     = ['components' => ['schemas' => ['a' => ['type' => 'object']]]];
        $override = ['components' => ['objects' => [['@self' => ['slug' => 'frag-1']]]]];

        $merged = $this->invokeDeepMerge($base, $override);

        $this->assertCount(1, $merged['components']['objects']);
        $this->assertArrayHasKey('a', $merged['components']['schemas']);
    }//end testDeepMergeAddsSeedObjectsWhenBaseEmpty()

    /**
     * Non-object list values other than `objects` keep replace semantics, and
     * associative keys still merge recursively.
     *
     * @return void
     */
    public function testDeepMergeReplacesOtherListsButMergesMaps(): void
    {
        $base = [
            'info'       => ['version' => '1.0.0', 'title' => 'Base'],
            'components' => ['required' => ['a', 'b']],
        ];

        $override = [
            'info'       => ['version' => '2.0.0'],
            'components' => ['required' => ['c']],
        ];

        $merged = $this->invokeDeepMerge($base, $override);

        // Map keys merge recursively (title preserved, version overridden).
        $this->assertSame('2.0.0', $merged['info']['version']);
        $this->assertSame('Base', $merged['info']['title']);
        // Non-objects lists are replaced wholesale.
        $this->assertSame(['c'], $merged['components']['required']);
    }//end testDeepMergeReplacesOtherListsButMergesMaps()
}//end class
