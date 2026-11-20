<?php

/**
 * Unit tests for PartyReferenceMover's calls into OpenRegister.
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
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectPatch;
use OCA\Pipelinq\Service\Merge\PartyReferenceMover;
use OCA\Pipelinq\Service\Merge\PartyReferences;
use PHPUnit\Framework\TestCase;

/**
 * Which schemas a merge searches, with which filter and access flags, how entity
 * rows are read, and that a reversal leaves a row edited by hand after the merge.
 *
 * @covers \OCA\Pipelinq\Service\Merge\PartyReferenceMover
 * @uses   \OCA\Pipelinq\Service\Merge\PartyReferences
 *
 * @spec openspec/changes/clients-reversible-merge/specs/client-reversible-merge/spec.md
 */
class PartyReferenceMoverCallsTest extends TestCase {

	/**
	 * Every search, as [register, schema, filters, rbac, multitenancy].
	 *
	 * @var list<array{0: string, 1: string, 2: array<string, mixed>, 3: bool, 4: bool}>
	 */
	private array $searches = [];

	/**
	 * Every patch, as [uuid, data, rbac, multitenancy].
	 *
	 * @var list<array{0: string, 1: array<string, mixed>, 2: bool, 3: bool}>
	 */
	private array $patches = [];

	/**
	 * Every find, as [id, render, audit, rbac, multitenancy].
	 *
	 * @var list<array{0: string, 1: bool, 2: bool, 3: bool, 4: bool}>
	 */
	private array $finds = [];

	/**
	 * An entity with a uuid and object data.
	 *
	 * @param string               $uuid The uuid.
	 * @param array<string, mixed> $data The object data.
	 *
	 * @return ObjectEntity
	 */
	private static function entity(string $uuid, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * A mover over a recording object service.
	 *
	 * @param array<string, array<int, mixed>> $search Search rows by schema slug.
	 * @param array<string, array<string, mixed>> $found Object data by uuid.
	 *
	 * @return PartyReferenceMover
	 */
	private function mover(array $search=[], array $found=[]): PartyReferenceMover {
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->method('searchObjectsBySlug')->willReturnCallback(
			function (string $registerSlug, string $schemaSlug, array $filters=[], bool $_rbac=true, bool $_multitenancy=true) use ($search): array {
				$this->searches[] = [$registerSlug, $schemaSlug, $filters, $_rbac, $_multitenancy];
				return $search[$schemaSlug] ?? [];
			}
		);
		$objects->method('find')->willReturnCallback(
			function (int|string $id, ?ObjectFindOptions $options=null, bool $_rbac=true, bool $_multitenancy=true) use ($found): ?ObjectEntity {
				$this->finds[] = [(string)$id, $options?->render, $options?->audit, $_rbac, $_multitenancy];
				if (isset($found[$id]) === false) {
					return null;
				}

				return self::entity(uuid: (string)$id, data: $found[$id]);
			}
		);
		$objects->method('patchObject')->willReturnCallback(
			function (ObjectPatch $patch, bool $_rbac=true, bool $_multitenancy=true): ObjectEntity {
				$this->patches[] = [$patch->objectId, $patch->data, $_rbac, $_multitenancy];
				return new ObjectEntity();
			}
		);

		return new PartyReferenceMover(objects: $objects);
	}//end mover()

	/**
	 * A contact merge searches every contact reference in the pipelinq register,
	 * as the system, filtered on the merged-away uuid.
	 *
	 * @return void
	 */
	public function testAContactMergeSearchesEveryContactReferenceAsTheSystem(): void {
		$this->mover()->move(partyType: 'contact', fromUuid: 'away', intoUuid: 'kept');

		$expected = [];
		foreach (PartyReferences::CONTACT as [$schema, $property]) {
			$expected[] = ['pipelinq', $schema, [$property => 'away'], false, false];
		}

		$this->assertSame(expected: $expected, actual: $this->searches);
		$this->assertSame(expected: [], actual: $this->patches);
	}//end testAContactMergeSearchesEveryContactReferenceAsTheSystem()

	/**
	 * Entity rows are read by their uuid and object; a row holding another value or
	 * no uuid is not moved, and a row found under two properties moves once per property.
	 *
	 * @return void
	 */
	public function testEntityRowsAreMovedPerReferenceProperty(): void {
		$mover = $this->mover(
			search: [
				'relationship' => [
					self::entity(uuid: 'rel-1', data: ['fromContact' => 'away', 'toContact' => 'away']),
					self::entity(uuid: 'rel-2', data: ['fromContact' => 'other', 'toContact' => 'away']),
					self::entity(uuid: '', data: ['fromContact' => 'away', 'toContact' => 'away']),
				],
				'ticket' => [self::entity(uuid: 'ticket-1', data: ['contact' => 'away'])],
			]
		);

		$moved = $mover->move(partyType: 'contact', fromUuid: 'away', intoUuid: 'kept');

		$this->assertSame(
			expected: [
				['schema' => 'relationship', 'uuid' => 'rel-1', 'property' => 'fromContact'],
				['schema' => 'relationship', 'uuid' => 'rel-1', 'property' => 'toContact'],
				['schema' => 'relationship', 'uuid' => 'rel-2', 'property' => 'toContact'],
				['schema' => 'ticket', 'uuid' => 'ticket-1', 'property' => 'contact'],
			],
			actual: $moved
		);
		$this->assertSame(
			expected: [
				['rel-1', ['fromContact' => 'kept'], false, false],
				['rel-1', ['toContact' => 'kept'], false, false],
				['rel-2', ['toContact' => 'kept'], false, false],
				['ticket-1', ['contact' => 'kept'], false, false],
			],
			actual: $this->patches
		);
	}//end testEntityRowsAreMovedPerReferenceProperty()

	/**
	 * A reversal reads each row as the system without rendering or auditing, leaves a
	 * row that was pointed elsewhere by hand, and restores the rest as the system.
	 *
	 * @return void
	 */
	public function testAReversalLeavesARowEditedByHand(): void {
		$mover = $this->mover(found: ['lead-1' => ['client' => 'kept'], 'lead-2' => ['client' => 'someone-else'], 'lead-3' => []]);

		$result = $mover->restore(
			ledger: [
				['schema' => 'lead', 'uuid' => 'lead-1', 'property' => 'client'],
				['schema' => 'lead', 'uuid' => 'lead-2', 'property' => 'client'],
				['schema' => 'lead', 'uuid' => 'lead-3', 'property' => 'client'],
			],
			fromUuid: 'away',
			intoUuid: 'kept'
		);

		$this->assertSame(expected: [['schema' => 'lead', 'uuid' => 'lead-1', 'property' => 'client']], actual: $result['restored']);
		$this->assertSame(
			expected: [
				['schema' => 'lead', 'uuid' => 'lead-2', 'property' => 'client'],
				['schema' => 'lead', 'uuid' => 'lead-3', 'property' => 'client'],
			],
			actual: $result['skipped']
		);
		$this->assertSame(expected: [['lead-1', ['client' => 'away'], false, false]], actual: $this->patches);
		$this->assertSame(
			expected: [
				['lead-1', false, false, false, false],
				['lead-2', false, false, false, false],
				['lead-3', false, false, false, false],
			],
			actual: $this->finds
		);
	}//end testAReversalLeavesARowEditedByHand()
}//end class
