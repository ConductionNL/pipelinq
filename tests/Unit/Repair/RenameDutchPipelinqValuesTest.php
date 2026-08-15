<?php

/**
 * Tests for the stored-enum-value migration.
 *
 * @category  Test
 * @package   OCA\Pipelinq\Tests\Unit\Repair
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Repair;

use OCA\Pipelinq\Repair\RenameDutchPipelinqValues;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;

/**
 * The value migration, driven through a mocked connection.
 *
 * PHPUnit assertions take positional arguments; the named-parameter sniff does
 * not apply to them.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 *
 * @covers \OCA\Pipelinq\Repair\RenameDutchPipelinqValues
 */
final class RenameDutchPipelinqValuesTest extends TestCase {

	/**
	 * The step under test.
	 *
	 * @var RenameDutchPipelinqValues
	 */
	private RenameDutchPipelinqValues $step;

	/**
	 * Mocked database connection.
	 *
	 * @var IDBConnection
	 */
	private $db;

	/**
	 * Build the step with mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->db = $this->createMock(IDBConnection::class);
		$this->step = new RenameDutchPipelinqValues($this->db, $this->createMock(LoggerInterface::class));

	}//end setUp()

	/**
	 * Invoke a private method on the step.
	 *
	 * @param string       $name Method name.
	 * @param array<mixed> $args Positional arguments.
	 *
	 * @return mixed
	 */
	private function call(string $name, array $args) {
		$m = new ReflectionMethod(RenameDutchPipelinqValues::class, $name);
		$m->setAccessible(true);
		return $m->invokeArgs($this->step, $args);

	}//end call()

	/**
	 * The step names itself.
	 *
	 * @return void
	 */
	public function testStepNamesItself(): void {
		self::assertStringContainsString('value', strtolower($this->step->getName()));

	}//end testStepNamesItself()

	/**
	 * Property names snake down to the columns MagicMapper materialised.
	 *
	 * MagicMapper applies ONLY the ([a-z0-9])([A-Z]) boundary — no acronym rule.
	 * A column name spelled any other way matches nothing, and the migration is
	 * a silent no-op rather than an error.
	 *
	 * @return void
	 */
	public function testColumnForMirrorsMagicMapper(): void {
		self::assertSame('response_status', $this->call('columnFor', ['responseStatus']));
		self::assertSame('vat_class', $this->call('columnFor', ['vatClass']));
		self::assertSame('status', $this->call('columnFor', ['status']));
		self::assertSame('ticket_type', $this->call('columnFor', ['ticketType']));

		foreach (array_keys(RenameDutchPipelinqValues::VALUE_MAP) as $property) {
			self::assertMatchesRegularExpression(
				'/^[a-z][a-z0-9_]*$/',
				(string)$this->call('columnFor', [$property]),
				$property
			);
		}

	}//end testColumnForMirrorsMagicMapper()

	/**
	 * Every mapped value actually changes, and no target is also a source.
	 *
	 * A target that is also somebody's source means the ORDER of the map decides
	 * the result — and a value mapping to itself is a migration that runs an
	 * UPDATE for nothing.
	 *
	 * @return void
	 */
	public function testMapEntriesChangeSomethingAndDoNotChain(): void {
		$map = RenameDutchPipelinqValues::VALUE_MAP;
		self::assertNotSame([], $map);

		foreach ($map as $property => $values) {
			$sources = array_keys($values);
			foreach ($values as $old => $new) {
				self::assertNotSame($old, $new, "`$old` on `$property` maps to itself");
				self::assertNotContains(
					$new,
					$sources,
					sprintf("target '%s' on '%s' is also a source, so map order would decide the result", $new, $property)
				);
			}
		}

	}//end testMapEntriesChangeSomethingAndDoNotChain()

	/**
	 * The adapter schemas' values are NOT in the map.
	 *
	 * `zgwResourceType` and `actorType` belong to the ZGW and VNG
	 * Klantinteracties mappings. A mapping is configuration, and the standard's
	 * vocabulary stays in the standard's language — renaming VNG Klantinteracties
	 * names has already caused a live break in this programme.
	 *
	 * @return void
	 */
	public function testAdapterVocabularyIsNotMigrated(): void {
		$map = RenameDutchPipelinqValues::VALUE_MAP;

		self::assertArrayNotHasKey('zgwResourceType', $map);
		self::assertArrayNotHasKey('actorType', $map);

		$allSources = [];
		foreach ($map as $values) {
			$allSources = array_merge($allSources, array_keys($values));
		}

		foreach (['zaak', 'besluit', 'informatieobject', 'medewerker', 'organisatorischeEenheid'] as $wire) {
			self::assertNotContains($wire, $allSources, sprintf("'%s' is standard vocabulary and must not be migrated", $wire));
		}

	}//end testAdapterVocabularyIsNotMigrated()

	/**
	 * With no shard tables the step reports it and touches nothing.
	 *
	 * @return void
	 */
	public function testNoShardTablesIsANoOp(): void {
		$this->db->method('prepare')->willThrowException(new \RuntimeException('no information_schema'));
		$this->db->expects(self::never())->method('executeStatement');

		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info')
			->with(self::stringContains('nothing to do'));

		$this->step->run($output);

	}//end testNoShardTablesIsANoOp()

	/**
	 * The map covers every property the value pass translated.
	 *
	 * The schema edit and this migration are two halves of one change; if the
	 * map loses a property, rows keep the Dutch string while the schema no
	 * longer declares it.
	 *
	 * @return void
	 */
	public function testMapCoversTheTranslatedProperties(): void {
		$map = RenameDutchPipelinqValues::VALUE_MAP;

		foreach (['status', 'vatClass', 'priority', 'outcome', 'ticketType', 'gender'] as $property) {
			self::assertArrayHasKey($property, $map, $property);
			self::assertNotSame([], $map[$property]);
		}

	}//end testMapCoversTheTranslatedProperties()

	/**
	 * The class is a repair step.
	 *
	 * @return void
	 */
	public function testIsARepairStep(): void {
		self::assertTrue(
			(new ReflectionClass(RenameDutchPipelinqValues::class))->implementsInterface(\OCP\Migration\IRepairStep::class)
		);

	}//end testIsARepairStep()
}//end class
