<?php

/**
 * A change in the Zuiddrecht set to an object the same set writes names that
 * object by its key (`object`), never by a `where`. OpenRegister's check finds
 * a `where` only among the rows already on the instance, so on a fresh
 * instance such a change found nothing and refused the whole set.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/example-data/spec.md#requirement-every-example-record-imports
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Changes in the Zuiddrecht set reach the set's own objects by key.
 */
class ZuiddrechtChangesNameTheirObjectTest extends TestCase {

	/**
	 * The decoded main file and its parts, by file name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function fragments(): array {
		$dir   = __DIR__.'/../../../lib/Settings/example-sets/';
		$files = array_merge([$dir.'zuiddrecht.json'], glob($dir.'zuiddrecht.d/*.json'));
		$found = [];
		foreach ($files as $file) {
			$found[basename($file)] = json_decode((string) file_get_contents($file), true);
		}

		return $found;
	}//end fragments()

	/**
	 * The set's own objects by key.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function objects(): array {
		$objects = [];
		foreach ($this->fragments() as $fragment) {
			foreach (($fragment['objects'] ?? []) as $object) {
				$objects[$object['key']] = $object;
			}
		}

		return $objects;
	}//end objects()

	/**
	 * Whether an object of the set answers a change's `where`: same schema and
	 * every `where` field equal to the object's data, or the `where` is an id
	 * placeholder of that object.
	 *
	 * @param array<string, mixed> $object The set's object.
	 * @param array<string, mixed> $change The change.
	 *
	 * @return bool
	 */
	private function answers(array $object, array $change): bool {
		if (($object['schema'] ?? '') !== ($change['schema'] ?? '')) {
			return false;
		}

		foreach ((array) $change['where'] as $field => $value) {
			if ($field === 'id' && $value === '{{object:'.$object['key'].'.id}}') {
				continue;
			}

			if (($object['data'][$field] ?? null) !== $value) {
				return false;
			}
		}

		return true;
	}//end answers()

	/**
	 * No change looks up, with a `where`, an object the set writes itself.
	 *
	 * @return void
	 */
	public function testNoChangeLooksUpAnObjectOfTheSetWithAWhere(): void {
		$objects = $this->objects();
		$found   = [];
		foreach ($this->fragments() as $file => $fragment) {
			foreach (($fragment['changes'] ?? []) as $change) {
				if (isset($change['where']) === false) {
					continue;
				}

				foreach ($objects as $key => $object) {
					if ($this->answers(object: $object, change: $change) === true) {
						$found[] = $file.' '.$change['key'].' -> '.$key;
					}
				}
			}
		}

		$this->assertSame(expected: [], actual: $found);
	}//end testNoChangeLooksUpAnObjectOfTheSetWithAWhere()

	/**
	 * Every change that names an object names one the set declares, of the
	 * change's schema.
	 *
	 * @return void
	 */
	public function testEveryNamedObjectIsDeclaredWithTheChangesSchema(): void {
		$objects = $this->objects();
		$named   = 0;
		foreach ($this->fragments() as $file => $fragment) {
			foreach (($fragment['changes'] ?? []) as $change) {
				if (isset($change['object']) === false) {
					continue;
				}

				$named++;
				$this->assertArrayHasKey(key: $change['object'], array: $objects, message: $file.' '.$change['key']);
				$this->assertSame(
					expected: $change['schema'],
					actual: $objects[$change['object']]['schema'],
					message: $file.' '.$change['key']
				);
				$this->assertArrayNotHasKey(key: 'where', array: $change, message: $file.' '.$change['key']);
			}
		}

		$this->assertGreaterThanOrEqual(expected: 18, actual: $named);
	}//end testEveryNamedObjectIsDeclaredWithTheChangesSchema()

	/**
	 * The ticket that links the dossiq case is optional, so the set installs
	 * where dossiq is not enabled and leaves only that ticket out.
	 *
	 * @return void
	 */
	public function testTheTicketThatLinksADossiqCaseIsOptional(): void {
		$ticket = $this->objects()['ticket-sanne-fietspad-lindelaan'];
		$this->assertSame(expected: 'dossiq', actual: $ticket['lookups']['case']['register']);
		$this->assertTrue(condition: $ticket['optional'] ?? false);
	}//end testTheTicketThatLinksADossiqCaseIsOptional()
}//end class
