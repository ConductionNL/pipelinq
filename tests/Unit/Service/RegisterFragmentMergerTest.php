<?php

/**
 * Unit tests for RegisterFragmentMerger.
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

use OCA\Pipelinq\Service\RegisterFragmentMerger;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the ADR-037 additive register fragment merge.
 */
class RegisterFragmentMergerTest extends TestCase
{
    /**
     * Test that a fragment's seed objects are unioned onto the base seed objects
     * rather than replacing them (ADR-037 additive merge).
     *
     * @return void
     */
    public function testMergeRegisterConfigUnionsSeedObjects(): void
    {
        $base = [
            'components' => [
                'objects' => [
                    ['@self' => ['register' => 'pipelinq', 'schema' => 'product', 'slug' => 'product-a'], 'name' => 'A'],
                ],
            ],
        ];

        $override = [
            'components' => [
                'objects' => [
                    ['@self' => ['register' => 'pipelinq', 'schema' => 'leadProduct', 'slug' => 'lp-1'], 'quantity' => 1],
                ],
            ],
        ];

        $result = RegisterFragmentMerger::mergeRegisterConfig(base: $base, override: $override);
        $slugs  = array_map(static fn ($o) => $o['@self']['slug'], $result['components']['objects']);

        $this->assertContains('product-a', $slugs, 'base seed object must survive');
        $this->assertContains('lp-1', $slugs, 'fragment seed object must be added');
        $this->assertCount(2, $result['components']['objects']);
    }//end testMergeRegisterConfigUnionsSeedObjects()

    /**
     * Test that re-merging an identical seed object (same identity) is
     * idempotent and the override wins on conflict.
     *
     * @return void
     */
    public function testMergeRegisterConfigSeedObjectsAreIdempotentBySlug(): void
    {
        $base = [
            'components' => [
                'objects' => [
                    ['@self' => ['register' => 'pipelinq', 'schema' => 'product', 'slug' => 'product-a'], 'name' => 'Old'],
                ],
            ],
        ];

        $override = [
            'components' => [
                'objects' => [
                    ['@self' => ['register' => 'pipelinq', 'schema' => 'product', 'slug' => 'product-a'], 'name' => 'New'],
                ],
            ],
        ];

        $result = RegisterFragmentMerger::mergeRegisterConfig(base: $base, override: $override);

        $this->assertCount(1, $result['components']['objects'], 'same-slug object must not duplicate');
        $this->assertSame('New', $result['components']['objects'][0]['name'], 'override must win on conflict');
    }//end testMergeRegisterConfigSeedObjectsAreIdempotentBySlug()

    /**
     * Test that a fragment extends an existing register's schema-membership list
     * additively instead of replacing it (ADR-037).
     *
     * @return void
     */
    public function testMergeRegisterConfigUnionsRegisterSchemaMembership(): void
    {
        $base = [
            'components' => [
                'registers' => [
                    'pipelinq' => ['slug' => 'pipelinq', 'schemas' => ['product', 'lead']],
                ],
            ],
        ];

        $override = [
            'components' => [
                'registers' => [
                    'pipelinq' => ['schemas' => ['lead', 'leadProduct', 'productCategory']],
                ],
            ],
        ];

        $result  = RegisterFragmentMerger::mergeRegisterConfig(base: $base, override: $override);
        $schemas = $result['components']['registers']['pipelinq']['schemas'];

        $this->assertSame(['product', 'lead', 'leadProduct', 'productCategory'], $schemas);
    }//end testMergeRegisterConfigUnionsRegisterSchemaMembership()

    /**
     * Test that non-list keys still deep-merge (override replaces scalar).
     *
     * @return void
     */
    public function testMergeRegisterConfigDeepMergesNonListKeys(): void
    {
        $base     = ['info' => ['version' => '1.0.0', 'title' => 'Base']];
        $override = ['info' => ['version' => '1.1.0']];

        $result = RegisterFragmentMerger::mergeRegisterConfig(base: $base, override: $override);

        $this->assertSame('1.1.0', $result['info']['version']);
        $this->assertSame('Base', $result['info']['title'], 'untouched keys must be preserved');
    }//end testMergeRegisterConfigDeepMergesNonListKeys()

    /**
     * Test that merge() applies multiple fragments and stamps the version hash.
     *
     * @return void
     */
    public function testMergeAppliesFragmentsAndStampsVersion(): void
    {
        $merger = new RegisterFragmentMerger();

        $base = [
            'info'       => ['version' => '1.1.0'],
            'components' => [
                'objects'   => [
                    ['@self' => ['register' => 'pipelinq', 'schema' => 'product', 'slug' => 'base-product']],
                ],
                'registers' => [
                    'pipelinq' => ['slug' => 'pipelinq', 'schemas' => ['product']],
                ],
            ],
        ];

        $fragment = [
            'components' => [
                'objects'   => [
                    ['@self' => ['register' => 'pipelinq', 'schema' => 'leadProduct', 'slug' => 'lp-1']],
                ],
                'registers' => [
                    'pipelinq' => ['schemas' => ['leadProduct']],
                ],
            ],
        ];

        $blob   = json_encode($fragment);
        $result = $merger->merge(base: $base, fragments: [$fragment], fragmentBlob: $blob);

        $this->assertCount(2, $result['components']['objects'], 'both base and fragment objects must be present');
        $this->assertSame(
            ['product', 'leadProduct'],
            $result['components']['registers']['pipelinq']['schemas']
        );
        $this->assertStringStartsWith('1.1.0+frag.', $result['info']['version'], 'version must be fragment-stamped');
    }//end testMergeAppliesFragmentsAndStampsVersion()

    /**
     * Test that merge() leaves the version untouched when there are no fragments.
     *
     * @return void
     */
    public function testMergeWithoutFragmentsLeavesVersionUntouched(): void
    {
        $merger = new RegisterFragmentMerger();
        $base   = ['info' => ['version' => '1.1.0']];

        $result = $merger->merge(base: $base, fragments: [], fragmentBlob: '');

        $this->assertSame('1.1.0', $result['info']['version']);
    }//end testMergeWithoutFragmentsLeavesVersionUntouched()

    /**
     * Test that decode() throws a RuntimeException on malformed JSON.
     *
     * @return void
     */
    public function testDecodeThrowsOnInvalidJson(): void
    {
        $merger = new RegisterFragmentMerger();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid JSON in register fragment');

        $merger->decode(fragmentFile: '/tmp/broken.json', fragmentContent: '{not valid');
    }//end testDecodeThrowsOnInvalidJson()
}//end class
