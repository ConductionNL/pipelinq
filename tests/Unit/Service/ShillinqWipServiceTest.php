<?php

/**
 * Unit tests for ShillinqWipService.
 *
 * Asserts that the WIP hand-off follows the detected Shillinq app instead of
 * a typed webhook URL (pipelinq-audit-admin-forms-pos).
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\IntegrationDetector;
use OCA\Pipelinq\Service\ShillinqWipService;
use OCP\App\IAppManager;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for ShillinqWipService.
 */
class ShillinqWipServiceTest extends TestCase {
	/**
	 * The capturing webhook double (shared with the AP test).
	 *
	 * @var FakeApWebhookService
	 */
	private FakeApWebhookService $webhooks;

	/**
	 * Build a service on a server with or without the Shillinq app.
	 *
	 * @param bool $shillinqInstalled Whether the Shillinq app is installed.
	 *
	 * @return ShillinqWipService The service under test.
	 */
	private function makeService(bool $shillinqInstalled): ShillinqWipService {
		if (class_exists(FakeApWebhookService::class) === false) {
			require_once __DIR__ . '/ShillinqApServiceTest.php';
		}

		$this->webhooks = new FakeApWebhookService();

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturnCallback(
			fn (string $appId): bool => $appId === 'shillinq' && $shillinqInstalled === true
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) {
				if ($id === 'OCA\OpenRegister\Service\WebhookService') {
					return $this->webhooks;
				}

				throw new \RuntimeException('unknown service ' . $id);
			}
		);

		return new ShillinqWipService(
			integrations: new IntegrationDetector($appManager, $this->createMock(IURLGenerator::class)),
			container: $container,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end makeService()

	/**
	 * An installed Shillinq receives approved hours without any URL.
	 *
	 * @return void
	 */
	public function testDispatchesWhenShillinqIsInstalled(): void {
		$service = $this->makeService(true);

		$this->assertTrue($service->shouldDispatch());
		$this->assertTrue($service->dispatchWipEvent(['id' => 'te-1', 'hours' => 2], 'alice', '2026-10-07T10:00:00Z'));
		$this->assertCount(1, $this->webhooks->events);
		$this->assertSame(ShillinqWipService::EVENT_TIME_APPROVED, $this->webhooks->events[0]['eventName']);
	}//end testDispatchesWhenShillinqIsInstalled()

	/**
	 * Without Shillinq nothing is sent.
	 *
	 * @return void
	 */
	public function testNoDispatchWhenShillinqIsMissing(): void {
		$service = $this->makeService(false);

		$this->assertFalse($service->shouldDispatch());
		$this->assertFalse($service->dispatchWipEvent(['id' => 'te-1'], 'alice', '2026-10-07T10:00:00Z'));
		$this->assertCount(0, $this->webhooks->events);
	}//end testNoDispatchWhenShillinqIsMissing()
}//end class
