<?php

/**
 * Golden tests for who may call the blast, segment and template controllers.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/marketing-api/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\BlastController;
use OCA\Pipelinq\Controller\SegmentController;
use OCA\Pipelinq\Controller\TemplateController;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Every public action: refused without a user, refused for a user without
 * CRM privilege, allowed for a privileged user.
 */
class MarketingControllersAccessGoldenTest extends TestCase {
	/**
	 * Every public action of the three controllers.
	 *
	 * @return array<string, array{0: class-string, 1: string}>
	 */
	public static function actionProvider(): array {
		$cases = [];
		foreach ([BlastController::class, SegmentController::class, TemplateController::class] as $class) {
			foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
				if ($method->getDeclaringClass()->getName() !== $class || $method->isConstructor() === true) {
					continue;
				}

				$cases[(new ReflectionClass($class))->getShortName() . '::' . $method->getName()] = [$class, $method->getName()];
			}
		}

		return $cases;
	}//end actionProvider()

	/**
	 * The pinned set of actions, so a new action is a conscious addition here.
	 *
	 * @return void
	 */
	public function testTheActionsAreTheKnownOnes(): void {
		$this->assertSame(
			[
				'BlastController::index', 'BlastController::create', 'BlastController::show', 'BlastController::update', 'BlastController::send',
				'BlastController::cancel', 'BlastController::deliveries', 'BlastController::attribution', 'BlastController::performance',
				'SegmentController::index', 'SegmentController::signals', 'SegmentController::create', 'SegmentController::show',
				'SegmentController::members', 'SegmentController::compliance', 'SegmentController::preview', 'SegmentController::update',
				'SegmentController::refreshSize',
				'TemplateController::index', 'TemplateController::create', 'TemplateController::show', 'TemplateController::update',
				'TemplateController::preview', 'TemplateController::render', 'TemplateController::validate',
			],
			array_keys(self::actionProvider())
		);
	}//end testTheActionsAreTheKnownOnes()

	/**
	 * Without a user every action answers 401 and touches no service.
	 *
	 * @param class-string $class  The controller.
	 * @param string       $action The action.
	 *
	 * @return void
	 *
	 * @dataProvider actionProvider
	 */
	public function testNoUserIsRefused(string $class, string $action): void {
		$response = $this->invoke(class: $class, action: $action, user: false, privileged: false, strict: true);
		$this->assertSame([401, ['error' => 'Authentication required']], [$response->getStatus(), $response->getData()]);
	}//end testNoUserIsRefused()

	/**
	 * A user without CRM privilege is refused with 403 before any service
	 * call.
	 *
	 * @param class-string $class  The controller.
	 * @param string       $action The action.
	 *
	 * @return void
	 *
	 * @dataProvider actionProvider
	 */
	public function testUnprivilegedUser(string $class, string $action): void {
		$response = $this->invoke(class: $class, action: $action, user: true, privileged: false, strict: true);
		$this->assertSame([403, ['error' => 'Forbidden']], [$response->getStatus(), $response->getData()]);
	}//end testUnprivilegedUser()

	/**
	 * A privileged user reaches the action.
	 *
	 * @param class-string $class  The controller.
	 * @param string       $action The action.
	 *
	 * @return void
	 *
	 * @dataProvider actionProvider
	 */
	public function testPrivilegedUserIsAllowed(string $class, string $action): void {
		$response = $this->invoke(class: $class, action: $action, user: true, privileged: true, strict: false);
		$this->assertNotContains($response->getStatus(), [401, 403]);
	}//end testPrivilegedUserIsAllowed()

	/**
	 * Call one action on a controller built over doubles.
	 *
	 * @param class-string $class      The controller.
	 * @param string       $action     The action.
	 * @param bool         $user       Whether a user is logged in.
	 * @param bool         $privileged Whether the user has CRM privilege.
	 * @param bool         $strict     Whether every service must stay untouched.
	 *
	 * @return JSONResponse The response.
	 */
	private function invoke(string $class, string $action, bool $user, bool $privileged, bool $strict): JSONResponse {
		$session = $this->createMock(IUserSession::class);
		$account = $this->createMock(IUser::class);
		$account->method('getUID')->willReturn('marketeer');
		$session->method('getUser')->willReturn($user === true ? $account : null);

		$policy = $this->createMock(ObjectOwnerAccessPolicy::class);
		$policy->method('isPrivileged')->willReturn($privileged);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => $default);
		$request->method('getParams')->willReturn([]);

		$args = [];
		foreach ((new ReflectionMethod($class, '__construct'))->getParameters() as $parameter) {
			$type = $parameter->getType();
			$name = $type instanceof ReflectionNamedType ? $type->getName() : '';
			$args[] = match (true) {
				$name === IRequest::class => $request,
				$name === IUserSession::class => $session,
				$name === ObjectOwnerAccessPolicy::class => $policy,
				$parameter->isDefaultValueAvailable() === true => $parameter->getDefaultValue(),
				default => $this->service(class: $name, strict: $strict),
			};
		}

		$controller = new $class(...$args);
		$callArgs = [];
		foreach ((new ReflectionMethod($class, $action))->getParameters() as $parameter) {
			$callArgs[] = $parameter->isDefaultValueAvailable() === true ? $parameter->getDefaultValue() : 'id-1';
		}

		return $controller->$action(...$callArgs);
	}//end invoke()

	/**
	 * A service double; a strict one fails on any call.
	 *
	 * @param class-string $class  The service.
	 * @param bool         $strict Whether any call fails the test.
	 *
	 * @return object The double.
	 */
	private function service(string $class, bool $strict): object {
		$service = $this->createMock($class);
		if ($strict === true) {
			$service->expects($this->never())->method($this->anything());
		}

		return $service;
	}//end service()
}//end class
