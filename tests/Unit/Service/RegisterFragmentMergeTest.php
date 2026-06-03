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
     * The register 'schemas[]' membership list is union-merged, not replaced.
     *
     * A fragment that contributes its own schema slugs must extend the
     * monolith's register membership, never clobber it (ADR-037).
     *
     * @return void
     */
    public function testRegisterSchemasListIsUnioned(): void
    {
        $base = [
            'components' => [
                'registers' => ['pipelinq' => ['schemas' => ['client', 'contact']]],
            ],
        ];
        $override = [
            'components' => [
                'registers' => ['pipelinq' => ['schemas' => ['berichtenboxMessage', 'contact']]],
            ],
        ];

        $result = $this->deepMerge($base, $override);

        $this->assertSame(
            ['client', 'contact', 'berichtenboxMessage'],
            $result['components']['registers']['pipelinq']['schemas']
        );
    }//end testRegisterSchemasListIsUnioned()

    /**
     * The seed 'components.objects[]' list is union-merged (appended).
     *
     * @return void
     */
    public function testSeedObjectsListIsUnioned(): void
    {
        $base     = ['components' => ['objects' => [['slug' => 'a']]]];
        $override = ['components' => ['objects' => [['slug' => 'b']]]];

        $result = $this->deepMerge($base, $override);

        $this->assertSame(
            [['slug' => 'a'], ['slug' => 'b']],
            $result['components']['objects']
        );
    }//end testSeedObjectsListIsUnioned()

    /**
     * Non-union list keys (e.g. 'required') are still replaced wholesale.
     *
     * @return void
     */
    public function testNonUnionListStillReplaced(): void
    {
        $base     = ['required' => ['a', 'b']];
        $override = ['required' => ['c']];

        $result = $this->deepMerge($base, $override);

        $this->assertSame(['c'], $result['required']);
    }//end testNonUnionListStillReplaced()

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
}//end class
