<?php

/**
 * Unit tests for BillingHandoffController.
 *
 * Sending hours to billing is a manager action: it needs a session and the
 * manager role before the request is even validated, and each handoff
 * outcome maps to its own HTTP status.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\BillingHandoffController;
use OCA\Pipelinq\Lifecycle\BillingHandoffAccessPolicy;
use OCA\Pipelinq\Service\IntegrationDetector;
use OCA\Pipelinq\Service\TimeBillingHandoffService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for BillingHandoffController.
 *
 * @covers \OCA\Pipelinq\Controller\BillingHandoffController
 */
class BillingHandoffControllerTest extends TestCase {

	/**
	 * Mock handoff service.
	 *
	 * @var TimeBillingHandoffService&MockObject
	 */
	private TimeBillingHandoffService&MockObject $handoff;

	/**
	 * Mock access policy.
	 *
	 * @var BillingHandoffAccessPolicy&MockObject
	 */
	private BillingHandoffAccessPolicy&MockObject $policy;

	/**
	 * Mock integration detector.
	 *
	 * @var IntegrationDetector&MockObject
	 */
	private IntegrationDetector&MockObject $integrations;

	/**
	 * Mock session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $session;

	/**
	 * Controller under test.
	 *
	 * @var BillingHandoffController
	 */
	private BillingHandoffController $controller;

	/**
	 * Wire the controller.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->handoff = $this->createMock(originalClassName: TimeBillingHandoffService::class);
		$this->policy = $this->createMock(originalClassName: BillingHandoffAccessPolicy::class);
		$this->integrations = $this->createMock(originalClassName: IntegrationDetector::class);
		$this->session = $this->createMock(originalClassName: IUserSession::class);

		$this->controller = new BillingHandoffController(
			request: $this->createMock(originalClassName: IRequest::class),
			handoffService: $this->handoff,
			accessPolicy: $this->policy,
			integrations: $this->integrations,
			userSession: $this->session
		);
	}//end setUp()

	/**
	 * Sign in as `jan`, a manager or not.
	 *
	 * @param bool $manager Whether jan holds the manager role.
	 *
	 * @return void
	 */
	private function signIn(bool $manager): void {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('jan');
		$this->session->method('getUser')->willReturn($user);
		$this->policy->method('isManager')->with('jan')->willReturn($manager);
	}//end signIn()

	/**
	 * Without a session neither endpoint answers.
	 *
	 * @return void
	 */
	public function testRequiresASession(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->handoff->expects(self::never())->method(self::anything());

		self::assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $this->controller->availability()->getStatus());
		self::assertSame(
			expected: Http::STATUS_UNAUTHORIZED,
			actual: $this->controller->trigger(clientId: 'c1', periodStart: '2026-10-01', periodEnd: '2026-10-31')->getStatus()
		);
	}//end testRequiresASession()

	/**
	 * Availability reports the handoff state, the billing link and the caller's role.
	 *
	 * @return void
	 */
	public function testAvailabilityDescribesTheCaller(): void {
		$this->signIn(manager: false);
		$this->handoff->method('handoffAvailable')->willReturn(true);
		$this->integrations->method('shillinq')->willReturn(['url' => '/apps/shillinq']);

		self::assertSame(
			expected: ['available' => true, 'deepLinkUrl' => '/apps/shillinq', 'isManager' => false],
			actual: $this->controller->availability()->getData()
		);
	}//end testAvailabilityDescribesTheCaller()

	/**
	 * A non-manager cannot send hours to billing.
	 *
	 * @return void
	 */
	public function testANonManagerCannotTrigger(): void {
		$this->signIn(manager: false);
		$this->handoff->expects(self::never())->method('sendToBilling');

		$response = $this->controller->trigger(clientId: 'c1', periodStart: '2026-10-01', periodEnd: '2026-10-31');

		self::assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());
	}//end testANonManagerCannotTrigger()

	/**
	 * A manager must name the client and both period ends.
	 *
	 * @return void
	 */
	public function testTriggerNeedsClientAndPeriod(): void {
		$this->signIn(manager: true);
		$this->handoff->expects(self::never())->method('sendToBilling');

		$response = $this->controller->trigger(clientId: 'c1', periodStart: '2026-10-01');

		self::assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
		self::assertSame(expected: 'invalid-request', actual: $response->getData()['status']);
	}//end testTriggerNeedsClientAndPeriod()

	/**
	 * Handoff outcomes and the HTTP status each maps to.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: int}>
	 */
	public static function outcomes(): array {
		return [
			'synced' => [['status' => 'synced', 'entries' => 4], Http::STATUS_OK],
			'nothing to send' => [['status' => 'empty'], Http::STATUS_OK],
			'billing app absent' => [['status' => 'not-available'], Http::STATUS_CONFLICT],
			'already sent' => [['status' => 'conflict'], Http::STATUS_CONFLICT],
			'client not mapped' => [['status' => 'unmapped'], Http::STATUS_UNPROCESSABLE_ENTITY],
			'billing failed' => [['status' => 'failed'], Http::STATUS_BAD_GATEWAY],
			'no status' => [[], Http::STATUS_BAD_GATEWAY],
		];
	}//end outcomes()

	/**
	 * A manager's handoff returns the service result with the matching status.
	 *
	 * @param array<string, mixed> $result The service result.
	 * @param int $status The expected HTTP status.
	 *
	 * @return void
	 */
	#[DataProvider('outcomes')]
	public function testTriggerMapsTheOutcome(array $result, int $status): void {
		$this->signIn(manager: true);
		$this->handoff->expects(self::once())->method('sendToBilling')
			->with('c1', '2026-10-01', '2026-10-31')
			->willReturn($result);

		$response = $this->controller->trigger(clientId: 'c1', periodStart: '2026-10-01', periodEnd: '2026-10-31');

		self::assertSame(expected: $status, actual: $response->getStatus());
		self::assertSame(expected: $result, actual: $response->getData());
	}//end testTriggerMapsTheOutcome()
}//end class
