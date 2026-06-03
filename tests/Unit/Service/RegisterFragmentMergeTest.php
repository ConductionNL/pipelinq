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
     * The register's schema-membership list is additively unioned (ADR-037), so
     * a fragment adding one schema never drops the schemas the monolith declared.
     *
     * @return void
     */
    public function testRegisterSchemasMembershipIsUnioned(): void
    {
        $base = [
            'components' => [
                'registers' => [
                    'pipelinq' => ['schemas' => ['contact', 'request']],
                ],
            ],
        ];
        $override = [
            'components' => [
                'registers' => [
                    'pipelinq' => ['schemas' => ['brpPersoon', 'bsnAuditRecord']],
                ],
            ],
        ];

        $result = $this->deepMerge($base, $override);

        $this->assertSame(
            ['contact', 'request', 'brpPersoon', 'bsnAuditRecord'],
            $result['components']['registers']['pipelinq']['schemas']
        );
    }//end testRegisterSchemasMembershipIsUnioned()

    /**
     * components.objects[] seeds from a fragment extend the monolith's seeds and
     * are de-duplicated by their canonical encoding (ADR-037).
     *
     * @return void
     */
    public function testSeedObjectsAreUnionedAndDeduplicated(): void
    {
        $shared   = ['slug' => 'contact', 'name' => 'Shared seed'];
        $base     = ['components' => ['objects' => [$shared, ['slug' => 'request', 'name' => 'A']]]];
        $override = ['components' => ['objects' => [$shared, ['slug' => 'brpPersoon', 'name' => 'B']]]];

        $result = $this->deepMerge($base, $override);

        $this->assertSame(
            [
                ['slug' => 'contact', 'name' => 'Shared seed'],
                ['slug' => 'request', 'name' => 'A'],
                ['slug' => 'brpPersoon', 'name' => 'B'],
            ],
            $result['components']['objects']
        );
    }//end testSeedObjectsAreUnionedAndDeduplicated()

    /**
     * A list at a non-union dot-path is still replaced wholesale (the union rule
     * is path-scoped, not a blanket list-merge).
     *
     * @return void
     */
    public function testNonUnionListStillReplaced(): void
    {
        $base     = ['components' => ['views' => ['a', 'b']]];
        $override = ['components' => ['views' => ['c']]];

        $result = $this->deepMerge($base, $override);

        $this->assertSame(['c'], $result['components']['views']);
    }//end testNonUnionListStillReplaced()
}//end class
