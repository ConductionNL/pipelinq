<?php

/**
 * Unit tests for PortalRequestGuard's widget-origin enforcement.
 *
 * Covers the cross-origin embedding boundary: in widget mode (tenant asserted
 * via the X-Portal-Tenant header) a request whose Origin is not in the
 * tenant's widget-origin allow-list MUST be rejected with a 403 at the single
 * tenant-resolution gate, while first-party (host/subdomain) requests are
 * unaffected.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Portal;

use OCA\Pipelinq\Service\Portal\PortalException;
use OCA\Pipelinq\Service\Portal\PortalObjectRepository;
use OCA\Pipelinq\Service\Portal\PortalRequestGuard;
use OCA\Pipelinq\Service\Portal\PortalSessionManager;
use OCA\Pipelinq\Service\Portal\PortalTenantService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Tests for widget-origin enforcement in tenant resolution.
 */
class PortalRequestGuardTest extends TestCase
{
    /**
     * The tenant service double.
     *
     * @var PortalTenantService
     */
    private $tenant;

    /**
     * The guard under test.
     *
     * @var PortalRequestGuard
     */
    private PortalRequestGuard $guard;


    /**
     * Build a guard with mocked collaborators; only the tenant service is
     * exercised for these tenant-resolution tests.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $repository   = $this->createMock(PortalObjectRepository::class);
        $sessions     = $this->createMock(PortalSessionManager::class);
        $this->tenant = $this->createMock(PortalTenantService::class);
        $this->guard  = new PortalRequestGuard($repository, $sessions, $this->tenant);

    }//end setUp()


    /**
     * Build an IRequest double returning the given header map + host.
     *
     * @param array<string, string> $headers The header values keyed by name.
     * @param string                $host    The server host.
     *
     * @return IRequest The request double.
     */
    private function request(array $headers, string $host='portal.example'): IRequest
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getServerHost')->willReturn($host);
        $request->method('getHeader')->willReturnCallback(
            static fn (string $name): string => ($headers[$name] ?? '')
        );
        return $request;

    }//end request()


    /**
     * Widget mode + a disallowed Origin is rejected with a 403 at the gate —
     * an embedded widget on a site the tenant never allow-listed cannot drive
     * any portal action.
     *
     * @return void
     */
    public function testWidgetModeRejectsDisallowedOrigin(): void
    {
        $this->tenant->method('resolveTenantId')->willReturn('tenant-a');
        $this->tenant->expects($this->once())
            ->method('isWidgetOriginAllowed')
            ->with('tenant-a', 'https://evil.example')
            ->willReturn(false);

        $request = $this->request(
            [
                'X-Portal-Tenant' => 'tenant-a',
                'Origin'          => 'https://evil.example',
            ]
        );

        try {
            $this->guard->resolveTenant($request);
            $this->fail('Expected PortalException for disallowed widget origin');
        } catch (PortalException $e) {
            $this->assertSame(Http::STATUS_FORBIDDEN, $e->getStatus());
        }

    }//end testWidgetModeRejectsDisallowedOrigin()


    /**
     * Widget mode + an allow-listed Origin resolves normally.
     *
     * @return void
     */
    public function testWidgetModeAllowsAllowlistedOrigin(): void
    {
        $this->tenant->method('resolveTenantId')->willReturn('tenant-a');
        $this->tenant->method('isWidgetOriginAllowed')
            ->with('tenant-a', 'https://partner.example')
            ->willReturn(true);

        $request = $this->request(
            [
                'X-Portal-Tenant' => 'tenant-a',
                'Origin'          => 'https://partner.example',
            ]
        );

        $this->assertSame('tenant-a', $this->guard->resolveTenant($request));

    }//end testWidgetModeAllowsAllowlistedOrigin()


    /**
     * First-party mode (no X-Portal-Tenant header) never invokes the
     * widget-origin gate and resolves from host signals unchanged — the
     * enforcement is fail-closed for the widget path only.
     *
     * @return void
     */
    public function testFirstPartyModeSkipsOriginCheck(): void
    {
        $this->tenant->method('resolveTenantId')->willReturn('default');
        $this->tenant->expects($this->never())->method('isWidgetOriginAllowed');

        $request = $this->request([], host: 'tenant-a.portal.example');

        $this->assertSame('default', $this->guard->resolveTenant($request));

    }//end testFirstPartyModeSkipsOriginCheck()
}//end class
