<?php

/**
 * Contract tests for IncomingCallController.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\IncomingCallController;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\Cti\IncomingCallFinder;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * `GET /api/cti/incoming-call`: whose line is asked about, and who is refused.
 *
 * @covers \OCA\Pipelinq\Controller\IncomingCallController
 *
 * @spec openspec/changes/screen-pop-incoming-call/specs/cti-screenpop-adapter/spec.md#requirement-the-incoming-call-card-opens-the-screen-pop-req-cti-012
 */
class IncomingCallControllerTest extends TestCase {
	/**
	 * Build the controller.
	 *
	 * @param IncomingCallFinder $finder The finder.
	 * @param string|null $uid The signed-in uid, or null for anonymous.
	 * @param bool $privileged Whether that user is in a privileged group.
	 *
	 * @return IncomingCallController The controller.
	 */
	private function controller(IncomingCallFinder $finder, ?string $uid, bool $privileged = true): IncomingCallController {
		$request = $this->createMock(IRequest::class);
		// A uid in the request must not be read: the line asked about is the session's.
		$request->method('getParam')->willReturn('agent-2');

		$user = null;
		if ($uid !== null) {
			$user = $this->createConfiguredMock(IUser::class, ['getUID' => $uid]);
		}

		return new IncomingCallController(
			$request,
			$this->createConfiguredMock(IUserSession::class, ['getUser' => $user]),
			$this->createConfiguredMock(ObjectOwnerAccessPolicy::class, ['isPrivileged' => $privileged]),
			$finder,
		);
	}//end controller()

	/**
	 * The route names this controller and method.
	 *
	 * A method no route names answers 404, and the card reads that as "no
	 * telephony" in silence.
	 *
	 * @return void
	 */
	public function testTheRouteNamesThisMethod(): void {
		$routes = (require dirname(__DIR__, 3) . '/appinfo/routes.php')['routes'];
		$found = array_values(array_filter($routes, static fn (array $route): bool => $route['url'] === '/api/cti/incoming-call'));

		$this->assertCount(1, $found);
		$this->assertSame('incomingCall#show', $found[0]['name']);
		$this->assertSame('GET', $found[0]['verb']);
		$this->assertTrue(method_exists(IncomingCallController::class, 'show'));
	}//end testTheRouteNamesThisMethod()

	/**
	 * The finder is asked about the signed-in agent, and its answer goes out unchanged.
	 *
	 * @return void
	 */
	public function testTheSignedInAgentIsAskedAbout(): void {
		$answer = ['enabled' => true, 'delayMs' => 0, 'call' => ['id' => 'c1', 'fromNumber' => '+31612345678']];
		$finder = $this->createMock(IncomingCallFinder::class);
		$finder->expects($this->once())->method('forAgent')->with('agent-1')->willReturn($answer);

		$response = $this->controller(finder: $finder, uid: 'agent-1')->show();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($answer, $response->getData());
	}//end testTheSignedInAgentIsAskedAbout()

	/**
	 * An anonymous caller is refused with 401 and the finder is not asked.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerIsRefused(): void {
		$finder = $this->createMock(IncomingCallFinder::class);
		$finder->expects($this->never())->method('forAgent');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(finder: $finder, uid: null)->show()->getStatus());
	}//end testAnAnonymousCallerIsRefused()

	/**
	 * A user outside the privileged groups is refused with 403 and the finder is not asked.
	 *
	 * @return void
	 */
	public function testAnUnprivilegedUserIsRefused(): void {
		$finder = $this->createMock(IncomingCallFinder::class);
		$finder->expects($this->never())->method('forAgent');

		$response = $this->controller(finder: $finder, uid: 'visitor', privileged: false)->show();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testAnUnprivilegedUserIsRefused()
}//end class
