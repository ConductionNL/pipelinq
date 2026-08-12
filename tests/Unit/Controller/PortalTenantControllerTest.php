<?php

/**
 * Contract tests for PortalTenantController.
 *
 * The single pre-login public endpoint of the customer portal. These tests pin
 * the wire contract: the resolved tenant's client-safe branding at HTTP 200, and
 * the widget-origin refusal at HTTP 403 with a stable errorCode. Collaborators
 * (guard, tenant service) are mocked; no live Nextcloud server is required.
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

use OCA\Pipelinq\Controller\PortalTenantController;
use OCA\Pipelinq\Service\Portal\PortalException;
use OCA\Pipelinq\Service\Portal\PortalRequestGuard;
use OCA\Pipelinq\Service\Portal\PortalTenantService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for PortalTenantController.
 */
class PortalTenantControllerTest extends TestCase {

	/**
	 * The request mock.
	 *
	 * @var IRequest&MockObject
	 */
	private $request;

	/**
	 * The guard mock.
	 *
	 * @var PortalRequestGuard&MockObject
	 */
	private $guard;

	/**
	 * The tenant service mock.
	 *
	 * @var PortalTenantService&MockObject
	 */
	private $tenant;

	/**
	 * The controller under test.
	 *
	 * @var PortalTenantController
	 */
	private PortalTenantController $controller;

	/**
	 * Wire the controller to mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->guard = $this->createMock(PortalRequestGuard::class);
		$this->tenant = $this->createMock(PortalTenantService::class);

		$this->controller = new PortalTenantController(
			$this->request,
			$this->guard,
			$this->createMock(LoggerInterface::class),
			$this->tenant
		);
	}//end setUp()

	/**
	 * The happy path answers 200 with the tenant's client-safe branding.
	 *
	 * @return void
	 */
	public function testConfigReturnsPublicBrandingWithOkStatus(): void {
		$this->guard->method('resolveTenant')->willReturn('tenant-a');
		$this->tenant->method('getPublicConfig')->with(tenantId: 'tenant-a')->willReturn(
			[
				'tenantId' => 'tenant-a',
				'displayName' => 'Acme Support',
				'primaryColor' => '#0b5fff',
				'enabledFeatures' => ['invoices', 'orders'],
			]
		);

		$response = $this->controller->config();
		$body = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('tenant-a', $body['tenantId']);
		$this->assertSame('Acme Support', $body['displayName']);
		$this->assertSame(['invoices', 'orders'], $body['enabledFeatures']);
	}//end testConfigReturnsPublicBrandingWithOkStatus()

	/**
	 * The controller must not enrich the service's public projection: whatever
	 * the tenant service declares public is exactly what reaches the wire, so a
	 * secret cannot leak by being added at the controller layer.
	 *
	 * @return void
	 */
	public function testConfigBodyIsExactlyTheServiceProjection(): void {
		$this->guard->method('resolveTenant')->willReturn('tenant-a');
		$projection = ['tenantId' => 'tenant-a', 'displayName' => 'Acme Support'];
		$this->tenant->method('getPublicConfig')->willReturn($projection);

		$response = $this->controller->config();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($projection, $response->getData());
		$this->assertArrayNotHasKey('widgetAllowedOrigins', $response->getData());
		$this->assertArrayNotHasKey('mfaEnforced', $response->getData());
	}//end testConfigBodyIsExactlyTheServiceProjection()

	/**
	 * A widget-mode request from a non-allow-listed Origin is refused 403 with
	 * the stable machine errorCode, not a stack trace.
	 *
	 * @return void
	 */
	public function testConfigReturnsForbiddenWhenWidgetOriginIsNotAllowed(): void {
		$this->guard->method('resolveTenant')->willThrowException(
			new PortalException(
				Http::STATUS_FORBIDDEN,
				'originNotAllowed',
				'Deze widget mag niet vanaf deze locatie worden gebruikt.'
			)
		);

		$response = $this->controller->config();
		$body = $response->getData();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('originNotAllowed', $body['errorCode']);
		$this->assertArrayHasKey('message', $body);
		$this->assertStringNotContainsString('#0 ', (string)$body['message']);
	}//end testConfigReturnsForbiddenWhenWidgetOriginIsNotAllowed()

	/**
	 * An unexpected collaborator fault becomes an opaque 500 serverError — the
	 * internal message never reaches this unauthenticated surface.
	 *
	 * @return void
	 */
	public function testConfigMapsUnexpectedFaultToOpaqueServerError(): void {
		$this->guard->method('resolveTenant')->willReturn('tenant-a');
		$this->tenant->method('getPublicConfig')->willThrowException(
			new \RuntimeException('SQLSTATE[42P01]: undefined table oc_pipelinq_secret')
		);

		$response = $this->controller->config();
		$body = $response->getData();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame('serverError', $body['errorCode']);
		$this->assertStringNotContainsString('SQLSTATE', (string)$body['message']);
		$this->assertStringNotContainsString('oc_pipelinq_secret', (string)$body['message']);
	}//end testConfigMapsUnexpectedFaultToOpaqueServerError()
}//end class
