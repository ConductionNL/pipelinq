<?php

/**
 * Unit tests: the ticket carries the note of its latest handover.
 *
 * Reads the merged register (monolith plus every fragment in register.d, in
 * the loader's own order), which is what OpenRegister imports.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Settings
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ticket-handover-history/specs/ticket-handover-history/spec.md#REQ-THH-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Settings;

use OCA\Pipelinq\Service\ConfigFileLoaderService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Pipelinq\Service\ConfigFileLoaderService
 * @uses \OCA\Pipelinq\Service\ConfigFragmentValue
 */
class TicketHandoverNoteSchemaTest extends TestCase {

	/**
	 * The merged ticket schema.
	 *
	 * @return array<string, mixed>
	 */
	private function ticket(): array {
		$appManager = $this->createMock(originalClassName: IAppManager::class);
		$appManager->method('getAppPath')->willReturn(dirname(__DIR__, 3));
		$config = (new ConfigFileLoaderService(appManager: $appManager))->loadConfigurationFile();
		return $config['components']['schemas']['ticket'];
	}//end ticket()

	/**
	 * The note is a bounded, hidden string, and the schema version moved on.
	 *
	 * @return void
	 */
	public function testTheTicketCarriesAHiddenHandoverNote(): void {
		$ticket = $this->ticket();
		$note = $ticket['properties']['handoverNote'];

		$this->assertSame(expected: 'string', actual: $note['type']);
		$this->assertSame(expected: 1000, actual: $note['maxLength']);
		$this->assertFalse(condition: $note['visible']);
		$this->assertNotSame(expected: '', actual: $note['title']);
		$this->assertNotSame(expected: '', actual: $note['description']);
		$this->assertTrue(condition: version_compare($ticket['version'], '1.10.0', '>='));
		$this->assertSame(expected: 'user', actual: $ticket['properties']['assignee']['format']);
	}//end testTheTicketCarriesAHiddenHandoverNote()
}//end class
