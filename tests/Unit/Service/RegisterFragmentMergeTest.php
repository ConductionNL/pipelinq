<?php

/**
 * Unit tests for the ADR-037 modular register-fragment deep-merge.
 *
 * Exercises the private static ConfigFileLoaderService::deepMergeConfig()
 * (and its isList() helper) via reflection — the merge semantics that let
 * concurrent same-app builds extend the register without touching the shared
 * pipelinq_register.json monolith.
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
 * @link https://codeberg.org/Conduction/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\ConfigFileLoaderService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Tests for ConfigFileLoaderService::deepMergeConfig().
 */
class RegisterFragmentMergeTest extends TestCase
{
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
        $method = new ReflectionMethod(ConfigFileLoaderService::class, 'deepMergeConfig');
        $method->setAccessible(true);

        return $method->invoke(null, $base, $override);
    }//end deepMerge()

    /**
     * A fragment key absent from the base is added.
     *
     * @return void
     */
    public function testAddsNewTopLevelKey(): void
    {
        $result = $this->deepMerge(['a' => 1], ['b' => 2]);

        $this->assertSame(['a' => 1, 'b' => 2], $result);
    }//end testAddsNewTopLevelKey()

    /**
     * Nested associative objects merge recursively, preserving base siblings.
     *
     * @return void
     */
    public function testMergesNestedObjectsRecursively(): void
    {
        $base     = ['components' => ['schemas' => ['client' => ['title' => 'Client']]]];
        $override = ['components' => ['schemas' => ['lead' => ['title' => 'Lead']]]];

        $result = $this->deepMerge($base, $override);

        $this->assertSame(
            [
                'components' => [
                    'schemas' => [
                        'client' => ['title' => 'Client'],
                        'lead'   => ['title' => 'Lead'],
                    ],
                ],
            ],
            $result
        );
    }//end testMergesNestedObjectsRecursively()

    /**
     * A scalar value from the fragment replaces the base scalar.
     *
     * @return void
     */
    public function testScalarOverrideReplacesBase(): void
    {
        $result = $this->deepMerge(['info' => ['version' => '1.0.0']], ['info' => ['version' => '2.0.0']]);

        $this->assertSame('2.0.0', $result['info']['version']);
    }//end testScalarOverrideReplacesBase()

    /**
     * List (sequential) values are replaced wholesale, not deep-merged.
     *
     * @return void
     */
    public function testListValuesAreReplaced(): void
    {
        $result = $this->deepMerge(['tags' => ['a', 'b']], ['tags' => ['c']]);

        $this->assertSame(['c'], $result['tags']);
    }//end testListValuesAreReplaced()

    /**
     * isList() distinguishes sequential lists from associative maps.
     *
     * @return void
     */
    public function testIsListHelper(): void
    {
        $method = new ReflectionMethod(ConfigFileLoaderService::class, 'isList');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke(null, [0, 1, 2]));
        $this->assertTrue($method->invoke(null, []));
        $this->assertFalse($method->invoke(null, ['a' => 1]));
        $this->assertFalse($method->invoke(null, [1 => 'a', 2 => 'b']));
    }//end testIsListHelper()

    /**
     * The register schema-membership list (key "schemas") is additively unioned:
     * a fragment that adds a schema slug must NOT drop the monolith's slugs.
     *
     * Guards the ADR-037 rule that lets a register.d fragment extend the register
     * without clobbering the existing schema set (the stuf-zkn-bg-adapter case).
     *
     * @return void
     */
    public function testRegisterSchemaMembershipIsUnioned(): void
    {
        $base     = [
            'components' => [
                'registers' => [
                    'pipelinq' => ['schemas' => ['contact', 'request', 'lead']],
                ],
            ],
        ];
        $override = [
            'components' => [
                'registers' => [
                    'pipelinq' => ['schemas' => ['stufEndpoint', 'stufMessage']],
                ],
            ],
        ];

        $result = $this->deepMerge($base, $override);

        $this->assertSame(
            ['contact', 'request', 'lead', 'stufEndpoint', 'stufMessage'],
            $result['components']['registers']['pipelinq']['schemas']
        );
    }//end testRegisterSchemaMembershipIsUnioned()

    /**
     * Schema-membership union de-duplicates by slug value (no clones).
     *
     * @return void
     */
    public function testSchemaMembershipUnionDeduplicates(): void
    {
        $base     = ['schemas' => ['contact', 'request']];
        $override = ['schemas' => ['request', 'stufMessage']];

        $result = $this->deepMerge($base, $override);

        $this->assertSame(['contact', 'request', 'stufMessage'], $result['schemas']);
    }//end testSchemaMembershipUnionDeduplicates()

    /**
     * The components.objects[] seed list is additively unioned: a fragment that
     * ships seed objects appends them rather than replacing the monolith seeds.
     *
     * @return void
     */
    public function testSeedObjectsAreUnioned(): void
    {
        $base     = [
            'components' => [
                'objects' => [
                    ['@self' => ['slug' => 'txn-2026-0001'], 'reference' => 'TXN-1'],
                ],
            ],
        ];
        $override = [
            'components' => [
                'objects' => [
                    ['@self' => ['slug' => 'amersfoort-key2zaken'], 'naam' => 'Amersfoort'],
                ],
            ],
        ];

        $result = $this->deepMerge($base, $override);

        $this->assertCount(2, $result['components']['objects']);
        $this->assertSame('txn-2026-0001', $result['components']['objects'][0]['@self']['slug']);
        $this->assertSame('amersfoort-key2zaken', $result['components']['objects'][1]['@self']['slug']);
    }//end testSeedObjectsAreUnioned()

    /**
     * A seed object sharing a base entry's @self.slug refines (replaces) it in
     * place rather than producing a duplicate entry.
     *
     * @return void
     */
    public function testSeedObjectUnionReplacesBySlugIdentity(): void
    {
        $base     = [
            'objects' => [
                ['@self' => ['slug' => 'ep-a'], 'naam' => 'Old'],
            ],
        ];
        $override = [
            'objects' => [
                ['@self' => ['slug' => 'ep-a'], 'naam' => 'New'],
            ],
        ];

        $result = $this->deepMerge($base, $override);

        $this->assertCount(1, $result['objects']);
        $this->assertSame('New', $result['objects'][0]['naam']);
    }//end testSeedObjectUnionReplacesBySlugIdentity()

    /**
     * Non-additive list keys keep replace-semantics — only "schemas"/"objects"
     * are unioned. A "tags" list from the fragment still wins wholesale.
     *
     * @return void
     */
    public function testUnrelatedListsStillReplace(): void
    {
        $result = $this->deepMerge(['tags' => ['a', 'b']], ['tags' => ['c']]);

        $this->assertSame(['c'], $result['tags']);
    }//end testUnrelatedListsStillReplace()
}//end class
