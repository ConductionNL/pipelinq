<?php

/**
 * The setup status reports exactly the steps the manifest declares.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
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

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\SetupController;
use OCA\Pipelinq\Service\Demo\DemoRegisterImporter;
use OCA\Pipelinq\Service\DemoSeedService;
use OCA\Pipelinq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A manifest step the status omits stays unmet forever and reopens the
 * wizard over every page. A step the status reports but the manifest
 * dropped is dead weight that hides the first mistake.
 *
 * @covers \OCA\Pipelinq\Controller\SetupController
 */
class SetupControllerStatusContractTest extends TestCase {
	/**
	 * The status step ids equal the manifest's setup step ids.
	 *
	 * @return void
	 */
	public function testStatusReportsExactlyTheManifestSteps(): void {
		$manifest = json_decode((string)file_get_contents(__DIR__ . '/../../../src/manifest.json'), true);
		$declared = array_column($manifest['setup']['steps'], 'id');

		$demoSeed = $this->createMock(DemoSeedService::class);
		$demoSeed->method('listChoices')->willReturn([]);

		$controller = new SetupController(
			'pipelinq',
			$this->createMock(IRequest::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(SettingsService::class),
			$demoSeed,
			$this->createMock(IAppManager::class),
			new NullLogger(),
			$this->createMock(DemoRegisterImporter::class)
		);

		$reported = array_keys($controller->status()->getData()['steps']);

		sort($declared);
		sort($reported);
		$this->assertSame($declared, $reported);
	}//end testStatusReportsExactlyTheManifestSteps()

	/**
	 * Provisioning and the integrations are no longer wizard steps.
	 *
	 * @return void
	 */
	public function testTheWizardNoLongerAsksForProvisioningOrBaseUrls(): void {
		$manifest = json_decode((string)file_get_contents(__DIR__ . '/../../../src/manifest.json'), true);
		$encoded = json_encode($manifest['setup']);

		$this->assertStringNotContainsString('provision-register', $encoded);
		$this->assertStringNotContainsString('shillinq_app_url', $encoded);
		$this->assertStringNotContainsString('xwiki_direct_url', $encoded);
	}//end testTheWizardNoLongerAsksForProvisioningOrBaseUrls()
}//end class
