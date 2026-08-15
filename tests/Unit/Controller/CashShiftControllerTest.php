<?php

/**
 * Unit tests for CashShiftController.
 *
 * Asserts the HTTP surface of the cash-drawer lifecycle: each action requires an
 * authenticated user (401 otherwise), delegates the server-authoritative work to
 * CashShiftService with the operator/manager uid taken from the session (never
 * the body), and maps the service's OCS exceptions to the right HTTP status
 * (404 / 403 / 422) without leaking internal error detail on a 500.
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

use OCA\Pipelinq\Controller\CashShiftController;
use OCA\Pipelinq\Service\CashShiftService;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for CashShiftController.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Wires the controller's mocks.
 */
class CashShiftControllerTest extends TestCase {
	/** @var CashShiftController */
	private CashShiftController $controller;

	/** @var CashShiftService&MockObject */
	private CashShiftService $service;

	/** @var IRequest&MockObject */
	private IRequest $request;

	/** @var IUserSession&MockObject */
	private IUserSession $userSession;

	/**
	 * Wire the controller with mocks; l10n echoes its input.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(CashShiftService::class);
		$this->userSession = $this->createMock(IUserSession::class);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn (string $text): string => $text);

		$this->controller = new CashShiftController($this->request,
			$this->service,
			$this->userSession,
			$l10n,
			$this->createMock(LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * Make the session resolve to a user with the given uid.
	 *
	 * @param string $uid The acting uid.
	 *
	 * @return void
	 */
	private function loginAs(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}//end loginAs()

	/**
	 * Every action returns 401 when there is no user in the session.
	 *
	 * @return void
	 */
	public function testOpenRequiresAuthentication(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->service->expects($this->never())->method('openShift');

		$response = $this->controller->open();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testOpenRequiresAuthentication()

	/**
	 * open() delegates to the service with the session uid and body params.
	 *
	 * @return void
	 */
	public function testOpenDelegatesWithSessionUid(): void {
		$this->loginAs('clerk');
		$this->request->method('getParam')->willReturnMap([
			['drawer', '', 'kassa-01'],
			['floatAmount', 0, '100'],
			['reference', '', 'SHIFT-9'],
			['notes', '', ''],
		]);

		$this->service->expects($this->once())
			->method('openShift')
			->with('kassa-01', 100.0, 'clerk', 'SHIFT-9', '')
			->willReturn(['id' => 'shift-9', 'status' => 'open']);

		$response = $this->controller->open();
		$data = $response->getData();

		$this->assertSame('shift-9', $data['shift']['id']);
	}//end testOpenDelegatesWithSessionUid()

	/**
	 * count() delegates to recordCount with the session uid.
	 *
	 * @return void
	 */
	public function testCountDelegates(): void {
		$this->loginAs('clerk');
		$this->request->method('getParam')->willReturnMap([
			['amount', 0, '625.50'],
			['notes', '', 'blind'],
		]);

		$this->service->expects($this->once())
			->method('recordCount')
			->with('shift-1', 625.50, 'clerk', 'blind')
			->willReturn(['count' => [], 'diff' => [], 'shift' => []]);

		$response = $this->controller->count('shift-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testCountDelegates()

	/**
	 * A service OCSForbiddenException maps to HTTP 403.
	 *
	 * @return void
	 */
	public function testManagerGateMapsToForbidden(): void {
		$this->loginAs('clerk');
		$this->request->method('getParam')->willReturn('diff-1');
		$this->service->method('approveDiff')
			->willThrowException(new OCSForbiddenException('nope'));

		$response = $this->controller->approve('shift-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testManagerGateMapsToForbidden()

	/**
	 * A service OCSNotFoundException maps to HTTP 404.
	 *
	 * @return void
	 */
	public function testNotFoundMapsTo404(): void {
		$this->loginAs('clerk');
		$this->request->method('getParam')->willReturn('0');
		$this->service->method('recordDrop')
			->willThrowException(new OCSNotFoundException('missing'));

		$response = $this->controller->drop('missing');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testNotFoundMapsTo404()

	/**
	 * A service OCSBadRequestException maps to HTTP 422.
	 *
	 * @return void
	 */
	public function testBadRequestMapsTo422(): void {
		$this->loginAs('clerk');
		$this->request->method('getParam')->willReturn('0');
		$this->service->method('recordDrop')
			->willThrowException(new OCSBadRequestException('bad'));

		$response = $this->controller->drop('shift-1');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
	}//end testBadRequestMapsTo422()

	/**
	 * An unexpected exception maps to 500 with a generic message (no leak).
	 *
	 * @return void
	 */
	public function testUnexpectedErrorIsGeneric(): void {
		$this->loginAs('clerk');
		$this->request->method('getParam')->willReturn('diff-1');
		$this->service->method('rejectDiff')
			->willThrowException(new \RuntimeException('stack trace secret'));

		$response = $this->controller->reject('shift-1');
		$data = $response->getData();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertStringNotContainsString('secret', (string)($data['error'] ?? ''));
	}//end testUnexpectedErrorIsGeneric()
}//end class
