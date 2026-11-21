<?php

/**
 * SchemaLifecycleGraph lookup order: which schema a slug resolves to when
 * several schemas or files share it, and how the lifecycle and configuration
 * lookups differ in that.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Lifecycle
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Lifecycle;

use OCA\Pipelinq\Service\Lifecycle\SchemaLifecycleGraph;
use PHPUnit\Framework\TestCase;

/**
 * Lookup order across schemas sharing a slug and across register files.
 *
 * @covers \OCA\Pipelinq\Service\Lifecycle\SchemaLifecycleGraph
 */
class SchemaLifecycleGraphLookupOrderTest extends TestCase {

	/**
	 * The settings directory of this test.
	 *
	 * @var string
	 */
	private string $dir;

	/**
	 * Create an empty settings directory with a fragment directory.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/pipelinq-lifecycle-order-' . bin2hex(random_bytes(6));
		mkdir($this->dir . '/register.d', 0777, true);
	}//end setUp()

	/**
	 * Remove the settings directory.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach (array_merge(glob($this->dir . '/register.d/*') ?: [], glob($this->dir . '/*.json') ?: []) as $file) {
			unlink($file);
		}

		rmdir($this->dir . '/register.d');
		rmdir($this->dir);
	}//end tearDown()

	/**
	 * Write a register file with the given schemas.
	 *
	 * @param string $path    Path under the settings directory.
	 * @param array  $schemas The components.schemas value.
	 *
	 * @return void
	 */
	private function write(string $path, array $schemas): void {
		file_put_contents($this->dir . '/' . $path, json_encode(['components' => ['schemas' => $schemas]]));
	}//end write()

	/**
	 * A lifecycle annotation on the status field.
	 *
	 * @param string $from The source state.
	 * @param string $to   The target state.
	 *
	 * @return array
	 */
	private static function lifecycle(string $from, string $to): array {
		return ['field' => 'status', 'transitions' => [['from' => $from, 'to' => $to]]];
	}//end lifecycle()

	/**
	 * The lifecycle comes from the first schema with the slug in a file; a later
	 * schema with the same slug in that file is not consulted.
	 *
	 * @return void
	 */
	public function testTheFirstSchemaWithASlugInAFileIsTheOneRead(): void {
		$this->write(
			path: 'pipelinq_register.json',
			schemas: [
				'first' => ['slug' => 'visit', 'configuration' => ['x-openregister-lifecycle' => self::lifecycle(from: 'a', to: 'b')]],
				'second' => ['slug' => 'visit', 'configuration' => ['x-openregister-lifecycle' => self::lifecycle(from: 'c', to: 'd')]],
			]
		);

		self::assertSame(['a' => ['b']], (new SchemaLifecycleGraph(settingsDir: $this->dir))->adjacencyFor(schemaSlug: 'visit'));
	}//end testTheFirstSchemaWithASlugInAFileIsTheOneRead()

	/**
	 * When the first schema with the slug has no lifecycle, a later schema with
	 * that slug in the same file is not used, but a fragment file declaring it is.
	 *
	 * @return void
	 */
	public function testASchemaWithoutALifecycleDefersToTheNextFileNotTheNextSchema(): void {
		$this->write(
			path: 'pipelinq_register.json',
			schemas: [
				'first' => ['slug' => 'visit', 'configuration' => []],
				'second' => ['slug' => 'visit', 'configuration' => ['x-openregister-lifecycle' => self::lifecycle(from: 'c', to: 'd')]],
			]
		);
		$this->write(
			path: 'register.d/10-visit.json',
			schemas: ['visit' => ['configuration' => ['x-openregister-lifecycle' => self::lifecycle(from: 'e', to: 'f')]]]
		);

		$graph = new SchemaLifecycleGraph(settingsDir: $this->dir);

		self::assertSame(['field' => 'status', 'transitions' => [['from' => 'e', 'to' => 'f']]], $graph->lifecycleFor(schemaSlug: 'visit'));
		self::assertSame(['e' => ['f']], $graph->adjacencyFor(schemaSlug: 'visit'));
	}//end testASchemaWithoutALifecycleDefersToTheNextFileNotTheNextSchema()

