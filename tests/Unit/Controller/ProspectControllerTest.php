<?php

/**
 * Unit tests for ProspectController.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\ProspectController;
use OCA\Pipelinq\Service\ProspectDiscoveryService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ProspectController.
 */
class ProspectControllerTest extends TestCase
{
    /**
     * The controller under test.
     *
     * @var ProspectController
     */
    private ProspectController $controller;

    /**
     * Mock discovery service.
     *
     * @var ProspectDiscoveryService
     */
    private ProspectDiscoveryService $discoveryService;

    /**
     * Mock request.
     *
     * @var IRequest
     */
    private IRequest $request;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->request          = $this->createMock(IRequest::class);
        $this->discoveryService = $this->createMock(ProspectDiscoveryService::class);
        $l10n                   = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);

        $this->controller = new ProspectController(
            $this->request,
            $this->discoveryService,
            $l10n,
        );
    }//end setUp()

    /**
     * Test index returns discovery results.
     *
     * @return void
     */
    public function testIndexReturnsResults(): void
    {
        $this->request->method('getParam')->willReturn('false');
        $this->discoveryService->method('discover')->willReturn([
            'prospects' => [],
            'total'     => 0,
        ]);

        $response = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(200, $response->getStatus());
    }//end testIndexReturnsResults()

    /**
     * Test index returns 400 on error result.
     *
     * @return void
     */
    public function testIndexReturns400OnError(): void
    {
        $this->request->method('getParam')->willReturn('false');
        $this->discoveryService->method('discover')->willReturn([
            'error' => 'no_icp_configured',
        ]);

        $response = $this->controller->index();

        $this->assertSame(400, $response->getStatus());
    }//end testIndexReturns400OnError()

    /**
     * Test index returns 503 on exception.
     *
     * @return void
     */
    public function testIndexReturns503OnException(): void
    {
        $this->request->method('getParam')->willReturn('false');
        $this->discoveryService->method('discover')
            ->willThrowException(new \RuntimeException('API error'));

        $response = $this->controller->index();

        $this->assertSame(503, $response->getStatus());
    }//end testIndexReturns503OnException()

    /**
     * Test createLead returns 400 without trade name.
     *
     * @return void
     */
    public function testCreateLeadReturns400WithoutTradeName(): void
    {
        $this->request->method('getParams')->willReturn([]);

        $response = $this->controller->createLead();

        $this->assertSame(400, $response->getStatus());
    }//end testCreateLeadReturns400WithoutTradeName()

    /**
     * Test createLead returns 201 on success.
     *
     * @return void
     */
    public function testCreateLeadReturns201OnSuccess(): void
    {
        $this->request->method('getParams')->willReturn([
            'tradeName' => 'Test BV',
        ]);
        $this->discoveryService->method('createLeadFromProspect')->willReturn([
            'clientData' => ['name' => 'Test BV'],
            'leadData'   => ['title' => 'Test BV'],
        ]);

        $response = $this->controller->createLead();

        $this->assertSame(201, $response->getStatus());
    }//end testCreateLeadReturns201OnSuccess()
}//end class
