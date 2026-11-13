<?php

/**
 * SchemaLifecycleGraph over a settings directory written by the test: the main
 * register file, fragment files in register.d, and files or schemas that are
 * malformed.
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
 * Reading lifecycles, states and configuration across register files.
 *
 * @covers \OCA\Pipelinq\Service\Lifecycle\SchemaLifecycleGraph
 */
class SchemaLifecycleGraphFilesTest extends TestCase {

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
		$this->dir = sys_get_temp_dir() . '/pipelinq-lifecycle-' . bin2hex(random_bytes(6));
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
	 * @param mixed  $schemas The components.schemas value.
	 *
	 * @return void
	 */
	private function write(string $path, mixed $schemas): void {
		file_put_contents($this->dir . '/' . $path, json_encode(['components' => ['schemas' => $schemas]]));
	}//end write()

	/**
	 * A schema with a lifecycle on its status field.
	 *
	 * @param array $transitions The transitions.
	 * @param array $states      The status enum.
	 * @param array $extra       Extra configuration.
	 *
	 * @return array
	 */
	private static function schema(array $transitions, array $states = [], array $extra = []): array {
		return [
			'properties' => ['status' => ['enum' => $states]],
			'configuration' => ['x-openregister-lifecycle' => ['field' => 'status', 'transitions' => $transitions]] + $extra,
		];
	}//end schema()

	/**
	 * A schema only declared in a fragment file is found there.
	 *
	 * @return void
	 */
	public function testAFragmentFileIsReadAfterTheMainFile(): void {
		$this->write(path: 'pipelinq_register.json', schemas: ['lead' => self::schema([['from' => 'new', 'to' => 'won']])]);
		$this->write(path: 'register.d/20-visit.json', schemas: ['visit' => self::schema([['from' => 'planned', 'to' => 'done']])]);

		$graph = new SchemaLifecycleGraph(settingsDir: $this->dir);

		self::assertSame(['new' => ['won']], $graph->adjacencyFor(schemaSlug: 'lead'));
		self::assertSame(['planned' => ['done']], $graph->adjacencyFor(schemaSlug: 'visit'));
	}//end testAFragmentFileIsReadAfterTheMainFile()

	/**
	 * The main file wins over a fragment, and fragments are read in name order.
	 *
	 * @return void
	 */
	public function testTheFirstFileDeclaringASchemaWins(): void {
		$this->write(path: 'register.d/20-b.json', schemas: ['visit' => self::schema([['from' => 'b', 'to' => 'x']])]);
		$this->write(path: 'register.d/10-a.json', schemas: ['visit' => self::schema([['from' => 'a', 'to' => 'x']])]);

		self::assertSame(['a' => ['x']], (new SchemaLifecycleGraph(settingsDir: $this->dir))->adjacencyFor(schemaSlug: 'visit'));

		$this->write(path: 'pipelinq_register.json', schemas: ['visit' => self::schema([['from' => 'main', 'to' => 'x']])]);

		self::assertSame(['main' => ['x']], (new SchemaLifecycleGraph(settingsDir: $this->dir))->adjacencyFor(schemaSlug: 'visit'));
	}//end testTheFirstFileDeclaringASchemaWins()

	/**
	 * A malformed file, a non-list schemas value and a non-array schema are skipped.
	 *
	 * @return void
	 */
	public function testMalformedFilesAndSchemasAreSkipped(): void {
		file_put_contents($this->dir . '/pipelinq_register.json', '{not json');
		$this->write(path: 'register.d/10-scalar.json', schemas: 'nothing');
		$this->write(path: 'register.d/20-odd.json', schemas: ['visit' => 'not a schema']);
		$this->write(path: 'register.d/30-good.json', schemas: ['visit' => self::schema([['from' => 'a', 'to' => 'b']], ['a', 'b'])]);

		$graph = new SchemaLifecycleGraph(settingsDir: $this->dir);

		self::assertSame(['a' => ['b']], $graph->adjacencyFor(schemaSlug: 'visit'));
		self::assertSame(['a' => ['b'], 'b' => []], $graph->fullAdjacencyFor(schemaSlug: 'visit'));
	}//end testMalformedFilesAndSchemasAreSkipped()

