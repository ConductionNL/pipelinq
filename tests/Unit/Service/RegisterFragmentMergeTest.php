<?php

/**
 * Unit tests for the ADR-037 modular register-fragment deep-merge.
 *
 * Exercises the private static ConfigFileLoaderService::deepMergeConfig()
 * (and its isList() helper) via reflection — the merge semantics that let
 * concurrent same-app builds extend the register without touching the shared
 * pipelinq_register.json monolith. The BI-export fragment relies on the
 * additive union of the register `schemas[]` membership list and the
 * `components.objects[]` seed list (matched by dot-path, not bare key name) so
 * it can add its four export schemas without dropping the monolith's slugs.
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
class RegisterFragmentMergeTest extends TestCase {
	/**
	 * Invoke the private static deepMergeConfig() via reflection.
	 *
	 * @param array $base The base configuration.
	 * @param array $override The fragment to merge on top.
	 * @param string $path The dot-path of the node being merged (root '').
	 *
	 * @return array The merged result.
	 */
	private function deepMerge(array $base, array $override, string $path = ''): array {
		$method = new ReflectionMethod(ConfigFileLoaderService::class, 'deepMergeConfig');
		$method->setAccessible(true);

		return $method->invoke(null, $base, $override, $path);
	}//end deepMerge()

	/**
	 * A fragment key absent from the base is added.
	 *
	 * @return void
	 */
	public function testAddsNewTopLevelKey(): void {
		$result = $this->deepMerge(['a' => 1], ['b' => 2]);

		$this->assertSame(['a' => 1, 'b' => 2], $result);
	}//end testAddsNewTopLevelKey()

	/**
	 * Nested associative objects merge recursively, preserving base siblings.
	 *
	 * @return void
	 */
	public function testMergesNestedObjectsRecursively(): void {
		$base = ['components' => ['schemas' => ['client' => ['title' => 'Client']]]];
		$override = ['components' => ['schemas' => ['lead' => ['title' => 'Lead']]]];

		$result = $this->deepMerge($base, $override);

		$this->assertSame(
			[
				'components' => [
					'schemas' => [
						'client' => ['title' => 'Client'],
						'lead' => ['title' => 'Lead'],
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
	public function testScalarOverrideReplacesBase(): void {
		$result = $this->deepMerge(['info' => ['version' => '1.0.0']], ['info' => ['version' => '2.0.0']]);

		$this->assertSame('2.0.0', $result['info']['version']);
	}//end testScalarOverrideReplacesBase()

	/**
	 * A non-membership list value is replaced wholesale, not deep-merged.
	 *
	 * @return void
	 */
	public function testListValuesAreReplaced(): void {
		$result = $this->deepMerge(['tags' => ['a', 'b']], ['tags' => ['c']]);

		$this->assertSame(['c'], $result['tags']);
	}//end testListValuesAreReplaced()

	/**
	 * The register `schemas[]` membership list is unioned (not replaced) at its
	 * dot-path, so a fragment can add schema slugs without dropping the
	 * monolith's slugs (ADR-037, BI-export fragment).
	 *
	 * @return void
	 */
	public function testRegisterSchemasMembershipIsUnioned(): void {
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
					'pipelinq' => ['schemas' => ['exportJob', 'exportRun']],
				],
			],
		];

		$result = $this->deepMerge($base, $override);

		$this->assertSame(
			['client', 'contact', 'lead', 'exportJob', 'exportRun'],
			$result['components']['registers']['pipelinq']['schemas']
		);
	}//end testRegisterSchemasMembershipIsUnioned()

	/**
	 * Re-merging a fragment whose membership overlaps the base does not
	 * duplicate already-present members (idempotent import).
	 *
	 * @return void
	 */
	public function testSchemasUnionDeduplicates(): void {
		$base = [
			'components' => [
				'registers' => [
					'pipelinq' => ['schemas' => ['client', 'lead']],
				],
			],
		];
		$override = [
			'components' => [
				'registers' => [
					'pipelinq' => ['schemas' => ['lead', 'exportJob']],
				],
			],
		];

		$result = $this->deepMerge($base, $override);

		$this->assertSame(
			['client', 'lead', 'exportJob'],
			$result['components']['registers']['pipelinq']['schemas']
		);
	}//end testSchemasUnionDeduplicates()

	/**
	 * The `components.objects[]` seed list is likewise unioned additively at its
	 * dot-path.
	 *
	 * @return void
	 */
	public function testObjectsSeedListIsUnioned(): void {
		$base = ['components' => ['objects' => ['a', 'b']]];
		$override = ['components' => ['objects' => ['c']]];

		$result = $this->deepMerge($base, $override);

		$this->assertSame(['a', 'b', 'c'], $result['components']['objects']);
	}//end testObjectsSeedListIsUnioned()

	/**
	 * A `schemas` list NOT under the additive register dot-path keeps the
	 * default replace semantics — only the configured membership paths union.
	 *
	 * @return void
	 */
	public function testBareSchemasKeyOutsidePathIsReplaced(): void {
		$result = $this->deepMerge(['schemas' => ['client', 'lead']], ['schemas' => ['exportJob']]);

		$this->assertSame(['exportJob'], $result['schemas']);
	}//end testBareSchemasKeyOutsidePathIsReplaced()

	/**
	 * A fragment that re-declares a schema's `configuration` with a DIFFERENT
	 * annotation key must deep-merge (union) the configuration object, keeping
	 * the base's `x-openregister-mcp` block intact (pipelinq#396).
	 *
	 * Regression guard: the forecast fragment (50-forecast.json) re-declares
	 * `lead.configuration` to add `x-pipelinq-forecast-lifecycle`. Because both
	 * the base and fragment `configuration` are associative maps, deepMergeConfig
	 * recurses and unions their keys — it must NOT shallow-replace the base
	 * configuration (which would drop `x-openregister-mcp` and stop the derived
	 * `pipelinq.lead.search/get` MCP tools from ever registering).
	 *
	 * @return void
	 */
	public function testFragmentConfigurationUnionKeepsBaseMcpAnnotation(): void {
		$base = [
			'components' => [
				'schemas' => [
					'lead' => [
						'slug' => 'lead',
						'configuration' => [
							'x-openregister-mcp' => [
								'enabled' => true,
								'tools' => [
									'search' => ['scope' => 'read', 'filters' => ['status', 'stage', 'client']],
									'get' => ['scope' => 'read'],
								],
							],
						],
					],
				],
			],
		];
		$fragment = [
			'components' => [
				'schemas' => [
					'lead' => [
						'slug' => 'lead',
						'configuration' => [
							'x-pipelinq-forecast-lifecycle' => ['field' => 'forecast_category'],
						],
					],
				],
			],
		];

		$result = $this->deepMerge($base, $fragment);
		$leadConf = $result['components']['schemas']['lead']['configuration'];

		$this->assertArrayHasKey(
			'x-openregister-mcp',
			$leadConf,
			'the base x-openregister-mcp block must survive a fragment that re-declares lead.configuration'
		);
		$this->assertArrayHasKey(
			'x-pipelinq-forecast-lifecycle',
			$leadConf,
			'the fragment forecast annotation must be unioned in'
		);
		$this->assertSame(
			['status', 'stage', 'client'],
			$leadConf['x-openregister-mcp']['tools']['search']['filters'],
			'the mcp search filters must be preserved unchanged'
		);
	}//end testFragmentConfigurationUnionKeepsBaseMcpAnnotation()

	/**
	 * isList() distinguishes sequential lists from associative maps.
	 *
	 * @return void
	 */
	public function testIsListHelper(): void {
		$method = new ReflectionMethod(ConfigFileLoaderService::class, 'isList');
		$method->setAccessible(true);

		$this->assertTrue($method->invoke(null, [0, 1, 2]));
		$this->assertTrue($method->invoke(null, []));
		$this->assertFalse($method->invoke(null, ['a' => 1]));
		$this->assertFalse($method->invoke(null, [1 => 'a', 2 => 'b']));
	}//end testIsListHelper()
}//end class
