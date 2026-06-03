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
     * Invoke the private static deepMergeConfig() via reflection.
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

        return $method->invoke(null, $base, $override, '');
    }//end deepMerge()

    /**
     * Schema-membership lists on a register MUST be unioned, not replaced (ADR-037).
     *
     * A sibling feature fragment that adds its own schemas to the pipelinq
     * register must not drop the monolith's existing schema membership.
     *
     * @return void
     */
    public function testRegisterSchemasMembershipIsUnioned(): void
    {
        $base     = [
            'components' => [
                'registers' => [
                    'pipelinq' => ['schemas' => ['client', 'contact', 'request']],
                ],
            ],
        ];
        $override = [
            'components' => [
                'registers' => [
                    'pipelinq' => ['schemas' => ['zgwEndpoint', 'zgwClient']],
                ],
            ],
        ];

        $result = $this->deepMerge($base, $override);

        $this->assertSame(
            ['client', 'contact', 'request', 'zgwEndpoint', 'zgwClient'],
            $result['components']['registers']['pipelinq']['schemas']
        );
    }//end testRegisterSchemasMembershipIsUnioned()

    /**
     * Duplicate schema-membership slugs are deduplicated on union.
     *
     * @return void
     */
    public function testRegisterSchemasMembershipDeduplicates(): void
    {
        $base     = ['components' => ['registers' => ['pipelinq' => ['schemas' => ['client', 'request']]]]];
        $override = ['components' => ['registers' => ['pipelinq' => ['schemas' => ['request', 'zgwEndpoint']]]]];

        $result = $this->deepMerge($base, $override);

        $this->assertSame(
            ['client', 'request', 'zgwEndpoint'],
            $result['components']['registers']['pipelinq']['schemas']
        );
    }//end testRegisterSchemasMembershipDeduplicates()

    /**
     * Seed-object lists MUST be unioned by @self.slug, not replaced (ADR-037).
     *
     * A fragment contributing its own seed objects must preserve the monolith's
     * 39 existing seeds; matching slugs replace in place.
     *
     * @return void
     */
    public function testSeedObjectsAreUnionedBySlug(): void
    {
        $base     = [
            'components' => [
                'objects' => [
                    ['@self' => ['register' => 'pipelinq', 'schema' => 'request', 'slug' => 'req-1'], 'naam' => 'A'],
                ],
            ],
        ];
        $override = [
            'components' => [
                'objects' => [
                    ['@self' => ['register' => 'pipelinq', 'schema' => 'zgwEndpoint', 'slug' => 'zgw-ep-1'], 'naam' => 'B'],
                    ['@self' => ['register' => 'pipelinq', 'schema' => 'request', 'slug' => 'req-1'], 'naam' => 'A2'],
                ],
            ],
        ];

        $result  = $this->deepMerge($base, $override);
        $objects = $result['components']['objects'];

        $this->assertCount(2, $objects);
        $slugs = array_map(static fn(array $o): string => $o['@self']['slug'], $objects);
        $this->assertSame(['req-1', 'zgw-ep-1'], $slugs);
        // Matching slug replaced in place (override wins).
        $this->assertSame('A2', $objects[0]['naam']);
    }//end testSeedObjectsAreUnionedBySlug()

    /**
     * Non-union lists (e.g. a schema's required[] array) still replace.
     *
     * @return void
     */
    public function testNonUnionListsStillReplace(): void
    {
        $base     = ['components' => ['schemas' => ['request' => ['required' => ['a', 'b']]]]];
        $override = ['components' => ['schemas' => ['request' => ['required' => ['c']]]]];

        $result = $this->deepMerge($base, $override);

        $this->assertSame(['c'], $result['components']['schemas']['request']['required']);
    }//end testNonUnionListsStillReplace()
}//end class
