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
use OCA\Pipelinq\Service\Portal\PortalServiceAccount;
use OCA\Pipelinq\Service\Portal\PortalTenantService;
use OCA\Pipelinq\Tests\Unit\Service\Portal\FakePortalObjectRepository;
use OCP\IAppConfig;
use OCA\Pipelinq\Tests\Unit\Service\Portal\InstalledAppConfig;
use OCA\Pipelinq\Util\ContrastRatioCalculator;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
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
	 * The service account the controller picks with.
	 *
	 * @var PortalServiceAccount&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $serviceAccount;

	/**
	 * Request parameters the mocked IRequest serves.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

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
			fn (string $key, mixed $default = null): mixed => ($this->params[$key] ?? $default)
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
			new PortalAuditService(
				repository: $this->repository,
				time: $this->createMock(originalClassName: ITimeFactory::class),
				logger: $this->createMock(originalClassName: LoggerInterface::class)
			),
			$this->repository,
			$session,
			$groups,
			$this->createMock(LoggerInterface::class),
			$this->serviceAccount
		);
	}//end controller()

	/**
	 * Set up the in-memory repository.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->repository = new FakePortalObjectRepository(InstalledAppConfig::wire($this->createMock(IAppConfig::class)));
		$this->serviceAccount = $this->createMock(PortalServiceAccount::class);
		$this->params = [];
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

	/**
	 * A non-admin can neither read nor pick the portal service account.
	 *
	 * @return void
	 */
	public function testServiceAccountEndpointsRefuseANonAdmin(): void {
		$this->serviceAccount->expects($this->never())->method('assign');
		$this->serviceAccount->expects($this->never())->method('status');
		$this->params = ['userId' => 'mallory'];

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller(isAdmin: false)->getServiceAccount()->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller(isAdmin: false)->saveServiceAccount()->getStatus());
	}//end testServiceAccountEndpointsRefuseANonAdmin()

	/**
	 * An admin picks the account; the answer names its group.
	 *
	 * @return void
	 */
	public function testAnAdminPicksTheServiceAccount(): void {
		$this->serviceAccount->expects($this->once())->method('assign')->with(userId: 'portal-service')
			->willReturn(['userId' => 'portal-service', 'usable' => true, 'reason' => null]);
		$this->params = ['userId' => 'portal-service'];

		$response = $this->controller(isAdmin: true)->saveServiceAccount();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['userId' => 'portal-service', 'usable' => true, 'reason' => null, 'group' => PortalServiceAccount::GROUP],
			$response->getData()
		);
	}//end testAnAdminPicksTheServiceAccount()

	/**
	 * An account that cannot be used is refused with 400 and its reason.
	 *
	 * @return void
	 */
	public function testAnUnusableServiceAccountIsRefused(): void {
		$this->serviceAccount->method('assign')->willThrowException(new \InvalidArgumentException('No account "ghost" exists.'));
		$this->params = ['userId' => 'ghost'];

		$response = $this->controller(isAdmin: true)->saveServiceAccount();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('No account "ghost" exists.', $response->getData()['message']);
	}//end testAnUnusableServiceAccountIsRefused()

	/**
	 * Every stored portal is listed, sorted by name; a record without a
	 * status reads as live.
	 *
	 * @return void
	 */
	public function testTenantsListsEveryStoredPortal(): void {
		$this->repository->seed(
			'portalTenantConfig',
			'cfg-b',
			['tenantId' => 'west', 'displayName' => 'Mijn West', 'customDomain' => 'mijn.west.example', 'status' => 'draft']
		);
		$this->repository->seed('portalTenantConfig', 'cfg-a', ['tenantId' => 'oost', 'displayName' => 'Mijn Oost', 'enabledFeatures' => ['requests']]);

		$response = $this->controller(isAdmin: true)->tenants();
		$tenants = $response->getData()['tenants'];

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: ['oost', 'west'], actual: array_column($tenants, 'tenantId'));
		$this->assertSame(expected: 'live', actual: $tenants[0]['status']);
		$this->assertSame(expected: 'draft', actual: $tenants[1]['status']);
		$this->assertSame(expected: 'mijn.west.example', actual: $tenants[1]['customDomain']);
	}//end testTenantsListsEveryStoredPortal()

	/**
	 * A non-admin gets 403 and no list.
	 *
	 * @return void
	 */
	public function testTenantsRefusesANonAdmin(): void {
		$this->repository->seed('portalTenantConfig', 'cfg-a', ['tenantId' => 'oost', 'displayName' => 'Mijn Oost']);

		$response = $this->controller(isAdmin: false)->tenants();

		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());
		$this->assertArrayNotHasKey(key: 'tenants', array: (array)$response->getData());
	}//end testTenantsRefusesANonAdmin()

	/**
	 * With all=1 the latest events of every tenant come back newest first,
	 * capped at the limit, with the total count.
	 *
	 * @return void
	 */
	public function testAuditEventsOfAllTenantsComeNewestFirstWithTheTotal(): void {
		$this->repository->seed('portalAuditEvent', 'e1', ['tenantId' => 'oost', 'eventType' => 'login', 'occurredAt' => '2026-10-28T09:40:00Z']);
		$this->repository->seed('portalAuditEvent', 'e2', ['tenantId' => 'west', 'eventType' => 'invoice-download', 'occurredAt' => '2026-10-28T09:58:00']);
		$this->repository->seed(
			'portalAuditEvent',
			'e3',
			['tenantId' => 'oost', 'eventType' => 'question-answered', 'occurredAt' => '2026-10-28T10:14:00']
		);
		$this->params = ['all' => '1', 'limit' => '2'];

		$body = $this->controller(isAdmin: true)->auditEvents()->getData();

		$this->assertSame(expected: ['question-answered', 'invoice-download'], actual: array_column($body['events'], 'eventType'));
		$this->assertSame(expected: 3, actual: $body['total']);
	}//end testAuditEventsOfAllTenantsComeNewestFirstWithTheTotal()

	/**
	 * Without all=1 the endpoint keeps answering one tenant.
	 *
	 * @return void
	 */
	public function testAuditEventsOfOneTenantStayScoped(): void {
		$this->repository->seed('portalAuditEvent', 'e1', ['tenantId' => 'oost', 'eventType' => 'login', 'occurredAt' => '2026-10-28T09:40:00Z']);
		$this->repository->seed('portalAuditEvent', 'e2', ['tenantId' => 'west', 'eventType' => 'login', 'occurredAt' => '2026-10-28T09:58:00Z']);
		$this->params = ['tenantId' => 'west'];

		$body = $this->controller(isAdmin: true)->auditEvents()->getData();

		$this->assertSame(expected: ['west'], actual: array_column($body['events'], 'tenantId'));
	}//end testAuditEventsOfOneTenantStayScoped()
}//end class
