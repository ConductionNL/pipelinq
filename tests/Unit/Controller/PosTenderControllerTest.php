<?php

/**
 * Unit tests for PosTenderController.
 *
 * Verifies the HTTP surface of the POS split-tender endpoints (REQ-PST-001..006):
 *   - Every #[NoAdminRequired] action requires an authenticated user (403 otherwise).
 *   - Per-transaction endpoints delegate to PosTenderService and pass through
 *     its results, mapping the service's domain exceptions to the matching
 *     HTTP status (404 / 400 / 409).
 *   - The summary endpoint returns the validation envelope unchanged.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/pos-split-tender/tasks.md#10.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\PosTenderController;
use OCA\Pipelinq\Service\InvalidTenderException;
use OCA\Pipelinq\Service\PosTenderService;
use OCA\Pipelinq\Service\TenderTypeNotFoundException;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for PosTenderController.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/pos-split-tender/tasks.md#10.2
 */
class PosTenderControllerTest extends TestCase {

	private PosTenderController $controller;

	/**
	 * @var PosTenderService&MockObject
	 */
	private PosTenderService $service;

	/**
	 * @var IRequest&MockObject
	 */
	private IRequest $request;

	/**
	 * @var IUserSession&MockObject
	 */
	private IUserSession $session;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(PosTenderService::class);
		$this->session = $this->createMock(IUserSession::class);

