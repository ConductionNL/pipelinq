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
     * Test that a register schemas[] membership list is concatenated and deduplicated.
     *
     * @return void
     */
    public function testMergeFragmentUnionsSchemaMembership(): void
    {
        $base     = ['components' => ['registers' => ['pipelinq' => ['schemas' => ['client', 'product']]]]];
        $override = ['components' => ['registers' => ['pipelinq' => ['schemas' => ['product', 'loyaltyProgramme']]]]];

        $result = $this->service->mergeFragment($base, $override);

        $this->assertSame(
            ['client', 'product', 'loyaltyProgramme'],
            $result['components']['registers']['pipelinq']['schemas']
        );
    }//end testMergeFragmentUnionsSchemaMembership()

    /**
     * Test that components.objects[] seed lists are concatenated by slug.
     *
     * @return void
     */
    public function testMergeFragmentAppendsSeedObjects(): void
    {
        $base     = ['components' => ['objects' => [['@self' => ['slug' => 'a'], 'v' => 1]]]];
        $override = ['components' => ['objects' => [['@self' => ['slug' => 'b'], 'v' => 2]]]];

        $result  = $this->service->mergeFragment($base, $override);
        $objects = $result['components']['objects'];

        $this->assertCount(2, $objects);
        $this->assertSame('a', $objects[0]['@self']['slug']);
        $this->assertSame('b', $objects[1]['@self']['slug']);
    }//end testMergeFragmentAppendsSeedObjects()

    /**
     * Test that re-merging the same seed object by slug is idempotent (replace in place).
     *
     * @return void
     */
    public function testMergeFragmentSeedObjectsIdempotentBySlug(): void
    {
        $base     = ['components' => ['objects' => [['@self' => ['slug' => 'a'], 'v' => 1]]]];
        $override = ['components' => ['objects' => [['@self' => ['slug' => 'a'], 'v' => 2]]]];

        $result  = $this->service->mergeFragment($base, $override);
        $objects = $result['components']['objects'];

        $this->assertCount(1, $objects);
        $this->assertSame(2, $objects[0]['v']);
    }//end testMergeFragmentSeedObjectsIdempotentBySlug()

    /**
     * Test that non-membership, non-seed lists keep replace semantics.
     *
     * @return void
     */
    public function testMergeFragmentReplacesOtherLists(): void
    {
        $base     = ['components' => ['schemas' => ['x' => ['required' => ['a', 'b']]]]];
        $override = ['components' => ['schemas' => ['x' => ['required' => ['c']]]]];

        $result = $this->service->mergeFragment($base, $override);

        $this->assertSame(['c'], $result['components']['schemas']['x']['required']);
    }//end testMergeFragmentReplacesOtherLists()
}//end class
