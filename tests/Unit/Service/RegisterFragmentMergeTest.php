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
     * Register `schemas[]` membership is unioned, not replaced (ADR-037).
     *
     * A fragment that contributes new schemas must register them on the
     * existing register without clobbering the schemas the monolith already
     * declared.
     *
     * @return void
     */
    public function testRegisterSchemasMembershipIsUnioned(): void
    {
        $base = [
            'components' => [
                'registers' => [
                    'pipelinq' => ['schemas' => ['request', 'complaint']],
                ],
            ],
        ];
        $override = [
            'components' => [
                'registers' => [
                    'pipelinq' => ['schemas' => ['slaPolicy', 'slaBreachEvent']],
                ],
            ],
        ];

        $result = $this->deepMerge($base, $override);

        $this->assertSame(
            ['request', 'complaint', 'slaPolicy', 'slaBreachEvent'],
            $result['components']['registers']['pipelinq']['schemas']
        );
    }//end testRegisterSchemasMembershipIsUnioned()

    /**
     * Duplicate schema slugs in a fragment do not duplicate membership.
     *
     * @return void
     */
    public function testRegisterSchemasMembershipDeDuplicates(): void
    {
        $base     = ['schemas' => ['request', 'complaint']];
        $override = ['schemas' => ['complaint', 'slaPolicy']];

        $result = $this->deepMerge($base, $override);

        $this->assertSame(['request', 'complaint', 'slaPolicy'], $result['schemas']);
    }//end testRegisterSchemasMembershipDeDuplicates()

    /**
     * The `components.objects[]` seed list is appended, not replaced (ADR-037).
     *
     * @return void
     */
    public function testComponentsObjectsSeedListIsUnioned(): void
    {
        $base = [
            'components' => [
                'objects' => [
                    ['@self' => ['schema' => 'posTransaction', 'slug' => 'txn-1']],
                ],
            ],
        ];
        $override = [
            'components' => [
                'objects' => [
                    ['@self' => ['schema' => 'slaPolicy', 'slug' => 'policy-standaard-request']],
                ],
            ],
        ];

        $result = $this->deepMerge($base, $override);

        $this->assertCount(2, $result['components']['objects']);
        $this->assertSame('txn-1', $result['components']['objects'][0]['@self']['slug']);
        $this->assertSame('policy-standaard-request', $result['components']['objects'][1]['@self']['slug']);
    }//end testComponentsObjectsSeedListIsUnioned()

    /**
     * Lists under non-additive keys are still replaced wholesale.
     *
     * @return void
     */
    public function testNonAdditiveListsStillReplaced(): void
    {
        $result = $this->deepMerge(['required' => ['a', 'b']], ['required' => ['c']]);

        $this->assertSame(['c'], $result['required']);
    }//end testNonAdditiveListsStillReplaced()

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
