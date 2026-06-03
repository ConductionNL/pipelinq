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
     * Invoke the private deepMergeConfig method via reflection.
     *
     * @param array $base     The base configuration.
     * @param array $override The fragment override.
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
     * A fragment's components.objects[] seeds MUST be appended to the base
     * seeds, never replace them (ADR-037 additive union).
     *
     * @return void
     */
    public function testFragmentObjectsAreUnionedNotReplaced(): void
    {
        $base = [
            'components' => [
                'objects' => [
                    ['@self' => ['slug' => 'base-seed-1']],
                    ['@self' => ['slug' => 'base-seed-2']],
                ],
            ],
        ];

        $fragment = [
            'components' => [
                'objects' => [
                    ['@self' => ['slug' => 'frag-seed-1']],
                ],
            ],
        ];

        $merged = $this->deepMerge($base, $fragment);
        $slugs  = array_column(
            array_column($merged['components']['objects'], '@self'),
            'slug'
        );

        $this->assertSame(['base-seed-1', 'base-seed-2', 'frag-seed-1'], $slugs);
    }//end testFragmentObjectsAreUnionedNotReplaced()

    /**
     * A fragment's register schemas[] membership MUST union with the base
     * membership and de-duplicate string slugs (ADR-037).
     *
     * @return void
     */
    public function testFragmentSchemaMembershipIsUnionedAndDeduplicated(): void
    {
        $base = [
            'components' => [
                'registers' => [
                    'pipelinq' => ['schemas' => ['client', 'contact']],
                ],
            ],
        ];

        $fragment = [
            'components' => [
                'registers' => [
                    'pipelinq' => ['schemas' => ['contact', 'syncMapping']],
                ],
            ],
        ];

        $merged = $this->deepMerge($base, $fragment);

        $this->assertSame(
            ['client', 'contact', 'syncMapping'],
            $merged['components']['registers']['pipelinq']['schemas']
        );
    }//end testFragmentSchemaMembershipIsUnionedAndDeduplicated()

    /**
     * Non-union list keys (e.g. a schema's `required` array) keep
     * last-fragment-wins replace semantics.
     *
     * @return void
     */
    public function testNonUnionListsAreStillReplaced(): void
    {
        $base     = ['components' => ['schemas' => ['client' => ['required' => ['name']]]]];
        $fragment = ['components' => ['schemas' => ['client' => ['required' => ['email']]]]];

        $merged = $this->deepMerge($base, $fragment);

        $this->assertSame(['email'], $merged['components']['schemas']['client']['required']);
    }//end testNonUnionListsAreStillReplaced()
}//end class
