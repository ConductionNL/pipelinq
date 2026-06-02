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
     * Invoke the private deepMergeConfig via reflection.
     *
     * @param array $base     The base configuration array.
     * @param array $override The fragment to merge on top.
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
     * Seed/demo objects from a fragment must be appended, not replace the base.
     *
     * Guards ADR-037: a fragment that ships its own components.objects[] seeds
     * must not clobber the monolith's existing seed objects.
     *
     * @return void
     */
    public function testFragmentObjectsAreAppendedNotReplaced(): void
    {
        $base = [
            'components' => [
                'objects' => [
                    ['@self' => ['slug' => 'base-one'], 'name' => 'Base One'],
                    ['@self' => ['slug' => 'base-two'], 'name' => 'Base Two'],
                ],
            ],
        ];

        $override = [
            'components' => [
                'objects' => [
                    ['@self' => ['slug' => 'frag-one'], 'name' => 'Fragment One'],
                ],
            ],
        ];

        $result = $this->deepMerge($base, $override);
        $slugs  = array_map(
            static fn (array $o): string => $o['@self']['slug'],
            $result['components']['objects']
        );

        $this->assertCount(3, $result['components']['objects']);
        $this->assertSame(['base-one', 'base-two', 'frag-one'], $slugs);
    }//end testFragmentObjectsAreAppendedNotReplaced()

    /**
     * Re-merging the same fragment object is idempotent (slug de-duplicated).
     *
     * @return void
     */
    public function testFragmentObjectsAreDeduplicatedBySlug(): void
    {
        $base = [
            'components' => [
                'objects' => [
                    ['@self' => ['slug' => 'shared'], 'name' => 'Original'],
                ],
            ],
        ];

        $override = [
            'components' => [
                'objects' => [
                    ['@self' => ['slug' => 'shared'], 'name' => 'Duplicate'],
                    ['@self' => ['slug' => 'new'], 'name' => 'New'],
                ],
            ],
        ];

        $result = $this->deepMerge($base, $override);

        $this->assertCount(2, $result['components']['objects']);
        $this->assertSame('Original', $result['components']['objects'][0]['name']);
        $this->assertSame('new', $result['components']['objects'][1]['@self']['slug']);
    }//end testFragmentObjectsAreDeduplicatedBySlug()

    /**
     * A register schema-membership list from a fragment must be appended.
     *
     * @return void
     */
    public function testRegisterSchemaListIsAppendedNotReplaced(): void
    {
        $base = [
            'components' => [
                'registers' => [
                    'pipelinq' => [
                        'slug'    => 'pipelinq',
                        'schemas' => ['client', 'contact'],
                    ],
                ],
            ],
        ];

        $override = [
            'components' => [
                'registers' => [
                    'pipelinq' => [
                        'schemas' => ['billingCategory'],
                    ],
                ],
            ],
        ];

        $result = $this->deepMerge($base, $override);

        $this->assertSame(
            ['client', 'contact', 'billingCategory'],
            $result['components']['registers']['pipelinq']['schemas']
        );
        $this->assertSame('pipelinq', $result['components']['registers']['pipelinq']['slug']);
    }//end testRegisterSchemaListIsAppendedNotReplaced()

    /**
     * A duplicate schema slug in the membership list is not added twice.
     *
     * @return void
     */
    public function testRegisterSchemaListDeduplicates(): void
    {
        $base     = ['schemas' => ['client', 'contact']];
        $override = ['schemas' => ['contact', 'lead']];

        $result = $this->deepMerge($base, $override);

        $this->assertSame(['client', 'contact', 'lead'], $result['schemas']);
    }//end testRegisterSchemaListDeduplicates()

    /**
     * Non-append lists (e.g. 'required') keep replace semantics.
     *
     * @return void
     */
    public function testNonAppendListsAreReplaced(): void
    {
        $base     = ['required' => ['name'], 'title' => 'Base'];
        $override = ['required' => ['code', 'type']];

        $result = $this->deepMerge($base, $override);

        $this->assertSame(['code', 'type'], $result['required']);
        $this->assertSame('Base', $result['title']);
    }//end testNonAppendListsAreReplaced()
}//end class
