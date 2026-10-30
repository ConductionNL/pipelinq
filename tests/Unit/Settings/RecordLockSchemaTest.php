<?php

/**
 * The record lock switch on the client, lead and ticket schemas.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/platform-record-lock/specs/record-locking/spec.md#requirement-a-user-can-lock-a-client-a-finished-lead-or-a-finished-ticket-req-prl-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Settings;

use OCA\Pipelinq\Service\ConfigFileLoaderService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

/**
 * Reads the register as the import composes it (the monolith with every
 * `register.d` fragment merged onto it).
 *
 * @covers \OCA\Pipelinq\Service\ConfigFileLoaderService
 */
class RecordLockSchemaTest extends TestCase {
	/**
	 * A schema as the import composes it.
	 *
	 * @param string $key The schema key.
	 *
	 * @return array<string, mixed> The schema.
	 */
	private function schema(string $key): array {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->willReturn(dirname(__DIR__, 3));

		$register = (new ConfigFileLoaderService($appManager))->loadConfigurationFile();
		$schema = ($register['components']['schemas'][$key] ?? null);
		$this->assertIsArray($schema, "The {$key} schema was not read.");

		return $schema;
	}//end schema()

	/**
	 * Client, lead and ticket switch on OpenRegister's freeze.
	 *
	 * @return void
	 */
	public function testClientLeadAndTicketCanBeFrozen(): void {
		foreach (['client', 'lead', 'ticket'] as $key) {
			$schema = $this->schema(key: $key);
			$this->assertSame(
				['enabled' => true],
				($schema['configuration']['x-openregister-archive'] ?? null),
				"The {$key} schema must declare x-openregister-archive in its configuration."
			);
		}
	}//end testClientLeadAndTicketCanBeFrozen()

	/**
	 * The isFinished calculation on lead and ticket names exactly the
	 * lifecycle's final states, compared on the status field.
	 *
	 * @return void
	 */
	public function testIsFinishedNamesExactlyTheFinalStates(): void {
		foreach (['lead', 'ticket'] as $key) {
			$configuration = ($this->schema(key: $key)['configuration'] ?? []);
			$lifecycle = ($configuration['x-openregister-lifecycle'] ?? []);
			$calculation = ($configuration['x-openregister-calculations']['isFinished'] ?? null);

			$this->assertIsArray($calculation, "The {$key} schema must declare isFinished.");
			$this->assertSame('boolean', $calculation['type']);
			$this->assertFalse($calculation['materialise']);

			$terms = ($calculation['expression']['or'] ?? []);
			$this->assertNotEmpty($terms, "{$key}.isFinished must be an or of eq tests.");

			$states = [];
			foreach ($terms as $term) {
				$this->assertSame(['prop' => $lifecycle['field']], ($term['eq'][0] ?? null), "{$key}.isFinished must compare the lifecycle field.");
				$states[] = $term['eq'][1];
			}

			$final = $lifecycle['final'];
			sort($states);
			sort($final);
			$this->assertSame($final, $states, "{$key}.isFinished must name exactly the lifecycle's final states.");
		}
	}//end testIsFinishedNamesExactlyTheFinalStates()

	/**
	 * A client has no finished state, so it gets no isFinished.
	 *
	 * @return void
	 */
	public function testClientHasNoIsFinished(): void {
		$configuration = ($this->schema(key: 'client')['configuration'] ?? []);
		$this->assertArrayNotHasKey('isFinished', ($configuration['x-openregister-calculations'] ?? []));
	}//end testClientHasNoIsFinished()
}//end class
