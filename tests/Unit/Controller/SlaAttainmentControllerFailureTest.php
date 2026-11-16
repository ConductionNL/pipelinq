<?php

/**
 * SlaAttainmentController: how a failing computation and a refusal are answered.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\SlaAttainmentController;
use OCA\Pipelinq\Kernel\Contracts\SlaAttainmentServiceInterface;
use OCA\Pipelinq\Lifecycle\CrmPrivilegeCheck;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * An unexpected failure is logged and answered with a fixed code, never the
 * exception text; a privilege refusal is answered before anything is computed.
 *
 * @covers \OCA\Pipelinq\Controller\SlaAttainmentController
 */
class SlaAttainmentControllerFailureTest extends TestCase {

	/**
	 * Attainment service mock.
	 *
	 * @var SlaAttainmentServiceInterface&MockObject
	 */
	private SlaAttainmentServiceInterface&MockObject $attainment;

	/**
	 * Privilege check mock.
	 *
	 * @var CrmPrivilegeCheck&MockObject
	 */
	private CrmPrivilegeCheck&MockObject $privilege;

	/**
	 * Logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The controller under test.
	 *
	 * @var SlaAttainmentController
	 */
	private SlaAttainmentController $controller;

	/**
	 * Build the controller; every query parameter takes its default.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->attainment = $this->createMock(SlaAttainmentServiceInterface::class);
		$this->privilege = $this->createMock(CrmPrivilegeCheck::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => $default);

		$this->controller = new SlaAttainmentController($request, $this->attainment, $this->privilege, $this->logger);
	}//end setUp()

	/**
	 * A computation that throws is logged and answered with `computeFailed`.
	 *
	 * @return void
	 */
	public function testAFailedComputationIsLoggedAndHidden(): void {
		$this->privilege->method('refusalWith')->willReturn(null);
		$this->attainment->expects($this->once())->method('compute')
			->with(
				[
					'bucket' => 'month',
					'date' => '',
					'week' => '',
					'month' => '',
					'quarter' => '',
					'groupBy' => 'policy',
					'policy' => '',
				]
			)
			->willThrowException(new RuntimeException('SQLSTATE[HY000] connection lost'));
		$this->logger->expects($this->once())->method('error')
			->with('SlaAttainmentController: compute failed', ['error' => 'SQLSTATE[HY000] connection lost']);

		$response = $this->controller->attainment();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'computeFailed'], $response->getData());
	}//end testAFailedComputationIsLoggedAndHidden()

	/**
	 * A refused caller gets the refusal and nothing is computed.
	 *
	 * @return void
	 */
	public function testARefusedCallerComputesNothing(): void {
		$refusal = new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		$this->privilege->expects($this->once())->method('refusalWith')
			->with(['error' => 'notAuthenticated'], ['error' => 'forbidden'])
			->willReturn($refusal);
		$this->attainment->expects($this->never())->method('compute');

		$this->assertSame($refusal, $this->controller->attainment());
	}//end testARefusedCallerComputesNothing()
}//end class
