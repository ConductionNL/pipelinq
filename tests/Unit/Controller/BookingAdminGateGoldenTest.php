<?php

/**
 * Golden test of the staff gate of BookingAdminController.
 *
 * Calls every booking administration action with no session, with a
 * signed-in user who is not CRM staff, and with one who is, and pins the exact
 * refusal each answers before any service is touched, or that it got past.
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
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\BookingAdminController;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\Rule\AnyInvokedCount;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * Pins who gets past the staff gate of every booking administration action.
 */
final class BookingAdminGateGoldenTest extends TestCase {
	/**
	 * The controllers under test.
	 *
	 * @var array<int, class-string>
	 */
	private const CONTROLLERS = [BookingAdminController::class];

	/**
	 * Who gets past the gate, per action and caller.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private const EXPECTED = [
		'BookingAdminController::reschedule' => [
			'no session' => [
				401,
				[
					'error' => 'Authentication required',
				],
			],
			'signed in' => [
				403,
				[
					'error' => 'Forbidden',
				],
			],
			'crm user' => [
				422,
				[
					'error' => 'newStartAt is required',
				],
			],
		],
		'BookingAdminController::cancel' => [
			'no session' => [
				401,
				[
					'error' => 'Authentication required',
				],
			],
			'signed in' => 'passed',
			'crm user' => 'passed',
		],
		'BookingAdminController::markCompleted' => [
			'no session' => [
				401,
				[
					'error' => 'Authentication required',
				],
			],
			'signed in' => [
				403,
				[
					'error' => 'Forbidden',
				],
			],
			'crm user' => 'passed',
		],
		'BookingAdminController::markNoShow' => [
			'no session' => [
				401,
				[
					'error' => 'Authentication required',
				],
			],
			'signed in' => [
				403,
				[
					'error' => 'Forbidden',
				],
			],
			'crm user' => 'passed',
		],
		'BookingAdminController::sendReminder' => [
			'no session' => [
				401,
				[
					'error' => 'Authentication required',
				],
			],
			'signed in' => [
				403,
				[
					'error' => 'Forbidden',
				],
			],
			'crm user' => 'passed',
		],
		'BookingAdminController::confirmDeposit' => [
			'no session' => [
				401,
				[
					'error' => 'Authentication required',
				],
			],
			'signed in' => [
				403,
				[
					'error' => 'Forbidden',
				],
			],
			'crm user' => 'passed',
		],
	];

	/**
	 * Every action refuses the callers it must refuse, before touching a service.
	 *
	 * @return void
	 */
	public function testOnlyTheRightCallersGetPastTheGate(): void {
		$seen = [];
		foreach (self::CONTROLLERS as $class) {
			foreach ($this->actions(class: $class) as $action) {
				$name = (new ReflectionClass($class))->getShortName().'::'.$action->getName();
				foreach (['no session', 'signed in', 'crm user'] as $caller) {
					$seen[$name][$caller] = $this->outcome($action, $caller);
				}
			}
		}

		$this->assertSame(self::EXPECTED, $seen);
	}//end testOnlyTheRightCallersGetPastTheGate()

	/**
	 * The public actions a controller declares itself.
	 *
	 * @param class-string $class The controller.
	 *
	 * @return array<int, ReflectionMethod> The actions.
	 */
	private function actions(string $class): array {
		$actions = [];
		foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
			if ($method->getDeclaringClass()->getName() === $class && $method->isConstructor() === false) {
				$actions[] = $method;
			}
		}

		return $actions;
	}//end actions()

	/**
	 * Call one action as one kind of caller.
	 *
	 * @param ReflectionMethod $action The action.
	 * @param string           $caller The kind of caller.
	 *
	 * @return mixed The refusal as [status, body] when it touched no service, else 'passed'.
	 */
	private function outcome(ReflectionMethod $action, string $caller): mixed {
		$counters = [];
		$controller = $this->controller($action->getDeclaringClass(), $caller, $counters);
		// A mocked service answers empty values, which an action that got past
		// the gate may trip over: that still counts as getting past it.
		set_error_handler(static function (int $severity, string $message): bool {
			throw new \ErrorException($message, 0, $severity);
		});
		try {
			$response = $action->invokeArgs($controller, $this->arguments($action));
		} catch (Throwable $e) {
			return 'passed';
		} finally {
			restore_error_handler();
		}

		$touched = array_sum(array_map(static fn (AnyInvokedCount $count): int => $count->numberOfInvocations(), $counters));
		if ($response instanceof JSONResponse && $touched === 0 && $response->getStatus() >= 400) {
			return [$response->getStatus(), $response->getData()];
		}

		return 'passed';
	}//end outcome()

	/**
	 * Build the controller with a session and policy for the caller, and counting mocks for the rest.
	 *
	 * @param ReflectionClass<object>           $class    The controller class.
	 * @param string                            $caller   The kind of caller.
	 * @param array<int, AnyInvokedCount>       $counters Receives one invocation counter per service mock.
	 *
	 * @return object The controller.
	 */
	private function controller(ReflectionClass $class, string $caller, array &$counters): object {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($caller !== 'no session') {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('u-1');
		}

		$session->method('getUser')->willReturn($user);
		$policy = $this->createMock(ObjectOwnerAccessPolicy::class);
		$policy->method('isPrivileged')->willReturn($caller === 'crm user');
		$args = [];
		foreach ($class->getConstructor()->getParameters() as $parameter) {
			$type = (string)$parameter->getType();
			$args[] = match ($type) {
				IUserSession::class => $session,
				ObjectOwnerAccessPolicy::class => $policy,
				IRequest::class => $this->createMock(IRequest::class),
				IL10N::class => $this->translator(),
				default => $this->countingMock(type: $type, counters: $counters),
			};
		}

		return $class->newInstanceArgs($args);
	}//end controller()

	/**
	 * A pass-through translator.
	 *
	 * @return IL10N The translator.
	 */
	private function translator(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);
		return $l10n;
	}//end translator()

	/**
	 * A mock of a collaborator whose every call is counted.
	 *
	 * @param string                      $type     The class or interface.
	 * @param array<int, AnyInvokedCount> $counters Receives the counter.
	 *
	 * @return object The mock.
	 */
	private function countingMock(string $type, array &$counters): object {
		$mock = $this->createMock($type);
		$count = $this->any();
		$mock->expects($count)->method($this->anything());
		$counters[] = $count;
		return $mock;
	}//end countingMock()

	/**
	 * Placeholder arguments for an action.
	 *
	 * @param ReflectionMethod $action The action.
	 *
	 * @return array<int, mixed> The arguments.
	 */
	private function arguments(ReflectionMethod $action): array {
		$args = [];
		foreach ($action->getParameters() as $parameter) {
			$type = $parameter->getType();
			$name = ($type instanceof ReflectionNamedType) ? $type->getName() : 'string';
			$args[] = match ($name) {
				'int' => 1,
				'bool' => false,
				'array' => [],
				default => 'id-1',
			};
		}

		return $args;
	}//end arguments()
}//end class