	/**
	 * Configuration lookup, unlike the lifecycle lookup, checks every schema with
	 * the slug in a file: an invalid block or a non-array value on an earlier one
	 * does not hide a later one in the same file.
	 *
	 * @return void
	 */
	public function testConfigurationChecksEverySchemaWithTheSlugInAFile(): void {
		$this->write(
			path: 'pipelinq_register.json',
			schemas: [
				'broken' => ['slug' => 'lead', 'configuration' => 'not a block'],
				'flat' => ['slug' => 'lead', 'configuration' => ['x-forecast' => 'flat']],
				'other' => ['slug' => 'other', 'configuration' => ['x-forecast' => ['weight' => 9]]],
				'lead' => ['configuration' => ['x-forecast' => ['weight' => 0.75]]],
			]
		);
		$this->write(
			path: 'register.d/10-lead.json',
			schemas: ['lead' => ['configuration' => ['x-forecast' => ['weight' => 0.1]]]]
		);

		$graph = new SchemaLifecycleGraph(settingsDir: $this->dir);

		self::assertSame(['weight' => 0.75], $graph->configurationFor(schemaSlug: 'lead', key: 'x-forecast'));
		self::assertNull($graph->configurationFor(schemaSlug: 'lead', key: 'x-missing'));
	}//end testConfigurationChecksEverySchemaWithTheSlugInAFile()

	/**
	 * Full adjacency reads the states from the first file that declares the
	 * schema, the same file the lifecycle came from, not from a later fragment.
	 *
	 * @return void
	 */
	public function testFullAdjacencyTakesTheStatesFromTheLifecycleFile(): void {
		$this->write(
			path: 'pipelinq_register.json',
			schemas: ['visit' => [
				'properties' => ['status' => ['enum' => ['a', 'b', 'gone']]],
				'configuration' => ['x-openregister-lifecycle' => self::lifecycle(from: 'a', to: 'b')],
			]]
		);
		$this->write(
			path: 'register.d/10-visit.json',
			schemas: ['visit' => ['properties' => ['status' => ['enum' => ['a', 'b', 'fragment-only']]]]]
		);

		self::assertSame(
			['a' => ['b'], 'b' => [], 'gone' => []],
			(new SchemaLifecycleGraph(settingsDir: $this->dir))->fullAdjacencyFor(schemaSlug: 'visit')
		);
	}//end testFullAdjacencyTakesTheStatesFromTheLifecycleFile()

	/**
	 * The states of a schema no file declares are empty, and so are those of a
	 * declared schema whose field has no enum.
	 *
	 * @return void
	 */
	public function testStatesOfAnUndeclaredSchemaOrFieldAreEmpty(): void {
		$this->write(
			path: 'pipelinq_register.json',
			schemas: ['visit' => ['properties' => ['status' => ['enum' => ['a']], 'note' => ['type' => 'string']]]]
		);

		$graph = new SchemaLifecycleGraph(settingsDir: $this->dir);
		$states = new \ReflectionMethod(SchemaLifecycleGraph::class, 'statesFor');

		self::assertSame(['a'], $states->invoke($graph, 'visit', 'status'));
		self::assertSame([], $states->invoke($graph, 'missing', 'status'));
		self::assertSame([], $states->invoke($graph, 'visit', 'note'));
		self::assertSame([], $states->invoke($graph, 'visit', 'absent'));
	}//end testStatesOfAnUndeclaredSchemaOrFieldAreEmpty()

	/**
	 * Without a fragment directory only the main register file is read.
	 *
	 * @return void
	 */
	public function testWithoutAFragmentDirectoryOnlyTheMainFileIsRead(): void {
		$this->write(
			path: 'pipelinq_register.json',
			schemas: ['visit' => ['configuration' => ['x-openregister-lifecycle' => self::lifecycle(from: 'a', to: 'b')]]]
		);
		rmdir($this->dir . '/register.d');

		try {
			$graph = new SchemaLifecycleGraph(settingsDir: $this->dir);
			$files = new \ReflectionMethod(SchemaLifecycleGraph::class, 'resolveRegisterFiles');

			self::assertSame([$this->dir . '/pipelinq_register.json'], $files->invoke($graph));
			self::assertSame(['a' => ['b']], $graph->adjacencyFor(schemaSlug: 'visit'));
		} finally {
			mkdir($this->dir . '/register.d');
		}
	}//end testWithoutAFragmentDirectoryOnlyTheMainFileIsRead()
}//end class
