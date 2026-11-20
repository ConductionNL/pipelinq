<?php

/**
 * Golden test of the CRM-user gate of the marketing content controllers.
 *
 * Calls every public action of the article, mailing-list, social-post and
 * subscription controllers with no session, with a signed-in user who may not
 * use the marketing section, and with one who may, and pins which calls are
 * refused with the one generic 403 before any service is touched.
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

use OCA\Pipelinq\Controller\MailingListController;
use OCA\Pipelinq\Controller\SocialPostController;
use OCA\Pipelinq\Controller\SubscriptionController;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCP\AppFramework\Http\JSONResponse;
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
 * Pins who gets past the gate of every marketing content action.
 */
final class CrmOnlyControllerGateTest extends TestCase {
	/**
	 * The controllers under test.
	 *
	 * @var array<int, class-string>
	 */
	private const CONTROLLERS = [
		MailingListController::class,
		SocialPostController::class,
		SubscriptionController::class,
	];

	/**
	 * Who gets past the gate, per action and caller.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const EXPECTED = [
		'MailingListController::index' => ['no session' => 'refused', 'signed in' => 'refused', 'crm user' => 'passed'],
		'MailingListController::show' => ['no session' => 'refused', 'signed in' => 'refused', 'crm user' => 'passed'],
		'MailingListController::create' => ['no session' => 'refused', 'signed in' => 'refused', 'crm user' => 'passed'],
		'MailingListController::update' => ['no session' => 'refused', 'signed in' => 'refused', 'crm user' => 'passed'],
		'MailingListController::subscriptions' => ['no session' => 'refused', 'signed in' => 'refused', 'crm user' => 'passed'],
		'SocialPostController::index' => ['no session' => 'refused', 'signed in' => 'refused', 'crm user' => 'passed'],
		'SocialPostController::show' => ['no session' => 'refused', 'signed in' => 'refused', 'crm user' => 'passed'],
		'SocialPostController::create' => ['no session' => 'refused', 'signed in' => 'refused', 'crm user' => 'passed'],
		'SocialPostController::update' => ['no session' => 'refused', 'signed in' => 'refused', 'crm user' => 'passed'],
		'SocialPostController::submit' => ['no session' => 'refused', 'signed in' => 'refused', 'crm user' => 'passed'],
		'SocialPostController::approve' => ['no session' => 'refused', 'signed in' => 'refused', 'crm user' => 'passed'],
		'SocialPostController::reject' => ['no session' => 'refused', 'signed in' => 'refused', 'crm user' => 'passed'],
		'SocialPostController::publications' => ['no session' => 'refused', 'signed in' => 'refused', 'crm user' => 'passed'],
		'SocialPostController::retry' => ['no session' => 'refused', 'signed in' => 'passed', 'crm user' => 'passed'],
		'SocialPostController::performance' => ['no session' => 'refused', 'signed in' => 'refused', 'crm user' => 'passed'],
		'SocialPostController::networkPerformance' => ['no session' => 'refused', 'signed in' => 'refused', 'crm user' => 'passed'],
		'SubscriptionController::forContact' => ['no session' => 'refused', 'signed in' => 'refused', 'crm user' => 'passed'],
		'SubscriptionController::softOptIn' => ['no session' => 'refused', 'signed in' => 'refused', 'crm user' => 'passed'],
		'SubscriptionController::unsubscribe' => ['no session' => 'refused', 'signed in' => 'refused', 'crm user' => 'passed'],
		'SubscriptionController::preferenceLink' => ['no session' => 'refused', 'signed in' => 'refused', 'crm user' => 'passed'],
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
	 * @return string 'refused' when it answered the generic 403 without touching a service, else 'passed'.
	 */
	private function outcome(ReflectionMethod $action, string $caller): string {
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
		$refused = $response instanceof JSONResponse
			&& $response->getStatus() === 403
			&& $response->getData() === ['error' => 'Forbidden']
			&& $touched === 0;
		if ($refused === true) {
			return 'refused';
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
				default => $this->countingMock(type: $type, counters: $counters),
			};
		}

		return $class->newInstanceArgs($args);
	}//end controller()

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
