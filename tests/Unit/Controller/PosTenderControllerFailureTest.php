<?php

/**
 * PosTenderController: the summary endpoint, the remaining mapped refusals
 * and the logged 500 every endpoint falls back to.
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

use OCA\Pipelinq\Controller\PosTenderController;
use OCA\Pipelinq\Service\PosTenderService;
use OCA\Pipelinq\Service\TenderTypeNotFoundException;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * An unexpected failure is logged with its context and answered with a
 * generic 500 that does not leak the exception message.
 *
 * @covers \OCA\Pipelinq\Controller\PosTenderController
 */
class PosTenderControllerFailureTest extends TestCase {

	/**
	 * The tender service.
	 *
	 * @var PosTenderService&MockObject
	 */
	private PosTenderService&MockObject $service;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $session;

	/**
	 * The controller under test.
	 *
	 * @var PosTenderController
	 */
	private PosTenderController $controller;

	/**
	 * Build the controller with a signed-in cashier and request params as the body.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn(['name' => 'Cash']);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => $default);
		$this->service = $this->createMock(PosTenderService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->session->method('getUser')->willReturn($this->createMock(IUser::class));

		$this->controller = new PosTenderController($request, $this->service, $this->session, $this->logger);
	}//end setUp()

	/**
	 * Every endpoint, the service method it calls, and the 500 it falls back to.
	 *
	 * @return array<string, array{0: string, 1: array<int, string>, 2: string, 3: string, 4: array<string, string>}>
	 */
	public static function failures(): array {
		return [
			'indexTypes' => ['indexTypes', [], 'listTenderTypes', 'Failed to list tender types', []],
			'showType' => ['showType', ['t1'], 'getTenderTypeById', 'Failed to fetch tender type', ['id' => 't1']],
			'createType' => ['createType', [], 'createTenderType', 'Failed to create tender type', []],
			'updateType' => ['updateType', ['t1'], 'updateTenderType', 'Failed to update tender type', ['id' => 't1']],
			'destroyType' => ['destroyType', ['t1'], 'deleteTenderType', 'Failed to delete tender type', ['id' => 't1']],
			'indexTenders' => ['indexTenders', ['tx1'], 'getTendersForTransaction', 'Failed to list tenders', ['transactionId' => 'tx1']],
			'addTender' => ['addTender', ['tx1'], 'addTender', 'Failed to add tender', ['transactionId' => 'tx1']],
			'removeTender' => ['removeTender', ['tx1', 'tn1'], 'removeTender', 'Failed to remove tender', ['transactionId' => 'tx1', 'tenderId' => 'tn1']],
			'summary' => ['summary', ['tx1'], 'validateTenderSum', 'Failed to validate tender sum', ['transactionId' => 'tx1']],
		];
	}//end failures()

