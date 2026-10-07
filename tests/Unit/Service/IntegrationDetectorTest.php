<?php

/**
 * Shillinq and XWiki are detected, never typed.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
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
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/pipelinq-setup-wizard-review/specs/first-time-setup/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\IntegrationDetector;
use OCP\App\IAppManager;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for IntegrationDetector.
 *
 * @covers \OCA\Pipelinq\Service\IntegrationDetector
 * @uses   \OCA\Pipelinq\Support\FleetAppId
 */
class IntegrationDetectorTest extends TestCase {
	/**
	 * Build the detector over a set of installed app ids.
	 *
	 * @param string[] $installed The installed app ids.
	 *
	 * @return IntegrationDetector
	 */
	private function detector(array $installed): IntegrationDetector {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturnCallback(
			static fn (string $id): bool => in_array($id, $installed, true)
		);
		$appManager->method('isEnabledForUser')->willReturnCallback(
			static fn (string $id): bool => in_array($id, $installed, true)
		);

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(
			static fn (string $path): string => 'https://crm.example.test' . $path
		);

		return new IntegrationDetector($appManager, $urls);
	}//end detector()

	/**
	 * An installed Shillinq opens on this server.
	 *
	 * @return void
	 */
	public function testAnInstalledShillinqLinksToThisServer(): void {
		$this->assertSame(
			['installed' => true, 'url' => 'https://crm.example.test/index.php/apps/shillinq/'],
			$this->detector(['shillinq'])->shillinq()
		);
	}//end testAnInstalledShillinqLinksToThisServer()

	/**
	 * Without Shillinq there is no link at all.
	 *
	 * @return void
	 */
	public function testNoShillinqMeansNoLink(): void {
		$this->assertSame(['installed' => false, 'url' => ''], $this->detector([])->shillinq());
	}//end testNoShillinqMeansNoLink()

	/**
	 * The XWiki app wins over the OpenRegister route.
	 *
	 * @return void
	 */
	public function testTheXwikiAppWins(): void {
		$this->assertSame('xwiki-app', $this->detector(['xwiki', 'openregister', 'integriq'])->xwiki()['source']);
	}//end testTheXwikiAppWins()

	/**
	 * OpenRegister plus integriq (or its old id) carries XWiki.
	 *
	 * @return void
	 */
	public function testOpenRegisterWithIntegriqCarriesXwiki(): void {
		$this->assertSame(['available' => true, 'source' => 'openregister'], $this->detector(['openregister', 'integriq'])->xwiki());
		$this->assertSame(['available' => true, 'source' => 'openregister'], $this->detector(['openregister', 'openconnector'])->xwiki());
	}//end testOpenRegisterWithIntegriqCarriesXwiki()

	/**
	 * OpenRegister alone has no XWiki source.
	 *
	 * @return void
	 */
	public function testOpenRegisterAloneHasNoXwiki(): void {
		$this->assertSame(['available' => false, 'source' => 'none'], $this->detector(['openregister'])->xwiki());
	}//end testOpenRegisterAloneHasNoXwiki()
}//end class