	/**
	 * A schema is matched on its slug before its key.
	 *
	 * @return void
	 */
	public function testASchemaIsMatchedOnItsSlugBeforeItsKey(): void {
		$this->write(
			path: 'pipelinq_register.json',
			schemas: ['Visit' => ['slug' => 'visit'] + self::schema([['from' => 'a', 'to' => 'b']])]
		);

		$graph = new SchemaLifecycleGraph(settingsDir: $this->dir);

		self::assertSame(['a' => ['b']], $graph->adjacencyFor(schemaSlug: 'visit'));
		self::assertSame([], $graph->adjacencyFor(schemaSlug: 'Visit'));
	}//end testASchemaIsMatchedOnItsSlugBeforeItsKey()

	/**
	 * Full adjacency adds every enum state as a node, as text, using `property` when there is no `field`.
	 *
	 * @return void
	 */
	public function testFullAdjacencyAddsEveryEnumStateAsText(): void {
		$schema = [
			'properties' => ['phase' => ['enum' => ['open', 2, 'closed']]],
			'configuration' => ['x-openregister-lifecycle' => ['property' => 'phase', 'transitions' => [['from' => 'open', 'to' => 'closed']]]],
		];
		$this->write(path: 'pipelinq_register.json', schemas: ['ticket' => $schema]);

		self::assertSame(
			['open' => ['closed'], '2' => [], 'closed' => []],
			(new SchemaLifecycleGraph(settingsDir: $this->dir))->fullAdjacencyFor(schemaSlug: 'ticket')
		);
	}//end testFullAdjacencyAddsEveryEnumStateAsText()

	/**
	 * Without a field, or with a non-list enum, full adjacency is the declared graph alone.
	 *
	 * @return void
	 */
	public function testWithoutAFieldOrEnumNoStatesAreAdded(): void {
		$noField = ['configuration' => ['x-openregister-lifecycle' => ['transitions' => [['from' => 'a', 'to' => 'b']]]]];
		$badEnum = self::schema([['from' => 'a', 'to' => 'b']]);
		$badEnum['properties']['status']['enum'] = 'x';
		$this->write(path: 'pipelinq_register.json', schemas: ['one' => $noField, 'two' => $badEnum]);

		$graph = new SchemaLifecycleGraph(settingsDir: $this->dir);

		self::assertSame(['a' => ['b']], $graph->fullAdjacencyFor(schemaSlug: 'one'));
		self::assertSame(['a' => ['b']], $graph->fullAdjacencyFor(schemaSlug: 'two'));
	}//end testWithoutAFieldOrEnumNoStatesAreAdded()

	/**
	 * A schema without a lifecycle, or with a non-array configuration, has none.
	 *
	 * @return void
	 */
	public function testASchemaWithoutALifecycleHasNone(): void {
		$this->write(
			path: 'pipelinq_register.json',
			schemas: ['plain' => ['configuration' => ['x-openregister-lifecycle' => 'off']], 'odd' => ['configuration' => 'x']]
		);

		$graph = new SchemaLifecycleGraph(settingsDir: $this->dir);

		self::assertNull($graph->lifecycleFor(schemaSlug: 'plain'));
		self::assertNull($graph->lifecycleFor(schemaSlug: 'odd'));
		self::assertSame([], $graph->fullAdjacencyFor(schemaSlug: 'plain'));
	}//end testASchemaWithoutALifecycleHasNone()

	/**
	 * Configuration is found in a later file when an earlier one lacks it, and a non-array value is no configuration.
	 *
	 * @return void
	 */
	public function testConfigurationIsLookedUpAcrossFiles(): void {
		$this->write(path: 'pipelinq_register.json', schemas: ['lead' => ['configuration' => ['x-forecast' => 'flat']], 'other' => ['configuration' => 'x']]);
		$this->write(path: 'register.d/10-lead.json', schemas: ['lead' => ['configuration' => ['x-forecast' => ['weight' => 0.5]]]]);

		$graph = new SchemaLifecycleGraph(settingsDir: $this->dir);

		self::assertSame(['weight' => 0.5], $graph->configurationFor(schemaSlug: 'lead', key: 'x-forecast'));
		self::assertNull($graph->configurationFor(schemaSlug: 'other', key: 'x-forecast'));
	}//end testConfigurationIsLookedUpAcrossFiles()
}//end class
