<?php

/**
 * Where a relationship came from, and that somebody can see it.
 *
 * 🔴 PROVENANCE SHIPPED INVISIBLE IS PROVENANCE NOBODY HAS. Two properties on
 * this very schema, `notes` and `startDate`, carry `visible: false`, so a
 * `provenance` added the same way would be stored, facetable, validated, and
 * never once shown to the person deciding whether to trust the relationship.
 * That is the failure this suite exists to prevent, and it is why visibility is
 * asserted rather than assumed.
 *
 * 🔑 IT IS AN ENUM, NOT FREE TEXT, BECAUSE A READER HAS TO BRANCH ON IT. The
 * point of recording where a relationship came from is that a register's
 * assertion and somebody's typing are treated differently. Free text cannot be
 * branched on, so it would record the fact in a form nothing can act upon,
 * which is `notes` again under a better name.
 *
 * @category Tests
 * @package  OCA\Pipelinq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.pipelinq.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * `relationship.provenance` in the shipped register.
 *
 * @coversNothing
 */
class RelationshipProvenanceTest extends TestCase {

	/**
	 * The relationship schema as shipped.
	 *
	 * @return array<string, mixed> The schema.
	 */
	private function schema(): array {
		$path = dirname(__DIR__, 3) . '/lib/Settings/pipelinq_register.json';
		$this->assertFileExists($path);

		$register = json_decode((string)file_get_contents($path), true);

		$this->assertIsArray($register, 'the register must be valid JSON');

		return ($register['components']['schemas']['relationship'] ?? []);
	}//end schema()

	/**
	 * The relationship carries where it came from.
	 *
	 * 🔑 ADDED TO THE SCHEMA PIPELINQ ALREADY SHIPS, NOT TO A NEW ONE. The
	 * openregister change `relations-that-travel-and-what-they-expose` asked for
	 * a `partyRelationship` schema with two party references, a type, a period
	 * and a provenance. The first four were already here as `fromContact`,
	 * `toContact`, `type` and `startDate`/`endDate`; only provenance was
	 * missing. A second schema would have meant two sets of stored rows for one
	 * concept, with nothing reconciling them.
	 *
	 * @return void
	 */
	public function testARelationshipRecordsWhereItCameFrom(): void {
		$properties = ($this->schema()['properties'] ?? []);

		$this->assertArrayHasKey('provenance', $properties);
		$this->assertSame('string', $properties['provenance']['type']);
	}//end testARelationshipRecordsWhereItCameFrom()

	/**
	 * 🔴 IT IS VISIBLE, SO SOMEBODY ACTUALLY READS IT.
	 *
	 * The client detail page renders a Relationships section from this schema's
	 * properties, so a visible property is shown and a `visible: false` one is
	 * not. `notes` and `startDate` on this same schema are hidden that way, and
	 * a provenance hidden with them would be a field the system knows and no
	 * person ever sees.
	 *
	 * @return void
	 */
	public function testProvenanceIsVisibleAndNotShippedDark(): void {
		$provenance = ($this->schema()['properties']['provenance'] ?? []);

		$this->assertNotSame(
			false,
			($provenance['visible'] ?? true),
			'A provenance nobody can see is a provenance nobody has.'
		);
	}//end testProvenanceIsVisibleAndNotShippedDark()

	/**
	 * It is an enum with a default, so a reader can branch on it.
	 *
	 * The default matters as much as the list: every relationship written
	 * before this existed has no provenance, and reading that absence as
	 * anything stronger than `declared` would credit old rows with an authority
	 * nobody gave them.
	 *
	 * @return void
	 */
	public function testProvenanceIsAClosedListWithTheWeakestDefault(): void {
		$provenance = ($this->schema()['properties']['provenance'] ?? []);

		$this->assertSame(
			['declared', 'imported', 'derived', 'authoritative-source'],
			($provenance['enum'] ?? [])
		);
		$this->assertSame(
			'declared',
			($provenance['default'] ?? null),
			'The default must be the weakest value, or silence is read as authority.'
		);
	}//end testProvenanceIsAClosedListWithTheWeakestDefault()

	/**
	 * The schema version moved with the property.
	 *
	 * A consumer pinning a version would otherwise receive a schema whose shape
	 * changed under an unchanged number.
	 *
	 * @return void
	 */
	public function testTheSchemaVersionMovedWithIt(): void {
		$this->assertSame('1.1.0', ($this->schema()['version'] ?? null));
	}//end testTheSchemaVersionMovedWithIt()

	/**
	 * The properties the openregister change asked for are all here.
	 *
	 * Derived from that change's own wording rather than restated, so a future
	 * reader can see why no `partyRelationship` schema was ever built.
	 *
	 * @return void
	 */
	public function testTheSchemaSatisfiesTheRelationsChange(): void {
		$properties = ($this->schema()['properties'] ?? []);

		foreach (
			[
				'fromContact' => 'a party reference',
				'toContact' => 'the other party reference',
				'type' => 'the relationship type',
				'inverseType' => 'its reciprocal label',
				'fromType' => 'the party kind at one end',
				'toType' => 'the party kind at the other',
				'startDate' => 'the start of the period',
				'endDate' => 'the end of the period',
				'provenance' => 'where it came from',
			] as $property => $why
		) {
			$this->assertArrayHasKey($property, $properties, $property . ' is ' . $why);
		}
	}//end testTheSchemaSatisfiesTheRelationsChange()
}//end class
