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
use ReflectionMethod;

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
     * Invoke the private deepMergeConfig via reflection.
     *
     * @param array $base     The base config.
     * @param array $override The fragment.
     *
     * @return array The merged config.
     */
    private function deepMerge(array $base, array $override): array
    {
        $method = new ReflectionMethod(ConfigFileLoaderService::class, 'deepMergeConfig');
        $method->setAccessible(true);

        return $method->invoke(null, $base, $override, '');
    }//end deepMerge()

    /**
     * A fragment adding a schema to the register membership list must union,
     * not replace, the monolith's schemas[] (ADR-037).
     *
     * @return void
     */
    public function testRegisterSchemasAreUnionedNotReplaced(): void
    {
        $base = [
            'components' => [
                'registers' => [
                    'pipelinq' => ['slug' => 'pipelinq', 'schemas' => ['client', 'contact']],
                ],
            ],
        ];

        $fragment = [
            'components' => [
                'registers' => [
                    'pipelinq' => ['schemas' => ['avgVerzoek', 'bewijsItem']],
                ],
            ],
        ];

        $merged = $this->deepMerge($base, $fragment);
        $schemas = $merged['components']['registers']['pipelinq']['schemas'];

        $this->assertSame(['client', 'contact', 'avgVerzoek', 'bewijsItem'], $schemas);
        // The monolith register's own keys survive the merge.
        $this->assertSame('pipelinq', $merged['components']['registers']['pipelinq']['slug']);
    }//end testRegisterSchemasAreUnionedNotReplaced()

    /**
     * A schema already declared by the monolith and re-declared by a fragment
     * is not duplicated in the membership list.
     *
     * @return void
     */
    public function testRegisterSchemasUnionDeduplicates(): void
    {
        $base = [
            'components' => ['registers' => ['pipelinq' => ['schemas' => ['client', 'contact']]]],
        ];
        $fragment = [
            'components' => ['registers' => ['pipelinq' => ['schemas' => ['contact', 'avgVerzoek']]]],
        ];

        $merged = $this->deepMerge($base, $fragment);

        $this->assertSame(['client', 'contact', 'avgVerzoek'], $merged['components']['registers']['pipelinq']['schemas']);
    }//end testRegisterSchemasUnionDeduplicates()

    /**
     * A fragment contributing seed objects must append to components.objects[]
     * rather than replace the monolith's seeds (ADR-037).
     *
     * @return void
     */
    public function testObjectsAreUnionedNotReplaced(): void
    {
        $base = [
            'components' => [
                'objects' => [
                    ['@self' => ['register' => 'pipelinq', 'schema' => 'client', 'slug' => 'c1']],
                ],
            ],
        ];

        $fragment = [
            'components' => [
                'objects' => [
                    ['@self' => ['register' => 'pipelinq', 'schema' => 'avgVerzoek', 'slug' => 'a1']],
                ],
            ],
        ];

        $merged = $this->deepMerge($base, $fragment);
        $objects = $merged['components']['objects'];

        $this->assertCount(2, $objects);
        $this->assertSame('c1', $objects[0]['@self']['slug']);
        $this->assertSame('a1', $objects[1]['@self']['slug']);
    }//end testObjectsAreUnionedNotReplaced()

    /**
     * An identical seed object present in both the monolith and a fragment is
     * de-duplicated by canonical content.
     *
     * @return void
     */
    public function testObjectsUnionDeduplicatesIdenticalSeeds(): void
    {
        $seed = ['@self' => ['register' => 'pipelinq', 'schema' => 'avgVerzoek', 'slug' => 'a1'], 'kenmerk' => 'AVG-1'];
        $base = ['components' => ['objects' => [$seed]]];
        $fragment = ['components' => ['objects' => [$seed]]];

        $merged = $this->deepMerge($base, $fragment);

        $this->assertCount(1, $merged['components']['objects']);
    }//end testObjectsUnionDeduplicatesIdenticalSeeds()

    /**
     * Non-union lists (e.g. a schema's required[]) keep replace semantics so a
     * fragment can intentionally override them.
     *
     * @return void
     */
    public function testNonUnionListsStillReplace(): void
    {
        $base     = ['components' => ['schemas' => ['x' => ['required' => ['a', 'b']]]]];
        $fragment = ['components' => ['schemas' => ['x' => ['required' => ['c']]]]];

        $merged = $this->deepMerge($base, $fragment);

        $this->assertSame(['c'], $merged['components']['schemas']['x']['required']);
    }//end testNonUnionListsStillReplace()
}//end class