	/**
	 * An unexpected exception becomes a logged, generic 500.
	 *
	 * @param string                $action  The controller method.
	 * @param array<int, string>    $args    Its arguments.
	 * @param string                $method  The service method that fails.
	 * @param string                $message The generic error.
	 * @param array<string, string> $context The ids the log entry carries.
	 *
	 * @return void
	 *
	 * @dataProvider failures
	 */
	public function testUnexpectedFailureIsALogged500(string $action, array $args, string $method, string $message, array $context): void {
		$this->service->method($method)->willThrowException(new RuntimeException('db down'));
		$this->logger->expects($this->once())->method('error')->with(
			'Pipelinq POS tender: ' . ($action === 'indexTypes' ? 'listTenderTypes' : $action) . ' failed',
			$context + ['exception' => 'db down']
		);

		$response = $this->controller->{$action}(...$args);

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => $message], $response->getData());
	}//end testUnexpectedFailureIsALogged500()

	/**
	 * The summary is the service's tender-sum validation.
	 *
	 * @return void
	 */
	public function testSummaryReturnsTheValidation(): void {
		$this->service->expects($this->once())->method('validateTenderSum')->with('tx1')->willReturn(['valid' => true, 'remaining' => 0]);

		$response = $this->controller->summary(transactionId: 'tx1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['valid' => true, 'remaining' => 0], $response->getData());
	}//end testSummaryReturnsTheValidation()

	/**
	 * An unknown transaction is a 404 on the summary, the tender list and a removal.
	 *
	 * @return void
	 */
	public function testUnknownTransactionIs404(): void {
		$this->service->method('validateTenderSum')->willThrowException(new OCSNotFoundException('Transaction not found'));
		$this->service->method('getTendersForTransaction')->willThrowException(new OCSNotFoundException('Transaction not found'));
		$this->service->method('removeTender')->willThrowException(new OCSNotFoundException('Tender not found'));
		$this->logger->expects($this->never())->method('error');

		foreach ([$this->controller->summary(transactionId: 'tx'), $this->controller->indexTenders(transactionId: 'tx')] as $response) {
			$this->assertSame(404, $response->getStatus());
			$this->assertSame(['error' => 'Transaction not found'], $response->getData());
		}

		$removed = $this->controller->removeTender(transactionId: 'tx', tenderId: 'tn');
		$this->assertSame(404, $removed->getStatus());
		$this->assertSame(['error' => 'Tender not found'], $removed->getData());
	}//end testUnknownTransactionIs404()

	/**
	 * Updating a type returns the saved type, or a 400 with the validation message.
	 *
	 * @return void
	 */
	public function testUpdateType(): void {
		$this->service->expects($this->exactly(2))->method('updateTenderType')->willReturnOnConsecutiveCalls(
			['id' => 't1', 'name' => 'Cash'],
			$this->throwException(new OCSBadRequestException('Name is required'))
		);

		$ok = $this->controller->updateType(id: 't1');
		$this->assertSame(200, $ok->getStatus());
		$this->assertSame(['id' => 't1', 'name' => 'Cash'], $ok->getData());

		$bad = $this->controller->updateType(id: 't1');
		$this->assertSame(400, $bad->getStatus());
		$this->assertSame(['error' => 'Name is required'], $bad->getData());
	}//end testUpdateType()

	/**
	 * With no request body the form params are the payload.
	 *
	 * @return void
	 */
	public function testCreateTypeTakesTheRequestParams(): void {
		$this->service->expects($this->once())->method('createTenderType')->with(['name' => 'Cash'])->willReturn(['id' => 't2']);

		$response = $this->controller->createType();

		$this->assertSame(201, $response->getStatus());
		$this->assertSame(['id' => 't2'], $response->getData());
	}//end testCreateTypeTakesTheRequestParams()

	/**
	 * A missing type on show or update is a 404 with its message.
	 *
	 * @return void
	 */
	public function testMissingTypeIs404(): void {
		$this->service->method('updateTenderType')->willThrowException(new TenderTypeNotFoundException('Tender type not found'));

		$response = $this->controller->updateType(id: 'nope');

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'Tender type not found'], $response->getData());
	}//end testMissingTypeIs404()

	/**
	 * The cashier endpoints refuse an anonymous caller before the service is asked.
	 *
	 * @return void
	 */
	public function testAnonymousCallerIsRefused(): void {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$this->service->expects($this->never())->method($this->anything());
		$controller = new PosTenderController($this->createMock(IRequest::class), $this->service, $session, $this->logger);

		foreach (['showType' => ['t1'], 'addTender' => ['tx1'], 'removeTender' => ['tx1', 'tn1'], 'summary' => ['tx1']] as $action => $args) {
			try {
				$controller->{$action}(...$args);
				$this->fail($action . ' should refuse an anonymous caller');
			} catch (OCSForbiddenException $e) {
				$this->assertSame('Authentication required', $e->getMessage());
			}
		}
	}//end testAnonymousCallerIsRefused()
}//end class
