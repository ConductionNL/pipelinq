<?php

/**
 * Unit tests for PublicFormController.
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

use OCA\Pipelinq\Controller\PublicFormController;
use OCA\Pipelinq\Service\IntakeFormService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PublicFormController.
 *
 * @spec openspec/changes/2026-03-20-public-intake-forms/tasks.md#task-3.1
 */
class PublicFormControllerTest extends TestCase
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
     * The controller under test.
     *
     * @var PublicFormController
     */
    private PublicFormController $controller;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->request           = $this->createMock(IRequest::class);
        $this->intakeFormService = $this->createMock(IntakeFormService::class);
        $this->controller        = new PublicFormController(
            request: $this->request,
            intakeFormService: $this->intakeFormService,
        );
    }//end setUp()

    /**
     * Test show method returns form definition.
     *
     * @return void
     */
    public function testShowReturnsFormDefinition(): void
    {
        $formId   = 'test-form-123';
        $response = $this->controller->show(id: $formId);

        $this->assertInstanceOf(JSONResponse::class, $response);
    }//end testShowReturnsFormDefinition()

    /**
     * Test submit method with spam submission silently accepts.
     *
     * @return void
     */
    public function testSubmitWithSpamSilentlyAccepts(): void
    {
        $formId     = 'test-form-123';
        $submission = [
            'name'      => 'Test',
            '_hp_field' => 'filled',
        ];

        $this->request->expects($this->once())
            ->method('getParams')
            ->willReturn($submission);

        $this->request->expects($this->once())
            ->method('getRemoteAddress')
            ->willReturn('192.168.1.1');

        $this->intakeFormService->expects($this->once())
            ->method('isSpam')
            ->with($submission)
            ->willReturn(true);

        $response = $this->controller->submit(id: $formId);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertTrue($data['success']);
    }//end testSubmitWithSpamSilentlyAccepts()

    /**
     * Test submit method with rate limited submission.
     *
     * @return void
     */
    public function testSubmitWithRateLimitedSubmission(): void
    {
        $formId     = 'test-form-123';
        $ip         = '192.168.1.2';
        $submission = [
            'name' => 'Test',
        ];

        $this->request->expects($this->once())
            ->method('getParams')
            ->willReturn($submission);

        $this->request->expects($this->once())
            ->method('getRemoteAddress')
            ->willReturn($ip);

        $this->intakeFormService->expects($this->once())
            ->method('isSpam')
            ->with($submission)
            ->willReturn(false);

        $this->intakeFormService->expects($this->once())
            ->method('isRateLimited')
            ->with($ip, $formId)
            ->willReturn(true);

        $response = $this->controller->submit(id: $formId);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(429, $response->getStatus());
        $data = $response->getData();
        $this->assertFalse($data['success']);
    }//end testSubmitWithRateLimitedSubmission()

    /**
     * Test submit method with valid submission.
     *
     * @return void
     */
    public function testSubmitWithValidSubmission(): void
    {
        $formId     = 'test-form-123';
        $ip         = '192.168.1.3';
        $submission = [
            'name'  => 'John Doe',
            'email' => 'john@example.com',
        ];

        $this->request->expects($this->once())
            ->method('getParams')
            ->willReturn($submission);

        $this->request->expects($this->once())
            ->method('getRemoteAddress')
            ->willReturn($ip);

        $this->intakeFormService->expects($this->once())
            ->method('isSpam')
            ->with($submission)
            ->willReturn(false);

        $this->intakeFormService->expects($this->once())
            ->method('isRateLimited')
            ->with($ip, $formId)
            ->willReturn(false);

        $response = $this->controller->submit(id: $formId);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertTrue($data['success']);
    }//end testSubmitWithValidSubmission()
}//end class
