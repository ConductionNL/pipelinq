<?php

/**
 * Unit tests for PosStaffReportController.
 *
 * Verifies the wire contract of the per-staff sales report endpoint:
 *   - 401 without a session user, 403 for a non-manager, and in BOTH cases the
 *     aggregation service is never reached (the report exposes commission
 *     figures for every staff member).
 *   - 200 returns the documented `{report: [{staffMemberId, displayName,
 *     transactionCount, total, totalTax}]}` envelope.
 *   - A service failure maps to 500 with a generic error body (no internals).
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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\PosStaffReportController;
use OCA\Pipelinq\Lifecycle\PosAccessPolicy;
use OCA\Pipelinq\Service\PosStaffReportService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for PosStaffReportController.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class PosStaffReportControllerTest extends TestCase {

	private PosStaffReportController $controller;

	/**
	 * @var PosStaffReportService&MockObject
	 */
	private PosStaffReportService $service;

	/**
	 * @var PosAccessPolicy&MockObject
	 */
	private PosAccessPolicy $policy;

	/**
	 * @var IUserSession&MockObject
	 */
	private IUserSession $session;

	protected function setUp(): void {
		$this->service = $this->createMock(PosStaffReportService::class);
		$this->policy = $this->createMock(PosAccessPolicy::class);
		$this->session = $this->createMock(IUserSession::class);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$this->controller = new PosStaffReportController($this->createMock(IRequest::class),
			$this->service,
			$this->policy,
			$this->session,
			$l10n,
			$this->createMock(LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * Make the session resolve to a user.
	 *
	 * @param string $uid The user id.
	 *
	 * @return void
	 */
	private function loginAs(string $uid = 'manager'): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->session->method('getUser')->willReturn($user);
	}//end loginAs()

	/**
	 * @return void
	 */
	public function testStaffSalesRequiresAuthentication(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->service->expects($this->never())->method('staffSalesReport');

		$response = $this->controller->staffSales();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame('Authentication required', $response->getData()['error']);
	}//end testStaffSalesRequiresAuthentication()

	/**
	 * A cashier without the manager role gets 403 and the aggregation is never
	 * computed — the report must not leak through an error path either.
	 *
	 * @return void
	 */
	public function testStaffSalesForbiddenForNonManager(): void {
		$this->loginAs(uid: 'cashier');
		$this->policy->method('isManager')->with('cashier')->willReturn(false);
		$this->service->expects($this->never())->method('staffSalesReport');

		$response = $this->controller->staffSales();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('Manager privileges required', $response->getData()['error']);
	}//end testStaffSalesForbiddenForNonManager()

	/**
	 * @return void
	 */
	public function testStaffSalesReturnsReportRowsForManager(): void {
		$this->loginAs(uid: 'manager');
		$this->policy->method('isManager')->with('manager')->willReturn(true);
		$this->service->method('staffSalesReport')->willReturn(
			[
				[
					'staffMemberId' => 'staff-1',
					'displayName' => 'Ada Lovelace',
					'transactionCount' => 3,
					'total' => 150.55,
					'totalTax' => 26.13,
				],
			]
		);

		$response = $this->controller->staffSales();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertArrayHasKey('report', $data);
		$this->assertCount(1, $data['report']);
		$this->assertSame('staff-1', $data['report'][0]['staffMemberId']);
		$this->assertSame('Ada Lovelace', $data['report'][0]['displayName']);
		$this->assertSame(3, $data['report'][0]['transactionCount']);
		$this->assertSame(150.55, $data['report'][0]['total']);
		$this->assertSame(26.13, $data['report'][0]['totalTax']);
	}//end testStaffSalesReturnsReportRowsForManager()

	/**
	 * An empty ledger is a 200 with an empty list, not a 404.
	 *
	 * @return void
	 */
	public function testStaffSalesReturnsEmptyReportAsOk(): void {
		$this->loginAs();
		$this->policy->method('isManager')->willReturn(true);
		$this->service->method('staffSalesReport')->willReturn([]);

		$response = $this->controller->staffSales();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['report' => []], $response->getData());
	}//end testStaffSalesReturnsEmptyReportAsOk()

	/**
	 * @return void
	 */
	public function testStaffSalesMaps500OnServiceFailure(): void {
		$this->loginAs();
		$this->policy->method('isManager')->willReturn(true);
		$this->service->method('staffSalesReport')
			->willThrowException(new RuntimeException('OpenRegister is unavailable'));

		$response = $this->controller->staffSales();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame('An unexpected error occurred', $response->getData()['error']);
		$this->assertStringNotContainsString('OpenRegister', (string)$response->getData()['error']);
	}//end testStaffSalesMaps500OnServiceFailure()
}//end class