		$this->controller = new PosTenderController($this->request,
			$this->service,
			$this->session,
			$this->createMock(LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * Make the session resolve to a user.
	 *
	 * @return void
	 */
	private function loginAs(string $uid = 'cashier'): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->session->method('getUser')->willReturn($user);
	}//end loginAs()

	// ---- Tender-type endpoints (REQ-PST-001) -------------------------------

	/**
	 * @return void
	 */
	public function testIndexTypesRequiresAuthenticatedUser(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->expectException(OCSForbiddenException::class);
		$this->controller->indexTypes();
	}//end testIndexTypesRequiresAuthenticatedUser()

	/**
	 * @return void
	 */
	public function testIndexTypesReturnsResults(): void {
		$this->loginAs();
		$this->request->method('getParam')->willReturn('0');
		$this->service->method('listTenderTypes')->with(false)->willReturn(
			[['id' => 't1', 'code' => 'CASH']]
		);

		$response = $this->controller->indexTypes();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertSame(1, $data['total']);
		$this->assertSame('CASH', $data['results'][0]['code']);
	}//end testIndexTypesReturnsResults()

	/**
	 * @return void
	 */
	public function testIndexTypesActiveOnlyFlagPassedThrough(): void {
		$this->loginAs();
		$this->request->method('getParam')->willReturn('1');
		$this->service->expects($this->once())
			->method('listTenderTypes')
			->with(true)
			->willReturn([]);

		$this->controller->indexTypes();
	}//end testIndexTypesActiveOnlyFlagPassedThrough()

	/**
	 * @return void
	 */
	public function testShowTypeMaps404OnMissing(): void {
		$this->loginAs();
		$this->service->method('getTenderTypeById')
			->willThrowException(new TenderTypeNotFoundException('not found'));

		$response = $this->controller->showType(id: 'missing');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testShowTypeMaps404OnMissing()

	/**
	 * @return void
	 */
	public function testShowTypeReturns200OnSuccess(): void {
		$this->loginAs();
		$this->service->method('getTenderTypeById')->willReturn(['id' => 't1', 'code' => 'CASH']);

		$response = $this->controller->showType(id: 't1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('CASH', $response->getData()['code']);
	}//end testShowTypeReturns200OnSuccess()

	/**
	 * @return void
	 */
	public function testCreateTypeReturns201OnSuccess(): void {
		$this->request->method('getParams')->willReturn(['name' => 'X', 'code' => 'X', 'glAccount' => '1']);
		$this->service->method('createTenderType')->willReturn(['id' => 't1']);

		$response = $this->controller->createType();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}//end testCreateTypeReturns201OnSuccess()

	/**
	 * @return void
	 */
	public function testCreateTypeMaps400OnBadRequest(): void {
		$this->request->method('getParams')->willReturn([]);
		$this->service->method('createTenderType')
			->willThrowException(new OCSBadRequestException('Name is required'));

		$response = $this->controller->createType();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testCreateTypeMaps400OnBadRequest()

	/**
	 * @return void
	 */
	public function testUpdateTypeMaps404WhenMissing(): void {
		$this->request->method('getParams')->willReturn(['name' => 'X']);
		$this->service->method('updateTenderType')
			->willThrowException(new TenderTypeNotFoundException('not found'));

		$response = $this->controller->updateType(id: 'missing');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testUpdateTypeMaps404WhenMissing()

	/**
	 * @return void
	 */
	public function testDestroyTypeReturns204OnSuccess(): void {
		$this->service->expects($this->once())->method('deleteTenderType')->with('t1');

		$response = $this->controller->destroyType(id: 't1');

		$this->assertSame(Http::STATUS_NO_CONTENT, $response->getStatus());
	}//end testDestroyTypeReturns204OnSuccess()

	/**
	 * @return void
	 */
	public function testDestroyTypeMaps400WhenActiveReferencesExist(): void {
		$this->service->method('deleteTenderType')
			->willThrowException(new OCSBadRequestException('Cannot delete tender type with active references'));

		$response = $this->controller->destroyType(id: 't1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testDestroyTypeMaps400WhenActiveReferencesExist()

	/**
	 * @return void
	 */
	public function testDestroyTypeMaps404WhenMissing(): void {
		$this->service->method('deleteTenderType')
			->willThrowException(new TenderTypeNotFoundException('not found'));

		$response = $this->controller->destroyType(id: 'missing');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testDestroyTypeMaps404WhenMissing()

	// ---- Per-transaction endpoints (REQ-PST-002..004) ----------------------

	/**
	 * @return void
	 */
	public function testIndexTendersRequiresAuthenticatedUser(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->expectException(OCSForbiddenException::class);
		$this->controller->indexTenders(transactionId: 'tx1');
	}//end testIndexTendersRequiresAuthenticatedUser()

	/**
	 * @return void
	 */
	public function testIndexTendersReturnsResultsAndValidation(): void {
		$this->loginAs();
		$this->service->method('getTendersForTransaction')->with('tx1')
			->willReturn([['id' => 'a', 'amount' => 10.0]]);
		$this->service->method('validateTenderSum')->with('tx1')
			->willReturn(['tenderSum' => 10.0, 'transactionTotal' => 10.0, 'variance' => 0.0, 'balanced' => true]);

		$response = $this->controller->indexTenders(transactionId: 'tx1');

		$data = $response->getData();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(1, $data['total']);
		$this->assertTrue($data['validation']['balanced']);
	}//end testIndexTendersReturnsResultsAndValidation()

	/**
	 * @return void
	 */
	public function testAddTenderReturns201OnSuccess(): void {
		$this->loginAs();
		$this->request->method('getParams')->willReturn(['tenderType' => 't1', 'amount' => 10.0]);
		$this->service->method('addTender')->willReturn(['id' => 'a', 'amount' => 10.0]);
		$this->service->method('validateTenderSum')->willReturn([
			'tenderSum' => 10.0,
			'transactionTotal' => 10.0,
			'variance' => 0.0,
			'balanced' => true,
		]);

		$response = $this->controller->addTender(transactionId: 'tx1');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('a', $data['tender']['id']);
		$this->assertTrue($data['validation']['balanced']);
	}//end testAddTenderReturns201OnSuccess()

	/**
	 * @return void
	 */
	public function testAddTenderMaps409WhenTransactionIsSettled(): void {
		$this->loginAs();
		$this->request->method('getParams')->willReturn(['tenderType' => 't1', 'amount' => 10.0]);
		$this->service->method('addTender')
			->willThrowException(new InvalidTenderException('settled', 409));

		$response = $this->controller->addTender(transactionId: 'tx1');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
	}//end testAddTenderMaps409WhenTransactionIsSettled()

	/**
	 * @return void
	 */
	public function testAddTenderMaps400OnValidationError(): void {
		$this->loginAs();
		$this->request->method('getParams')->willReturn(['tenderType' => 't1', 'amount' => 0.0]);
		$this->service->method('addTender')
			->willThrowException(new InvalidTenderException('Tender amount must be greater than 0.01', 400));

		$response = $this->controller->addTender(transactionId: 'tx1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testAddTenderMaps400OnValidationError()

	/**
	 * @return void
	 */
	public function testAddTenderMaps404WhenTenderTypeMissing(): void {
		$this->loginAs();
		$this->request->method('getParams')->willReturn(['tenderType' => 'missing', 'amount' => 10.0]);
		$this->service->method('addTender')
			->willThrowException(new TenderTypeNotFoundException('not found'));

		$response = $this->controller->addTender(transactionId: 'tx1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testAddTenderMaps404WhenTenderTypeMissing()

	/**
	 * @return void
	 */
	public function testAddTenderMaps404WhenTransactionMissing(): void {
		$this->loginAs();
		$this->request->method('getParams')->willReturn(['tenderType' => 't1', 'amount' => 10.0]);
		$this->service->method('addTender')
			->willThrowException(new OCSNotFoundException('Transaction not found'));

		$response = $this->controller->addTender(transactionId: 'tx-missing');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testAddTenderMaps404WhenTransactionMissing()

	/**
	 * @return void
	 */
	public function testRemoveTenderReturns204OnSuccess(): void {
		$this->loginAs();
		$this->service->expects($this->once())
			->method('removeTender')
			->with('tx1', 'tnd1');

		$response = $this->controller->removeTender(transactionId: 'tx1', tenderId: 'tnd1');

		$this->assertSame(Http::STATUS_NO_CONTENT, $response->getStatus());
	}//end testRemoveTenderReturns204OnSuccess()

	/**
	 * @return void
	 */
	public function testRemoveTenderMaps409WhenSettled(): void {
		$this->loginAs();
		$this->service->method('removeTender')
			->willThrowException(new InvalidTenderException('settled', 409));

		$response = $this->controller->removeTender(transactionId: 'tx1', tenderId: 'tnd1');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
	}//end testRemoveTenderMaps409WhenSettled()

	/**
	 * @return void
	 */
	public function testSummaryRequiresAuthentication(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->expectException(OCSForbiddenException::class);
		$this->controller->summary(transactionId: 'tx1');
	}//end testSummaryRequiresAuthentication()

	/**
	 * @return void
	 */
	public function testSummaryReturnsValidation(): void {
		$this->loginAs();
		$this->service->method('validateTenderSum')->willReturn([
			'tenderSum' => 50.0,
			'transactionTotal' => 100.0,
			'variance' => 50.0,
			'balanced' => false,
		]);

		$response = $this->controller->summary(transactionId: 'tx1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertFalse($response->getData()['balanced']);
	}//end testSummaryReturnsValidation()
}//end class
