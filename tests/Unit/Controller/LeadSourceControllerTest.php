<?php

/**
 * Unit tests for LeadSourceController.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\LeadSourceController;
use OCA\Pipelinq\Service\SystemTagService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for LeadSourceController.
 */
class LeadSourceControllerTest extends TestCase {
	/**
	 * The controller under test.
	 *
	 * @var LeadSourceController
	 */
	private LeadSourceController $controller;

	/**
	 * Mock system tag service.
	 *
	 * @var SystemTagService
	 */
	private SystemTagService $tagService;

	/**
	 * Mock user session.
	 *
	 * @var IUserSession
	 */
	private IUserSession $userSession;

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
	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->tagService = $this->createMock(SystemTagService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$logger = $this->createMock(LoggerInterface::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('test-user');
		$this->userSession->method('getUser')->willReturn($user);

		$this->controller = new LeadSourceController(
			$this->request,
			$this->tagService,
			$this->userSession,
			$logger,
		);
	}//end setUp()

	/**
	 * Test index returns tags.
	 *
	 * @return void
	 */
	public function testIndexReturnsTags(): void {
		$this->tagService->method('getTags')->willReturn([
			['id' => 1, 'name' => 'Website'],
		]);

		$response = $this->controller->index();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertCount(1, $data['tags']);
	}//end testIndexReturnsTags()

	/**
	 * Test create returns created tag.
	 *
	 * @return void
	 */
	public function testCreateReturnsTag(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturn('Referral');

		$this->tagService->method('addTag')
			->willReturn(['id' => 2, 'name' => 'Referral']);

		$controller = new LeadSourceController($request, $this->tagService, $this->userSession, $this->createMock(LoggerInterface::class));
		$response = $controller->create();

		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertSame('Referral', $data['tag']['name']);
	}//end testCreateReturnsTag()

	/**
	 * Test create returns error on exception.
	 *
	 * @return void
	 */
	public function testCreateReturnsErrorOnException(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturn('');

		$this->tagService->method('addTag')
			->willThrowException(new \InvalidArgumentException('Tag name cannot be empty'));

		$controller = new LeadSourceController($request, $this->tagService, $this->userSession, $this->createMock(LoggerInterface::class));
		$response = $controller->create();

		$this->assertSame(400, $response->getStatus());
	}//end testCreateReturnsErrorOnException()

	/**
	 * Test destroy returns success.
	 *
	 * @return void
	 */
	public function testDestroyReturnsSuccess(): void {
		$this->tagService->expects($this->once())->method('removeTag');

		$response = $this->controller->destroy('5');

		$data = $response->getData();
		$this->assertTrue($data['success']);
	}//end testDestroyReturnsSuccess()

}//end class
