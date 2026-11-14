<?php

/**
 * Unit tests for PartyReferenceMover.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Merge
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Merge;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Pipelinq\Service\Merge\PartyReferenceMover;
use PHPUnit\Framework\TestCase;

/**
 * The mover's edges: array-shaped search rows, a search that returns a count,
 * malformed ledger rows and rows that are gone by the time of a reversal.
 *
 * @covers \OCA\Pipelinq\Service\Merge\PartyReferenceMover
 * @uses   \OCA\Pipelinq\Service\Merge\PartyReferences
 *
 * @spec openspec/changes/clients-reversible-merge/specs/client-reversible-merge/spec.md
 */
class PartyReferenceMoverTest extends TestCase {
	/**
	 * The patches written, in order, as [uuid, data].
	 *
	 * @var list<array{0: string, 1: array<string, mixed>}>
	 */
	private array $patched = [];

	/**
	 * A mover whose search answers per schema slug and whose find answers per uuid.
	 *
	 * @param array<string, array<int, mixed>|int> $search Search results by schema slug; others are empty.
	 * @param array<string, array<string, mixed>>  $found  Object data by uuid; others are gone.
	 *
	 * @return PartyReferenceMover The mover.
	 */
	private function mover(array $search=[], array $found=[]): PartyReferenceMover {
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->method('searchObjectsBySlug')->willReturnCallback(
			static fn (string $registerSlug, string $schemaSlug): array|int => $search[$schemaSlug] ?? []
		);
		$objects->method('find')->willReturnCallback(
			static function (int|string $id) use ($found): ?ObjectEntity {
				if (isset($found[$id]) === false) {
					return null;
				}

				$record = new ObjectEntity();
				$record->setObject($found[$id]);
				return $record;
			}
		);
		$objects->method('patchObject')->willReturnCallback(
			function (string $objectId, array $data): ObjectEntity {
				$this->patched[] = [$objectId, $data];
				return new ObjectEntity();
			}
		);

		return new PartyReferenceMover(objects: $objects);
	}//end mover()

	/**
	 * Array-shaped rows are read by @self.id, uuid or id; rows that are not arrays,
	 * have no uuid or hold another value are left alone, and duplicates move once.
	 *
	 * @return void
	 */
	public function testArrayRowsAreMovedByTheirIdAndOthersAreLeftAlone(): void {
		$mover = $this->mover(
			search: [
				'lead' => [
					['@self' => ['id' => 'lead-1'], 'client' => 'away'],
					['uuid' => 'lead-2', 'client' => 'away'],
					['id' => 'lead-3', 'client' => 'away'],
					['uuid' => 'lead-2', 'client' => 'away'],
					['uuid' => 'lead-4', 'client' => 'other'],
					['client' => 'away'],
					'not-a-row',
					42,
				],
				// A search that answers with a count moves nothing.
				'ticket' => 7,
			]
		);

		$moved = $mover->move(partyType: 'client', fromUuid: 'away', intoUuid: 'kept');

		$this->assertSame(
			expected: [
				['schema' => 'lead', 'uuid' => 'lead-1', 'property' => 'client'],
				['schema' => 'lead', 'uuid' => 'lead-2', 'property' => 'client'],
				['schema' => 'lead', 'uuid' => 'lead-3', 'property' => 'client'],
			],
			actual: $moved
		);
		$this->assertSame(
			expected: [
				['lead-1', ['client' => 'kept']],
				['lead-2', ['client' => 'kept']],
				['lead-3', ['client' => 'kept']],
			],
			actual: $this->patched
		);
	}//end testArrayRowsAreMovedByTheirIdAndOthersAreLeftAlone()

	/**
	 * An unknown party type has no references and moves nothing.
	 *
	 * @return void
	 */
	public function testAnUnknownPartyTypeMovesNothing(): void {
		$mover = $this->mover(search: ['lead' => [['uuid' => 'lead-1', 'client' => 'away']]]);

		$this->assertSame(expected: [], actual: $mover->move(partyType: 'supplier', fromUuid: 'away', intoUuid: 'kept'));
		$this->assertSame(expected: [], actual: $this->patched);
	}//end testAnUnknownPartyTypeMovesNothing()

	/**
	 * A reversal ignores ledger rows that are not arrays, skips rows without a uuid or
	 * property and rows that are gone, and restores the rest.
	 *
	 * @return void
	 */
	public function testAReversalSkipsMalformedAndMissingRows(): void {
		$mover = $this->mover(found: ['lead-1' => ['client' => 'kept']]);

		$result = $mover->restore(
			ledger: [
				'not-a-row',
				['schema' => 'lead', 'uuid' => 'lead-1', 'property' => 'client'],
				['schema' => 'lead', 'uuid' => '', 'property' => 'client'],
				['schema' => 'lead', 'uuid' => 'lead-2'],
				['schema' => 'lead', 'uuid' => 'gone', 'property' => 'client'],
			],
			fromUuid: 'away',
			intoUuid: 'kept'
		);

		$this->assertSame(
			expected: [
				'restored' => [['schema' => 'lead', 'uuid' => 'lead-1', 'property' => 'client']],
				'skipped' => [
					['schema' => 'lead', 'uuid' => '', 'property' => 'client'],
					['schema' => 'lead', 'uuid' => 'lead-2', 'property' => ''],
					['schema' => 'lead', 'uuid' => 'gone', 'property' => 'client'],
				],
			],
			actual: $result
		);
		$this->assertSame(expected: [['lead-1', ['client' => 'away']]], actual: $this->patched);
	}//end testAReversalSkipsMalformedAndMissingRows()
}//end class
