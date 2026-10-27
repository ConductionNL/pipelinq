<?php

/**
 * The job title and department on the appointment resource schema.
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
 * @spec openspec/changes/screens-inloop-resource-board/specs/appointment-booking/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Settings;

use OCA\Pipelinq\Service\ConfigFileLoaderService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

/**
 * Reads the register as the import composes it and checks that the resource
 * carries an optional job title and department, under a new schema version.
 *
 * @covers \OCA\Pipelinq\Service\ConfigFileLoaderService
 */
class ResourceJobTitleSchemaTest extends TestCase {
	/**
	 * The resource schema carries both fields as optional strings, keeps its
	 * slug and earlier fields, and moves to 1.1.0 so the import re-reads it.
	 *
	 * @return void
	 */
	public function testResourceDeclaresJobTitleAndDepartment(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->willReturn(dirname(__DIR__, 3));

		$register = (new ConfigFileLoaderService($appManager))->loadConfigurationFile();
		$schema = ($register['components']['schemas']['appointmentResource'] ?? null);
		$this->assertIsArray($schema);

		$this->assertSame('appointmentResource', $schema['slug']);
		$this->assertSame('1.1.0', $schema['version']);
		$this->assertArrayHasKey('workingHours', $schema['properties']);
		foreach (['jobTitle', 'department'] as $field) {
			$this->assertSame('string', ($schema['properties'][$field]['type'] ?? null), $field);
			$this->assertNotContains($field, ($schema['required'] ?? []), $field);
		}
	}//end testResourceDeclaresJobTitleAndDepartment()
}//end class
