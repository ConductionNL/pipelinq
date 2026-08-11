<?php

/**
 * Contract tests for PortalPageController.
 *
 * The public SPA shell for the customer portal. These tests pin the wire
 * contract of the deep-link catch-all: HTTP 200, the public render mode (no
 * Nextcloud chrome and no authenticated user context), the portal template, an
 * empty parameter bag (no server data may be handed to an anonymous page), and
 * the frame-ancestors policy that makes widget embedding possible.
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

use OCA\Pipelinq\Controller\PortalPageController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PortalPageController.
 */
class PortalPageControllerTest extends TestCase
{

    /**
     * The controller under test.
     *
     * @var PortalPageController
     */
    private PortalPageController $controller;

    /**
     * Wire the controller to a mocked request.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->controller = new PortalPageController($this->createMock(IRequest::class));
    }//end setUp()

    /**
     * A deep link answers 200 with the portal template rendered publicly.
     *
     * @return void
     */
    public function testSubpathServesThePortalShellWithOkStatus(): void
    {
        $response = $this->controller->subpath('invoices/42');

        $this->assertInstanceOf(TemplateResponse::class, $response);
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('pipelinq', $response->getApp());
        $this->assertSame('portal', $response->getTemplateName());
        $this->assertSame(TemplateResponse::RENDER_AS_PUBLIC, $response->getRenderAs());
    }//end testSubpathServesThePortalShellWithOkStatus()

    /**
     * The shell must hand no server-side data to an anonymous visitor: the
     * template parameter bag stays empty whatever path was requested.
     *
     * @return void
     */
    public function testSubpathPassesNoServerDataToTheAnonymousShell(): void
    {
        $response = $this->controller->subpath('accounts/profile');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame([], $response->getParams());
    }//end testSubpathPassesNoServerDataToTheAnonymousShell()

    /**
     * The sub-path is a routing placeholder only: every deep link, including the
     * empty default, resolves to a byte-identical shell so the hash-routed SPA
     * can take over client-side.
     *
     * @return void
     */
    public function testSubpathIsIdenticalForEveryDeepLink(): void
    {
        $deep    = $this->controller->subpath('requests/abc-123');
        $default = $this->controller->subpath();

        $this->assertSame($default->getStatus(), $deep->getStatus());
        $this->assertSame($default->getTemplateName(), $deep->getTemplateName());
        $this->assertSame($default->getRenderAs(), $deep->getRenderAs());
        $this->assertSame($default->getParams(), $deep->getParams());
    }//end testSubpathIsIdenticalForEveryDeepLink()

    /**
     * A path segment supplied by the client must never influence the template
     * that gets rendered — a traversal-shaped path still yields the shell.
     *
     * @return void
     */
    public function testSubpathIgnoresTraversalShapedInput(): void
    {
        $response = $this->controller->subpath('../../settings/admin');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('portal', $response->getTemplateName());
        $this->assertSame([], $response->getParams());
    }//end testSubpathIgnoresTraversalShapedInput()

    /**
     * The shell relaxes frame-ancestors so a tenant can embed the portal as a
     * widget; per-request origin enforcement lives in the tenant guard.
     *
     * @return void
     */
    public function testSubpathAllowsFrameAncestorsForWidgetEmbedding(): void
    {
        $policy = $this->controller->subpath('login')->getContentSecurityPolicy()->buildPolicy();

        $this->assertStringContainsString('frame-ancestors ', $policy);
        $this->assertMatchesRegularExpression('/frame-ancestors [^;]*\*/', $policy);
    }//end testSubpathAllowsFrameAncestorsForWidgetEmbedding()
}//end class
