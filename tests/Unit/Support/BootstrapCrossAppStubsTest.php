<?php

/**
 * Every PHPUnit bootstrap loads the same cross-app stubs.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://pipelinq.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * A test sees the Integriq events and the OpenRegister unsubscribe helper whichever phpunit config runs it.
 *
 * @coversNothing
 */
class BootstrapCrossAppStubsTest extends TestCase {

	/**
	 * The classes pipelinq reaches by string name are declared in this run.
	 *
	 * @return void
	 */
	public function testCrossAppClassesAreDeclared(): void {
		foreach ([
			'OCA\\Integriq\\Event\\ConnectionStatusReportedEvent',
			'OCA\\Integriq\\Event\\OutboundSendDecisionRequestedEvent',
			'OCA\\Integriq\\Event\\OptOutChangeRequestedEvent',
			'OCA\\OpenRegister\\Notifications\\Service\\Notification\\UnsubscribeHeaders',
		] as $class) {
			$this->assertTrue(condition: class_exists(class: $class), message: $class . ' is not declared');
		}
	}//end testCrossAppClassesAreDeclared()

	/**
	 * Each bootstrap that a phpunit config names requires the shared stub file.
	 *
	 * @return void
	 */
	public function testEveryConfiguredBootstrapRequiresTheSharedStubs(): void {
		$root = dirname(path: __DIR__, levels: 3);
		$configs = glob(pattern: $root . '/phpunit*.xml');
		$this->assertNotEmpty(actual: $configs);

		foreach ($configs as $config) {
			$this->assertSame(
				expected: 1,
				actual: preg_match(pattern: '/bootstrap="([^"]+)"/', subject: (string) file_get_contents(filename: $config), matches: $match),
				message: basename(path: $config) . ' names no bootstrap'
			);
			$this->assertStringContainsString(
				needle: "require_once __DIR__ . '/bootstrap-cross-app-stubs.php';",
				haystack: (string) file_get_contents(filename: $root . '/' . $match[1]),
				message: $match[1] . ' does not load the shared cross-app stubs'
			);
		}
	}//end testEveryConfiguredBootstrapRequiresTheSharedStubs()
}//end class
