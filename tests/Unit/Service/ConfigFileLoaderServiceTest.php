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
     * Build a throw-away app skeleton (base register + optional fragments) and
     * return the loaded, fragment-merged configuration.
     *
     * @param array<string, mixed>        $baseConfig The base register JSON.
     * @param array<string, array<mixed>> $fragments  filename => fragment JSON.
     *
     * @return array<string, mixed> The merged configuration.
     */
    private function loadWithFragments(array $baseConfig, array $fragments): array
    {
        $appRoot = sys_get_temp_dir().'/pq-cfg-'.bin2hex(random_bytes(6));
        mkdir($appRoot.'/lib/Settings/register.d', 0777, true);
        file_put_contents(
            $appRoot.'/lib/Settings/pipelinq_register.json',
            json_encode($baseConfig, JSON_PRETTY_PRINT)
        );
        foreach ($fragments as $name => $fragment) {
            file_put_contents(
                $appRoot.'/lib/Settings/register.d/'.$name,
                json_encode($fragment, JSON_PRETTY_PRINT)
            );
        }

        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('getAppPath')->willReturn($appRoot);
        $service = new ConfigFileLoaderService($appManager);

        $result = $service->loadConfigurationFile();

        // Clean up the skeleton.
        foreach ($fragments as $name => $unused) {
            @unlink($appRoot.'/lib/Settings/register.d/'.$name);
        }

        @unlink($appRoot.'/lib/Settings/pipelinq_register.json');
        @rmdir($appRoot.'/lib/Settings/register.d');
        @rmdir($appRoot.'/lib/Settings');
        @rmdir($appRoot.'/lib');
        @rmdir($appRoot);

        return $result;
    }//end loadWithFragments()

    /**
     * A fragment's components.objects[] seeds are UNIONED onto the base seeds,
     * not replaced (ADR-037 additive set-list rule).
     *
     * @return void
     */
    public function testFragmentObjectsAreUnionedNotReplaced(): void
    {
        $base = [
            'info'       => ['version' => '1.0.0'],
            'components' => [
                'registers' => ['pipelinq' => ['slug' => 'pipelinq', 'schemas' => ['posTransaction']]],
                'objects'   => [
                    ['@self' => ['slug' => 'base-one', 'schema' => 'posTransaction']],
                    ['@self' => ['slug' => 'base-two', 'schema' => 'posTransaction']],
                ],
            ],
        ];

        $fragment = [
            'components' => [
                'objects' => [
                    ['@self' => ['slug' => 'frag-one', 'schema' => 'posTender']],
                ],
            ],
        ];

        $result = $this->loadWithFragments($base, ['40-frag.json' => $fragment]);
        $slugs  = array_map(static fn (array $o): string => $o['@self']['slug'], $result['components']['objects']);

        // All three slugs survive — the base seeds were NOT dropped.
        $this->assertContains('base-one', $slugs);
        $this->assertContains('base-two', $slugs);
        $this->assertContains('frag-one', $slugs);
        $this->assertCount(3, $result['components']['objects']);
    }//end testFragmentObjectsAreUnionedNotReplaced()

    /**
     * A fragment object with a slug that already exists in the base REPLACES that
     * one base seed in place (last-writer-wins) without dropping the others.
     *
     * @return void
     */
    public function testFragmentObjectWithExistingSlugReplacesInPlace(): void
    {
        $base = [
            'info'       => ['version' => '1.0.0'],
            'components' => [
                'objects' => [
                    ['@self' => ['slug' => 'shared', 'schema' => 'posTransaction'], 'total' => 1],
                    ['@self' => ['slug' => 'other', 'schema' => 'posTransaction']],
                ],
            ],
        ];

        $fragment = [
            'components' => [
                'objects' => [
                    ['@self' => ['slug' => 'shared', 'schema' => 'posTransaction'], 'total' => 99],
                ],
            ],
        ];

        $result  = $this->loadWithFragments($base, ['40-frag.json' => $fragment]);
        $objects = $result['components']['objects'];
        $this->assertCount(2, $objects);

        $bySlug = [];
        foreach ($objects as $object) {
            $bySlug[$object['@self']['slug']] = $object;
        }

        $this->assertSame(99, $bySlug['shared']['total']);
        $this->assertArrayHasKey('other', $bySlug);
    }//end testFragmentObjectWithExistingSlugReplacesInPlace()

    /**
     * A fragment that adds schema slugs to a register's schemas[] membership list
     * UNIONS them onto the base membership rather than overwriting it.
     *
     * @return void
     */
    public function testFragmentRegisterSchemasAreUnioned(): void
    {
        $base = [
            'info'       => ['version' => '1.0.0'],
            'components' => [
                'registers' => [
                    'pipelinq' => ['slug' => 'pipelinq', 'schemas' => ['posTransaction', 'posTransactionLine']],
                ],
            ],
        ];

        $fragment = [
            'components' => [
                'registers' => [
                    'pipelinq' => ['schemas' => ['posTenderType', 'posTender']],
                ],
            ],
        ];

        $result  = $this->loadWithFragments($base, ['40-frag.json' => $fragment]);
        $schemas = $result['components']['registers']['pipelinq']['schemas'];

        $this->assertContains('posTransaction', $schemas);
        $this->assertContains('posTransactionLine', $schemas);
        $this->assertContains('posTenderType', $schemas);
        $this->assertContains('posTender', $schemas);
        // No duplicates and base membership preserved.
        $this->assertSame($schemas, array_values(array_unique($schemas)));
    }//end testFragmentRegisterSchemasAreUnioned()

    /**
     * Register schema-membership union does not duplicate a slug the fragment
     * re-declares, and the version is stamped with the fragment hash.
     *
     * @return void
     */
    public function testFragmentSchemaUnionDedupesAndStampsVersion(): void
    {
        $base = [
            'info'       => ['version' => '2.0.0'],
            'components' => [
                'registers' => ['pipelinq' => ['slug' => 'pipelinq', 'schemas' => ['posTransaction']]],
            ],
        ];

        $fragment = [
            'components' => [
                'registers' => ['pipelinq' => ['schemas' => ['posTransaction', 'posTender']]],
            ],
        ];

        $result  = $this->loadWithFragments($base, ['40-frag.json' => $fragment]);
        $schemas = $result['components']['registers']['pipelinq']['schemas'];

        $this->assertSame(['posTransaction', 'posTender'], $schemas);
        $this->assertStringStartsWith('2.0.0+frag.', (string) $result['info']['version']);
    }//end testFragmentSchemaUnionDedupesAndStampsVersion()

    /**
     * Against the REAL monolith + the real 40-pos-split-tender fragment, the
     * union adds the two split-tender schemas to the register membership and
     * preserves every base schema and seed object (regression guard for ADR-037:
     * a fragment must never drop the base register's schemas or seeds).
     *
     * @return void
     */
    public function testRealSplitTenderFragmentExtendsMonolith(): void
    {
        $appRoot = dirname(__DIR__, 3);
        if (is_file($appRoot.'/lib/Settings/register.d/40-pos-split-tender.json') === false) {
            $this->markTestSkipped('split-tender fragment not present');
        }

        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('getAppPath')->willReturn($appRoot);
        $service = new ConfigFileLoaderService($appManager);

        $result   = $service->loadConfigurationFile();
        $schemas  = $result['components']['registers']['pipelinq']['schemas'];
        $defined  = array_keys($result['components']['schemas']);
        $objSlugs = array_map(
            static fn (array $o): string => (string) ($o['@self']['slug'] ?? ''),
            $result['components']['objects']
        );

        // Base POS schemas still present in the register membership.
        $this->assertContains('posTransaction', $schemas);
        $this->assertContains('posRefund', $schemas);
        // New split-tender schemas added (membership + definitions).
        $this->assertContains('posTenderType', $schemas);
        $this->assertContains('posTender', $schemas);
        $this->assertContains('posTenderType', $defined);
        $this->assertContains('posTender', $defined);
        // No duplicate schema membership entries.
        $this->assertSame($schemas, array_values(array_unique($schemas)));
        // Base seeds preserved AND new tender seeds unioned in.
        $this->assertContains('txn-2026-0001', $objSlugs);
        $this->assertContains('tender-cash', $objSlugs);
        $this->assertContains('tender-txn-0003-card', $objSlugs);

        // The fragment additively extends posTransaction with changeDue WITHOUT
        // dropping its base properties (deep associative merge of properties).
        $txnProps = $result['components']['schemas']['posTransaction']['properties'];
        $this->assertArrayHasKey('changeDue', $txnProps);
        $this->assertArrayHasKey('total', $txnProps);
        $this->assertArrayHasKey('status', $txnProps);
    }//end testRealSplitTenderFragmentExtendsMonolith()
}//end class
