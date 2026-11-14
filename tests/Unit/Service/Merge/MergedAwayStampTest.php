<?php

/**
 * Unit tests for MergedAwayStamp.
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
use OCA\OpenRegister\Event\ObjectsMergedEvent;
use OCA\Pipelinq\Service\Merge\MergedAwayStamp;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * The stamp names the surviving record on a merge and is cleared on a reversal.
 *
 * @spec openspec/specs/master-data-management/spec.md#requirement-req-mdm-013-mdm-steward-ui-deep-linked-to-openregister
 */
class MergedAwayStampTest extends TestCase {
	/**
	 * The patches written, by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $patched = [];

	/**
	 * A stamp over client schema "10": the clients "away" and "kept", and a lead "lead" of schema "30".
	 *
	 * @return MergedAwayStamp The stamp.
	 */
	private function stamp(): MergedAwayStamp {
		$records = [
			'away' => ['10', ['name' => 'Gem. Utrecht']],
			'kept' => ['10', ['name' => 'Gemeente Utrecht', 'customerNumber' => 'K-2019-01184']],
			'lead' => ['30', ['title' => 'Koppeling']],
		];
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(
			static function (int|string $id) use ($records): ?ObjectEntity {
				if (isset($records[$id]) === false) {
					return null;
				}

				$record = new ObjectEntity();
				$record->setSchema($records[$id][0]);
				$record->setObject($records[$id][1]);
				return $record;
			}
		);
		$objects->method('patchObject')->willReturnCallback(
			function (string $objectId, array $data): ObjectEntity {
				$this->patched[$objectId] = $data;
				return new ObjectEntity();
			}
		);
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => (['client_schema' => '10', 'contact_schema' => '20'][$key] ?? $default)
		);

		return new MergedAwayStamp(objects: $objects, appConfig: $config);
	}//end stamp()

	/**
	 * A merge writes the survivor's id, name and number, and the moment.
	 *
	 * @return void
	 */
	public function testAMergeNamesTheSurvivingRecord(): void {
		$this->stamp()->apply(uuid: 'away', event: new ObjectsMergedEvent(survivorUuid: 'kept', mergedFromUuids: ['away'], mergeOperationId: 'op-1'));

		$this->assertSame(expected: 'kept', actual: $this->patched['away']['mergedInto']);
		$this->assertSame(expected: 'Gemeente Utrecht (K-2019-01184)', actual: $this->patched['away']['mergedIntoLabel']);
		$this->assertNotEmpty(actual: $this->patched['away']['mergedAt']);
	}//end testAMergeNamesTheSurvivingRecord()

	/**
	 * A reversal clears the stamp; a record that is not a client or contact, or is gone, is not touched.
	 *
	 * @return void
	 */
	public function testAReversalClearsAndOtherRecordsAreLeftAlone(): void {
		$stamp = $this->stamp();
		$reversal = new ObjectsMergedEvent(survivorUuid: 'kept', mergedFromUuids: ['away'], mergeOperationId: 'op-1', isReversal: true);
		$stamp->apply(uuid: 'away', event: $reversal);
		$stamp->apply(uuid: 'lead', event: new ObjectsMergedEvent(survivorUuid: 'kept', mergedFromUuids: ['lead'], mergeOperationId: 'op-2'));
		$stamp->apply(uuid: 'gone', event: new ObjectsMergedEvent(survivorUuid: 'kept', mergedFromUuids: ['gone'], mergeOperationId: 'op-3'));

		$this->assertSame(expected: ['away' => ['mergedInto' => null, 'mergedIntoLabel' => null, 'mergedAt' => null]], actual: $this->patched);
	}//end testAReversalClearsAndOtherRecordsAreLeftAlone()
}//end class
