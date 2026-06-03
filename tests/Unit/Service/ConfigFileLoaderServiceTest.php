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
     * Build a temporary app directory with a monolith register file and the
     * given fragments, then load it through a service pointed at that path.
     *
     * @param array              $monolith  The monolith register configuration.
     * @param array<string,array> $fragments Map of fragment filename => content.
     *
     * @return array The merged configuration.
     */
    private function loadWithFragments(array $monolith, array $fragments): array
    {
        $appPath = sys_get_temp_dir().'/pq-cfg-'.uniqid('', true);
        mkdir($appPath.'/lib/Settings/register.d', 0777, true);
        file_put_contents(
            $appPath.'/lib/Settings/pipelinq_register.json',
            (string) json_encode($monolith)
        );
        foreach ($fragments as $name => $content) {
            file_put_contents(
                $appPath.'/lib/Settings/register.d/'.$name,
                (string) json_encode($content)
            );
        }

        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('getAppPath')->willReturn($appPath);
        $service = new ConfigFileLoaderService($appManager);

        return $service->loadConfigurationFile();
    }//end loadWithFragments()

    /**
     * A fragment that contributes seed objects must ADD them to the monolith's
     * components.objects[] rather than replacing the existing seeds (ADR-037).
     *
     * @return void
     */
    public function testFragmentSeedObjectsAreAdditivelyUnioned(): void
    {
        $monolith  = [
            'info'       => ['version' => '1.0.0'],
            'components' => [
                'registers' => ['pipelinq' => ['slug' => 'pipelinq', 'schemas' => ['contact']]],
                'schemas'   => ['contact' => ['slug' => 'contact']],
                'objects'   => [['@self' => ['slug' => 'base-seed'], 'name' => 'Base']],
            ],
        ];
        $fragments = [
            '90-mdm.json' => [
                'components' => [
                    'objects' => [['@self' => ['slug' => 'mdm-seed'], 'name' => 'Mdm']],
                ],
            ],
        ];

        $result = $this->loadWithFragments($monolith, $fragments);
        $slugs  = array_map(
            static fn (array $o): string => (string) ($o['@self']['slug'] ?? ''),
            $result['components']['objects']
        );

        $this->assertContains('base-seed', $slugs, 'Monolith seed must survive fragment merge');
        $this->assertContains('mdm-seed', $slugs, 'Fragment seed must be added');
        $this->assertCount(2, $result['components']['objects']);
    }//end testFragmentSeedObjectsAreAdditivelyUnioned()

    /**
     * A fragment that registers a new schema must ADD its slug to the register's
     * schemas[] membership, not overwrite the existing membership (ADR-037).
     *
     * @return void
     */
    public function testFragmentSchemaMembershipIsAdditivelyUnioned(): void
    {
        $monolith  = [
            'info'       => ['version' => '1.0.0'],
            'components' => [
                'registers' => ['pipelinq' => ['slug' => 'pipelinq', 'schemas' => ['contact', 'client']]],
                'schemas'   => ['contact' => ['slug' => 'contact']],
                'objects'   => [],
            ],
        ];
        $fragments = [
            '90-mdm.json' => [
                'components' => [
                    'registers' => ['pipelinq' => ['schemas' => ['masterEntity', 'sourceRecord']]],
                    'schemas'   => ['masterEntity' => ['slug' => 'masterEntity']],
                ],
            ],
        ];

        $result     = $this->loadWithFragments($monolith, $fragments);
        $membership = $result['components']['registers']['pipelinq']['schemas'];

        $this->assertContains('contact', $membership, 'Existing membership must survive');
        $this->assertContains('client', $membership, 'Existing membership must survive');
        $this->assertContains('masterEntity', $membership, 'New schema must be added to register');
        $this->assertContains('sourceRecord', $membership, 'New schema must be added to register');
    }//end testFragmentSchemaMembershipIsAdditivelyUnioned()

    /**
     * Re-applying an unchanged fragment must be idempotent: the seed-object and
     * schema-membership unions de-duplicate, so no member appears twice.
     *
     * @return void
     */
    public function testFragmentUnionIsIdempotentOnDuplicateMembers(): void
    {
        $monolith  = [
            'info'       => ['version' => '1.0.0'],
            'components' => [
                'registers' => ['pipelinq' => ['slug' => 'pipelinq', 'schemas' => ['contact', 'masterEntity']]],
                'schemas'   => ['contact' => ['slug' => 'contact']],
                'objects'   => [['@self' => ['slug' => 'shared-seed'], 'name' => 'Shared']],
            ],
        ];
        $fragments = [
            '90-mdm.json' => [
                'components' => [
                    'registers' => ['pipelinq' => ['schemas' => ['masterEntity']]],
                    'objects'   => [['@self' => ['slug' => 'shared-seed'], 'name' => 'Shared']],
                ],
            ],
        ];

        $result = $this->loadWithFragments($monolith, $fragments);

        $this->assertCount(1, $result['components']['objects'], 'Duplicate seed must not be doubled');
        $membership = $result['components']['registers']['pipelinq']['schemas'];
        $this->assertSame(
            ['contact', 'masterEntity'],
            $membership,
            'Duplicate schema slug must not be doubled'
        );
    }//end testFragmentUnionIsIdempotentOnDuplicateMembers()
}//end class
