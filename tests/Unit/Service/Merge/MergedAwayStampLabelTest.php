<?php

/**
 * Unit tests for MergedAwayStamp's label, schemas and write flags.
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

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectsMergedEvent;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectPatch;
use OCA\Pipelinq\Service\Merge\MergedAwayStamp;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * A contact is stamped like a client; the label drops a missing number or name;
 * an unset schema setting never matches a record without a schema.
 *
 * @covers \OCA\Pipelinq\Service\Merge\MergedAwayStamp
 *
 * @spec openspec/specs/master-data-management/spec.md#requirement-req-mdm-013-mdm-steward-ui-deep-linked-to-openregister
 */
class MergedAwayStampLabelTest extends TestCase {

	/**
	 * The patches written, as [uuid, data, rbac, multitenancy].
	 *
	 * @var list<array{0: string, 1: array<string, mixed>, 2: bool, 3: bool}>
	 */
	private array $patches = [];

	/**
	 * A stamp over the given records and schema settings.
	 *
	 * @param array<string, array{0: string, 1: array<string, mixed>}> $records  Schema and data by uuid.
	 * @param array<string, string>                                    $settings App config values.
	 *
	 * @return MergedAwayStamp
	 */
	private function stamp(array $records, array $settings=['client_schema' => '10', 'contact_schema' => '20']): MergedAwayStamp {
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
			function (ObjectPatch $patch, bool $_rbac=true, bool $_multitenancy=true): ObjectEntity {
				$this->patches[] = [$patch->objectId, $patch->data, $_rbac, $_multitenancy];
				return new ObjectEntity();
			}
		);
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default='') use ($settings): string {
				self::assertSame(expected: 'pipelinq', actual: $app);
				return $settings[$key] ?? $default;
			}
		);

		return new MergedAwayStamp(objects: $objects, appConfig: $config);
	}//end stamp()

	/**
	 * A merge event.
	 *
	 * @param string $survivor The surviving uuid.
	 *
	 * @return ObjectsMergedEvent
	 */
	private static function merge(string $survivor): ObjectsMergedEvent {
		return new ObjectsMergedEvent(survivorUuid: $survivor, mergedFromUuids: ['away'], mergeOperationId: 'op-1');
	}//end merge()

	/**
	 * A contact is stamped as the system, with the survivor's trimmed name and no
	 * number brackets when it has none, at an ATOM moment of now.
	 *
	 * @return void
	 */
	public function testAContactIsStampedWithTheNameAlone(): void {
		$stamp = $this->stamp(records: ['away' => ['20', []], 'kept' => ['20', ['name' => '  Jan de Vries ', 'customerNumber' => '  ']]]);
		$before = new DateTimeImmutable('-2 seconds');

		$stamp->apply(uuid: 'away', event: self::merge(survivor: 'kept'));

		$this->assertCount(expectedCount: 1, haystack: $this->patches);
		[$uuid, $data, $rbac, $multitenancy] = $this->patches[0];
		$this->assertSame(expected: ['away', false, false], actual: [$uuid, $rbac, $multitenancy]);
		$this->assertSame(expected: ['mergedInto', 'mergedIntoLabel', 'mergedAt'], actual: array_keys($data));
		$this->assertSame(expected: 'kept', actual: $data['mergedInto']);
		$this->assertSame(expected: 'Jan de Vries', actual: $data['mergedIntoLabel']);
		$at = DateTimeImmutable::createFromFormat(DATE_ATOM, $data['mergedAt']);
		$this->assertInstanceOf(expected: DateTimeImmutable::class, actual: $at);
		$this->assertGreaterThanOrEqual(expected: $before->getTimestamp(), actual: $at->getTimestamp());
	}//end testAContactIsStampedWithTheNameAlone()

	/**
	 * A survivor without a name is labelled by its number alone; a survivor that is
	 * gone still gets its uuid on the stamp, with an empty label.
	 *
	 * @return void
	 */
	public function testTheLabelFallsBackToTheNumberOrNothing(): void {
		$stamp = $this->stamp(records: ['away' => ['10', []], 'kept' => ['10', ['customerNumber' => 'K-7']]]);

		$stamp->apply(uuid: 'away', event: self::merge(survivor: 'kept'));
		$stamp->apply(uuid: 'away', event: self::merge(survivor: 'gone'));

		$this->assertSame(expected: '(K-7)', actual: $this->patches[0][1]['mergedIntoLabel']);
		$this->assertSame(expected: 'gone', actual: $this->patches[1][1]['mergedInto']);
		$this->assertSame(expected: '', actual: $this->patches[1][1]['mergedIntoLabel']);
	}//end testTheLabelFallsBackToTheNumberOrNothing()

	/**
	 * With no schemas configured, a record without a schema is not a party and is not written.
	 *
	 * @return void
	 */
	public function testAnUnsetSchemaSettingMatchesNothing(): void {
		$stamp = $this->stamp(records: ['away' => ['', []], 'kept' => ['', ['name' => 'X']]], settings: []);

		$stamp->apply(uuid: 'away', event: self::merge(survivor: 'kept'));

		$this->assertSame(expected: [], actual: $this->patches);
	}//end testAnUnsetSchemaSettingMatchesNothing()
}//end class
