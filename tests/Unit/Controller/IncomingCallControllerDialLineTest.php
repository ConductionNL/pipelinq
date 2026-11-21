<?php

/**
 * Contract tests for IncomingCallController::dialLine().
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\IncomingCallController;
use OCA\Pipelinq\Kernel\Contracts\DialLineReaderInterface;
use OCA\Pipelinq\Kernel\Contracts\IncomingCallFinderInterface;
use OCA\Pipelinq\Lifecycle\CrmPrivilegeCheck;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * `GET /api/cti/dial-line`: whose line is read, and who is refused.
 *
 * @covers \OCA\Pipelinq\Controller\IncomingCallController
 *
 * @spec openspec/changes/specify-capability-rows/tasks.md#task-36.1
 * @uses \OCA\Pipelinq\Lifecycle\CrmPrivilegeCheck
 * @uses   \OCA\Pipelinq\Middleware\Support\ApiPathAliases
 * @uses   \OCA\Pipelinq\Middleware\Support\StandardProtocolPaths
 */
class IncomingCallControllerDialLineTest extends TestCase {
	/**
	 * Build the controller.
	 *
	 * @param DialLineReaderInterface $reader The dial line reader.
	 * @param string|null $uid The signed-in uid, or null for anonymous.
	 * @param bool $privileged Whether that user is in a privileged group.
	 *
	 * @return IncomingCallController The controller.
	 */
	private function controller(DialLineReaderInterface $reader, ?string $uid, bool $privileged = true): IncomingCallController {
		$request = $this->createMock(originalClassName: IRequest::class);
		// A uid in the request must not be read: the line asked about is the session's.
		$request->method('getParam')->willReturn('agent-2');

		$user = null;
		if ($uid !== null) {
			$user = $this->createConfiguredMock(originalClassName: IUser::class, configuration: ['getUID' => $uid]);
		}

		$privilege = new CrmPrivilegeCheck(
			userSession: $this->createConfiguredMock(originalClassName: IUserSession::class, configuration: ['getUser' => $user]),
			policy: $this->createConfiguredMock(originalClassName: ObjectOwnerAccessPolicy::class, configuration: ['isPrivileged' => $privileged]),
		);

		return new IncomingCallController(
			request: $request,
			privilege: $privilege,
			incomingCalls: $this->createMock(originalClassName: IncomingCallFinderInterface::class),
			dialLines: $reader,
		);
	}//end controller()

	/**
	 * The route names this controller and method.
	 *
	 * @return void
	 */
	public function testTheRouteNamesThisMethod(): void {
		$routes = (require dirname(__DIR__, 3) . '/appinfo/routes.php')['routes'];
		$found = array_values(array_filter($routes, static fn (array $route): bool => $route['url'] === '/api/cti/dial-line'));

		$this->assertCount(expectedCount: 1, haystack: $found);
		$this->assertSame(expected: 'incomingCall#dialLine', actual: $found[0]['name']);
		$this->assertSame(expected: 'GET', actual: $found[0]['verb']);
		$this->assertTrue(condition: method_exists(IncomingCallController::class, 'dialLine'));
	}//end testTheRouteNamesThisMethod()

	/**
	 * The reader is asked about the signed-in agent, and its answer goes out unchanged.
	 *
	 * @return void
	 */
	public function testTheSignedInAgentsLineIsRead(): void {
		$answer = ['enabled' => true, 'extension' => '101', 'busy' => false];
		$reader = $this->createMock(originalClassName: DialLineReaderInterface::class);
		$reader->expects($this->once())->method('forAgent')->with('agent-1')->willReturn($answer);

		$response = $this->controller(reader: $reader, uid: 'agent-1')->dialLine();

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: $answer, actual: $response->getData());
	}//end testTheSignedInAgentsLineIsRead()

	/**
	 * An anonymous caller is refused with 401 and the reader is not asked.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerIsRefused(): void {
		$reader = $this->createMock(originalClassName: DialLineReaderInterface::class);
		$reader->expects($this->never())->method('forAgent');

		$response = $this->controller(reader: $reader, uid: null)->dialLine();

		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $response->getStatus());
	}//end testAnAnonymousCallerIsRefused()

	/**
	 * A user outside the privileged groups is refused with 403 and the reader is not asked.
	 *
	 * @return void
	 */
	public function testAnUnprivilegedUserIsRefused(): void {
		$reader = $this->createMock(originalClassName: DialLineReaderInterface::class);
		$reader->expects($this->never())->method('forAgent');

		$response = $this->controller(reader: $reader, uid: 'visitor', privileged: false)->dialLine();

		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());
	}//end testAnUnprivilegedUserIsRefused()
}//end class
