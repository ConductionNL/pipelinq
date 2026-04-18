<?php

/**
 * Unit tests for IntakeFormController.
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

use OCA\Pipelinq\Controller\IntakeFormController;
use OCA\Pipelinq\Service\IntakeFormService;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for IntakeFormController.
 *
 * @spec openspec/changes/2026-03-20-public-intake-forms/tasks.md#task-3.2
 */
class IntakeFormControllerTest extends TestCase
{

    /**
     * The request mock.
     *
     * @var IRequest&MockObject
     */
    private IRequest $request;

    /**
     * The intake form service mock.
     *
     * @var IntakeFormService&MockObject
     */
    private IntakeFormService $intakeFormService;

    /**
     * The URL generator mock.
     *
     * @var IURLGenerator&MockObject
     */
    private IURLGenerator $urlGenerator;

    /**
     * The controller under test.
     *
     * @var IntakeFormController
     */
    private IntakeFormController $controller;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->request           = $this->createMock(IRequest::class);
        $this->intakeFormService = $this->createMock(IntakeFormService::class);
        $this->urlGenerator      = $this->createMock(IURLGenerator::class);
        $this->controller        = new IntakeFormController(
            request: $this->request,
            intakeFormService: $this->intakeFormService,
            urlGenerator: $this->urlGenerator,
        );
    }//end setUp()

    /**
     * Test embed method returns embed code.
     *
     * @return void
     */
    public function testEmbedReturnsEmbedCode(): void
    {
        $formId     = 'test-form-123';
        $baseUrl    = 'https://example.com/';
        $iframeCode = '<iframe src="..."></iframe>';
        $jsCode     = '<script>...</script>';

        $this->urlGenerator->expects($this->once())
            ->method('getAbsoluteURL')
            ->with('/')
            ->willReturn($baseUrl);

        $this->intakeFormService->expects($this->once())
            ->method('generateIframeEmbed')
            ->with($formId, $baseUrl)
            ->willReturn($iframeCode);

        $this->intakeFormService->expects($this->once())
            ->method('generateJsEmbed')
            ->with($formId, $baseUrl)
            ->willReturn($jsCode);

        $response = $this->controller->embed(id: $formId);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertEquals($iframeCode, $data['iframe']);
        $this->assertEquals($jsCode, $data['js']);
    }//end testEmbedReturnsEmbedCode()

    /**
     * Test export method returns CSV download.
     *
     * @return void
     */
    public function testExportReturnsCsvDownload(): void
    {
        $formId     = 'test-form-456';
        $csvContent = "Submitted At,Status,Full Name\n2024-01-01,processed,John Doe";

        $this->intakeFormService->expects($this->once())
            ->method('exportCsv')
            ->with([], [])
            ->willReturn($csvContent);

        $response = $this->controller->export(id: $formId);

        $this->assertInstanceOf(DataDownloadResponse::class, $response);
    }//end testExportReturnsCsvDownload()
}//end class
