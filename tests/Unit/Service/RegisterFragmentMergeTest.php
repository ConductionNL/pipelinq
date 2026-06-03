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
     * A register's schema-membership list is unioned, not replaced (ADR-037).
     *
     * Without the union rule a fragment adding three schemas would silently drop
     * the monolith's existing schema memberships.
     *
     * @return void
     */
    public function testRegisterSchemaMembershipIsUnioned(): void
    {
        $base = [
            'components' => [
                'registers' => [
                    'pipelinq' => ['schemas' => ['client', 'contact', 'lead']],
                ],
            ],
        ];

        $override = [
            'components' => [
                'registers' => [
                    'pipelinq' => ['schemas' => ['ctiAdapterConfig', 'ctiEventLog']],
                ],
            ],
        ];

        $result  = $this->deepMerge($base, $override);
        $schemas = $result['components']['registers']['pipelinq']['schemas'];

        $this->assertSame(
            ['client', 'contact', 'lead', 'ctiAdapterConfig', 'ctiEventLog'],
            $schemas
        );
    }//end testRegisterSchemaMembershipIsUnioned()

    /**
     * Union of schema membership deduplicates overlapping slugs.
     *
     * @return void
     */
    public function testRegisterSchemaMembershipDeduplicates(): void
    {
        $base     = ['components' => ['registers' => ['pipelinq' => ['schemas' => ['client', 'contact']]]]];
        $override = ['components' => ['registers' => ['pipelinq' => ['schemas' => ['contact', 'lead']]]]];

        $result = $this->deepMerge($base, $override);

        $this->assertSame(
            ['client', 'contact', 'lead'],
            $result['components']['registers']['pipelinq']['schemas']
        );
    }//end testRegisterSchemaMembershipDeduplicates()

    /**
     * Seed objects under components.objects are unioned, keyed by @self.slug.
     *
     * @return void
     */
    public function testSeedObjectsAreUnionedBySlug(): void
    {
        $base = [
            'components' => [
                'objects' => [
                    ['@self' => ['slug' => 'txn-1'], 'total' => 10],
                ],
            ],
        ];

        $override = [
            'components' => [
                'objects' => [
                    ['@self' => ['slug' => 'cti-config-default'], 'platform' => 'callvoip'],
                ],
            ],
        ];

        $result  = $this->deepMerge($base, $override);
        $objects = $result['components']['objects'];

        $this->assertCount(2, $objects);
        $slugs = array_map(static fn(array $o): string => $o['@self']['slug'], $objects);
        $this->assertSame(['txn-1', 'cti-config-default'], $slugs);
    }//end testSeedObjectsAreUnionedBySlug()

    /**
     * A seed object with a colliding @self.slug replaces the base entry in place.
     *
     * @return void
     */
    public function testSeedObjectCollisionReplacesInPlace(): void
    {
        $base     = ['components' => ['objects' => [['@self' => ['slug' => 's1'], 'v' => 'old']]]];
        $override = ['components' => ['objects' => [['@self' => ['slug' => 's1'], 'v' => 'new']]]];

        $result = $this->deepMerge($base, $override);

        $this->assertCount(1, $result['components']['objects']);
        $this->assertSame('new', $result['components']['objects'][0]['v']);
    }//end testSeedObjectCollisionReplacesInPlace()

    /**
     * Lists at non-union paths still replace wholesale (regression guard).
     *
     * @return void
     */
    public function testNonUnionListsStillReplace(): void
    {
        $base     = ['components' => ['schemas' => ['contactmoment' => ['required' => ['a', 'b']]]]];
        $override = ['components' => ['schemas' => ['contactmoment' => ['required' => ['c']]]]];

        $result = $this->deepMerge($base, $override);

        $this->assertSame(['c'], $result['components']['schemas']['contactmoment']['required']);
    }//end testNonUnionListsStillReplace()
}//end class
