<?php

/**
 * Unit tests for IdentityTally.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Repair;

use OCA\Pipelinq\Repair\IdentityKind;
use OCA\Pipelinq\Repair\IdentityTally;
use PHPUnit\Framework\TestCase;

/**
 * IdentityTally counts what the identity repair did and keeps its audit map.
 */
class IdentityTallyTest extends TestCase {

	/**
	 * A fresh tally reports zeroes and the moments it was given.
	 *
	 * @return void
	 */
	public function testAFreshTallyIsEmpty(): void {
		self::assertSame(
			[
				'clientsLinked' => 0,
				'contactsLinked' => 0,
				'skipped' => 0,
				'unresolved' => 0,
				'momentsRekeyed' => 7,
				'auditMap' => [],
			],
			(new IdentityTally())->summary(momentsRekeyed: 7)
		);
	}//end testAFreshTallyIsEmpty()

	/**
	 * Linked rows count per kind; skipped and every other status count apart.
	 *
	 * @return void
	 */
	public function testStatusesAreCountedApart(): void {
		$tally = new IdentityTally();
		$tally->count(status: 'linked', kind: IdentityKind::client());
		$tally->count(status: 'linked', kind: IdentityKind::client());
		$tally->count(status: 'linked', kind: IdentityKind::contact());
		$tally->count(status: 'skipped', kind: IdentityKind::contact());
		$tally->count(status: 'unresolved', kind: IdentityKind::client());
		$tally->count(status: 'anything-else', kind: IdentityKind::contact());

		$summary = $tally->summary(momentsRekeyed: 0);
		self::assertSame(2, $summary['clientsLinked']);
		self::assertSame(1, $summary['contactsLinked']);
		self::assertSame(1, $summary['skipped']);
		self::assertSame(2, $summary['unresolved']);
	}//end testStatusesAreCountedApart()

	/**
	 * Client references map to the contacts uid; a later mapping wins.
	 *
	 * @return void
	 */
	public function testClientRefsMapToTheContactsUid(): void {
		$tally = new IdentityTally();
		$tally->mapClientRefs(refs: ['ref-a', 'ref-b'], contactsUid: 'uid-1');
		$tally->mapClientRefs(refs: ['ref-b'], contactsUid: 'uid-2');

		self::assertSame(['ref-a' => 'uid-1', 'ref-b' => 'uid-2'], $tally->clientUidByRef());
	}//end testClientRefsMapToTheContactsUid()

	/**
	 * The audit map keys an object by its id, else its uuid, else the empty string.
	 *
	 * @return void
	 */
	public function testTheAuditMapKeysByIdThenUuid(): void {
		$tally = new IdentityTally();
		$tally->audit(object: ['id' => 'obj-1', 'uuid' => 'ignored'], contactsUid: 'uid-1');
		$tally->audit(object: ['uuid' => 'obj-2'], contactsUid: 'uid-2');
		$tally->audit(object: [], contactsUid: 'uid-3');

		self::assertSame(
			['obj-1' => 'uid-1', 'obj-2' => 'uid-2', '' => 'uid-3'],
			$tally->summary(momentsRekeyed: 0)['auditMap']
		);
	}//end testTheAuditMapKeysByIdThenUuid()
}//end class
