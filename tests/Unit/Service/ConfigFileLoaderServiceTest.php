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
     * A fragment's components.objects[] is unioned onto the base, not replacing.
     *
     * @return void
     */
    public function testMergeUnionsSeedObjects(): void
    {
        $base = [
            'components' => [
                'objects' => [
                    ['@self' => ['register' => 'r', 'schema' => 's', 'slug' => 'base-1'], 'v' => 1],
                ],
            ],
        ];

        $fragment = [
            'components' => [
                'objects' => [
                    ['@self' => ['register' => 'r', 'schema' => 's', 'slug' => 'frag-1'], 'v' => 2],
                ],
            ],
        ];

        $merged = ConfigFileLoaderService::mergeConfig($base, $fragment);
        $slugs  = array_map(
            static fn (array $o): string => $o['@self']['slug'],
            $merged['components']['objects']
        );

        $this->assertContains('base-1', $slugs);
        $this->assertContains('frag-1', $slugs);
        $this->assertCount(2, $merged['components']['objects']);
    }//end testMergeUnionsSeedObjects()

    /**
     * A fragment re-seeding an existing slug replaces just that object.
     *
     * @return void
     */
    public function testMergeReseedReplacesBySlug(): void
    {
        $base = [
            'components' => [
                'objects' => [
                    ['@self' => ['register' => 'r', 'schema' => 's', 'slug' => 'shared'], 'v' => 1],
                ],
            ],
        ];

        $fragment = [
            'components' => [
                'objects' => [
                    ['@self' => ['register' => 'r', 'schema' => 's', 'slug' => 'shared'], 'v' => 99],
                ],
            ],
        ];

        $merged = ConfigFileLoaderService::mergeConfig($base, $fragment);

        $this->assertCount(1, $merged['components']['objects']);
        $this->assertSame(99, $merged['components']['objects'][0]['v']);
    }//end testMergeReseedReplacesBySlug()

    /**
     * A fragment's register schemas[] membership is unioned, de-duplicated.
     *
     * @return void
     */
    public function testMergeUnionsRegisterSchemas(): void
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
                    'pipelinq' => ['schemas' => ['contact', 'posRole', 'posStaff']],
                ],
            ],
        ];

        $merged  = ConfigFileLoaderService::mergeConfig($base, $fragment);
        $schemas = $merged['components']['registers']['pipelinq']['schemas'];

        $this->assertSame(['client', 'contact', 'posRole', 'posStaff'], $schemas);
    }//end testMergeUnionsRegisterSchemas()

    /**
     * A fragment adds a property to an existing schema without dropping the rest.
     *
     * @return void
     */
    public function testMergeAddsSchemaPropertyAdditively(): void
    {
        $base = [
            'components' => [
                'schemas' => [
                    'posTransaction' => [
                        'properties' => [
                            'total' => ['type' => 'number'],
                            'cashier' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];

        $fragment = [
            'components' => [
                'schemas' => [
                    'posTransaction' => [
                        'properties' => [
                            'staffMemberId' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];

        $merged = ConfigFileLoaderService::mergeConfig($base, $fragment);
        $props  = $merged['components']['schemas']['posTransaction']['properties'];

        $this->assertArrayHasKey('total', $props);
        $this->assertArrayHasKey('cashier', $props);
        $this->assertArrayHasKey('staffMemberId', $props);
    }//end testMergeAddsSchemaPropertyAdditively()
}//end class
