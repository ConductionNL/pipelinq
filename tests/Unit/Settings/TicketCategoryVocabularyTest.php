<?php

/**
 * The ticket category vocabulary schema.
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
 * @spec openspec/specs/unify-ticket-supertype/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Settings;

use OCA\Pipelinq\Service\ConfigFileLoaderService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

/**
 * Reads the register as the import composes it: the pipelinq register lists
 * ticketCategory, which names a category code with key and name, and the
 * ticket's own category stays a plain string code.
 *
 * @covers \OCA\Pipelinq\Service\ConfigFileLoaderService
 */
class TicketCategoryVocabularyTest extends TestCase {
	/**
	 * The vocabulary is declared and registered; the ticket is unchanged.
	 *
	 * @return void
	 */
	public function testTheRegisterCarriesATicketCategoryVocabulary(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->willReturn(dirname(__DIR__, 3));

		$register = (new ConfigFileLoaderService($appManager))->loadConfigurationFile();
		$schema = ($register['components']['schemas']['ticketCategory'] ?? null);
		$this->assertIsArray($schema);
		$this->assertSame(['key', 'name'], $schema['required']);
		$this->assertSame('string', $schema['properties']['key']['type']);
		$this->assertContains('ticketCategory', $register['components']['registers']['pipelinq']['schemas']);
		$this->assertSame('string', $register['components']['schemas']['ticket']['properties']['category']['type']);
	}//end testTheRegisterCarriesATicketCategoryVocabulary()
}//end class
