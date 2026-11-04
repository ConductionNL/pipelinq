<?php

/**
 * Unit tests for MergedAwayRecordGuard.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Listener
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

namespace OCA\Pipelinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Pipelinq\Listener\MergedAwayRecordGuard;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * A merged-away record is read-only; undoing the merge is not refused.
 *
 * @spec openspec/changes/clients-reversible-merge/specs/client-reversible-merge/spec.md#requirement-clients-and-contacts-merge-through-openregisters-reversible-merge-req-crm-001
 */
class MergedAwayRecordGuardTest extends TestCase {
	private const STAMP = ['mergedInto' => 'c-1', 'mergedIntoLabel' => 'Gemeente Utrecht', 'mergedAt' => '2026-10-02T10:00:00+02:00'];

	/**
	 * Run the guard on an update from $old to $new and say whether it was refused.
	 *
	 * @param array<string, mixed> $old The record before.
	 * @param array<string, mixed> $new The record after.
	 *
	 * @return bool True when the update was refused.
	 */
	private function refused(array $old, array $new): bool {
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$event = new ObjectUpdatingEvent(newObject: $this->record(data: $new), oldObject: $this->record(data: $old));

		(new MergedAwayRecordGuard(l10n: $l10n))->handle(event: $event);

		return $event->isPropagationStopped();
	}//end refused()

	/**
	 * A client record with the given data.
	 *
	 * @param array<string, mixed> $data The data.
	 *
	 * @return ObjectEntity The record.
	 */
	private function record(array $data): ObjectEntity {
		$record = new ObjectEntity();
		$record->setObject($data);
		return $record;
	}//end record()

	/**
	 * Changing the name of a merged-away client is refused.
	 *
	 * @return void
	 */
	public function testAChangeToAMergedAwayRecordIsRefused(): void {
		$old = (['name' => 'Gem. Utrecht'] + self::STAMP);
		$this->assertTrue(condition: $this->refused(old: $old, new: (['name' => 'Gemeente Utrecht-Oost'] + self::STAMP)));
	}//end testAChangeToAMergedAwayRecordIsRefused()

	/**
	 * Writing the stamp, undoing the merge and editing a record that stands on its own all pass.
	 *
	 * @return void
	 */
	public function testTheStampTheReversalAndOrdinaryEditsPass(): void {
		$plain = ['name' => 'Gem. Utrecht'];
		$this->assertFalse(condition: $this->refused(old: $plain, new: ($plain + self::STAMP)), message: 'the stamp');
		$this->assertFalse(condition: $this->refused(old: ($plain + self::STAMP), new: ($plain + ['mergedInto' => null])), message: 'the reversal');
		$this->assertFalse(condition: $this->refused(old: $plain, new: ['name' => 'Gemeente Utrecht']), message: 'an edit');
		$restamp = ($plain + ['mergedAt' => 'x'] + self::STAMP);
		$this->assertFalse(condition: $this->refused(old: ($plain + self::STAMP), new: $restamp), message: 'the stamp again');
	}//end testTheStampTheReversalAndOrdinaryEditsPass()
}//end class
