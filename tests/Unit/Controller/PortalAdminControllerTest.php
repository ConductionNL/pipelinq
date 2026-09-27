<?php

/**
 * Contract tests for PortalAdminController::getConfig.
 *
 * The admin screen reads the full tenant config before it saves, because
 * saveConfig() replaces the whole record (pipelinq#2041). These tests run the
 * real PortalTenantService over the in-memory portal repository, so the stored
 * shape (with its `@self` envelope) is the one the screen receives.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\PortalAdminController;
use OCA\Pipelinq\Service\Portal\PortalAuditService;
use OCA\Pipelinq\Service\Portal\PortalTenantService;
use OCA\Pipelinq\Tests\Unit\Service\Portal\FakePortalObjectRepository;
use OCP\IAppConfig;
use OCA\Pipelinq\Tests\Unit\Service\Portal\InstalledAppConfig;
use OCA\Pipelinq\Util\ContrastRatioCalculator;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for PortalAdminController::getConfig.
 */
class PortalAdminControllerTest extends TestCase {

	/**
	 * The in-memory portal repository.
	 *
	 * @var FakePortalObjectRepository
	 */
	private FakePortalObjectRepository $repository;

	/**
	 * Build a controller for a user who is (or is not) a Nextcloud admin.
	 *
	 * @param bool $isAdmin Whether the current user is an admin.
	 *
	 * @return PortalAdminController The controller.
	 */
	private function controller(bool $isAdmin): PortalAdminController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => $default
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($isAdmin);

		return new PortalAdminController(
			$request,
			new PortalTenantService($this->repository, new ContrastRatioCalculator()),
			$this->createMock(PortalAuditService::class),
			$this->repository,
			$session,
			$groups,
			$this->createMock(LoggerInterface::class)
		);
	}//end controller()

	/**
	 * Set up the in-memory repository.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->repository = new FakePortalObjectRepository(InstalledAppConfig::wire($this->createMock(IAppConfig::class)));
	}//end setUp()

	/**
	 * A stored tenant config comes back whole, admin-only fields included.
	 *
	 * @return void
	 */
	public function testGetConfigReturnsTheStoredRecord(): void {
		$this->repository->seed(
			'portalTenantConfig',
			'cfg-1',
			[
				'tenantId' => 'default',
				'displayName' => 'Gemeente Voorbeeld',
				'enabledFeatures' => ['requests'],
				'customDomain' => 'portaal.voorbeeld.nl',
				'mfaEnforced' => true,
			]
		);

		$response = $this->controller(isAdmin: true)->getConfig();
		$body = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($body['configured']);
		$this->assertSame(['requests'], $body['config']['enabledFeatures']);
		$this->assertSame('portaal.voorbeeld.nl', $body['config']['customDomain']);
		$this->assertTrue($body['config']['mfaEnforced']);
	}//end testGetConfigReturnsTheStoredRecord()

	/**
	 * With no record the admin sees the defaults the portal applies, flagged as unsaved.
	 *
	 * @return void
	 */
	public function testGetConfigReturnsDefaultsWhenNothingIsStored(): void {
		$response = $this->controller(isAdmin: true)->getConfig();
		$body = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertFalse($body['configured']);
		$this->assertSame('default', $body['config']['tenantId']);
		$this->assertContains('requests', $body['config']['enabledFeatures']);
	}//end testGetConfigReturnsDefaultsWhenNothingIsStored()

	/**
	 * A non-admin is refused, whatever the route middleware did.
	 *
	 * @return void
	 */
	public function testGetConfigRefusesANonAdmin(): void {
		$this->repository->seed('portalTenantConfig', 'cfg-1', ['tenantId' => 'default', 'mfaEnforced' => true]);

		$response = $this->controller(isAdmin: false)->getConfig();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertArrayNotHasKey('config', $response->getData());
	}//end testGetConfigRefusesANonAdmin()
}//end class
