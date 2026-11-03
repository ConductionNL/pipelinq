<?php

/**
 * Golden test of every controller that refuses through the CRM privilege check.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Lifecycle\CrmPrivilegeCheck;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Tests\Unit\Support\Autowire;
use OCA\Pipelinq\Tests\Unit\Support\ComparesGolden;
use OCA\Pipelinq\Tests\Unit\Support\NextcloudDispatch;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;
use stdClass;
use Throwable;

/**
 * Every public action of each controller, called with no session, as a user
 * without the CRM privilege and as a privileged user with forged identity
 * parameters. Each case records the response and every call to the policy and
 * the other collaborators, in order. Controllers are built by autowiring from
 * doubles of their leaf collaborators. One golden file per controller in
 * CrmPrivilegeRefusalGolden/; set GOLDEN_RECORD=1 to rewrite them, then run
 * once more without it.
 *
 * @covers \OCA\Pipelinq\Controller\AnalyticsController
 * @covers \OCA\Pipelinq\Controller\CampaignController
 * @covers \OCA\Pipelinq\Controller\CompetitorWatchController
 * @covers \OCA\Pipelinq\Controller\ContactSyncController
 * @covers \OCA\Pipelinq\Controller\ContactSyncSettingsController
 * @covers \OCA\Pipelinq\Controller\ContractController
 * @covers \OCA\Pipelinq\Controller\CtiController
 * @covers \OCA\Pipelinq\Controller\CtiEscalationQueueController
 * @covers \OCA\Pipelinq\Controller\IncomingCallController
 * @covers \OCA\Pipelinq\Controller\JourneyController
 * @covers \OCA\Pipelinq\Controller\LoyaltyController
 * @covers \OCA\Pipelinq\Controller\LoyaltyPaymentController
 * @covers \OCA\Pipelinq\Controller\LoyaltyReportingController
 * @covers \OCA\Pipelinq\Controller\MarketingConnectorController
 * @covers \OCA\Pipelinq\Controller\MessagingController
 * @covers \OCA\Pipelinq\Controller\NotesController
 * @covers \OCA\Pipelinq\Controller\OrganisationEnrichmentController
 * @covers \OCA\Pipelinq\Controller\ProspectController
 * @covers \OCA\Pipelinq\Controller\RapportageController
 * @covers \OCA\Pipelinq\Controller\ReportingController
 * @covers \OCA\Pipelinq\Controller\RoutingController
 * @covers \OCA\Pipelinq\Controller\SearchConsoleController
 * @covers \OCA\Pipelinq\Controller\SemanticHandoffController
 * @covers \OCA\Pipelinq\Controller\SlaAttainmentController
 * @covers \OCA\Pipelinq\Controller\TeamLoadController
 * @covers \OCA\Pipelinq\Controller\TicketWooRequestController
 * @covers \OCA\Pipelinq\Controller\WeeklyReviewController
 * @covers \OCA\Pipelinq\Lifecycle\CrmPrivilegeCheck
 */
class CrmPrivilegeRefusalGoldenTest extends TestCase {
	use ComparesGolden;

	/**
	 * The controller => the actions the refusal guards.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const GUARDED = [
		'AnalyticsController' => ['overview', 'commercial', 'trends', 'funnels'],
		'CampaignController' => ['vocabularies', 'report', 'createLandingPage', 'write'],
		'CompetitorWatchController' => ['index', 'run'],
		'ContactSyncController' => ['status', 'search', 'import', 'create', 'writeBack'],
		'ContactSyncSettingsController' => ['run'],
		'ContractController' => ['create', 'summary', 'renewalMetrics'],
		'CtiController' => ['screenPop', 'clickToDial', 'disposition', 'attachRecording'],
		'CtiEscalationQueueController' => ['index'],
		'IncomingCallController' => ['show'],
		'JourneyController' => ['index', 'show', 'compile', 'setStatus', 'runs', 'write'],
		'LoyaltyController' => ['getAccount', 'getAccountHistory', 'getRedemptionOptions', 'initiateRedemption', 'lookupRedemptionCode', 'useRedemptionCode', 'lookupGiftCard', 'redeemGiftCard', 'activateGiftCard', 'issueGiftCard', 'activateProgramme'],
		'LoyaltyPaymentController' => ['pay'],
		'LoyaltyReportingController' => ['kpis', 'liability', 'tierDistribution', 'expiryForecast'],
		'MarketingConnectorController' => ['matomo', 'connectionAudit'],
		'MessagingController' => ['send', 'resend', 'preflight', 'consent'],
		'NotesController' => ['list', 'create', 'deleteAll', 'deleteSingle'],
		'OrganisationEnrichmentController' => ['propose', 'apply'],
		'ProspectController' => ['index'],
		'RapportageController' => ['getPipelineStats'],
		'ReportingController' => ['getKpis', 'getChannels', 'getAgents', 'getSla', 'exportCsv'],
		'RoutingController' => ['getSuggestions'],
		'SearchConsoleController' => ['index'],
		'SemanticHandoffController' => ['requestAvailability', 'convertRequestToCase'],
		'SlaAttainmentController' => ['attainment'],
		'TeamLoadController' => ['index'],
		'TicketWooRequestController' => ['availability', 'convert'],
		'WeeklyReviewController' => ['show', 'generate'],
	];

	private const PRIVILEGED_USER = 'crm-user';
	private const FORGED = ['uid' => 'mallory', 'userId' => 'mallory', 'user' => 'mallory', 'createdBy' => 'mallory', 'owner' => 'mallory'];

	/**
	 * What the current case did, in order.
	 *
	 * @var array<int, mixed>
	 */
	private array $trace = [];

	/**
	 * The controllers, one data set each.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function controllers(): array {
		$sets = [];
		foreach (array_keys(self::GUARDED) as $controller) {
			$sets[$controller] = [$controller];
		}

		return $sets;
	}//end controllers()

	/**
	 * Every public action over the three callers, compared with the controller's golden file.
	 *
	 * @param string $controller The controller's short name.
	 *
	 * @return void
	 */
	#[DataProvider('controllers')]
	public function testGolden(string $controller): void {
		$actual = [];
		foreach ($this->actions(controller: $controller) as $action) {
			$actual[$action.' no session'] = $this->runCase(controller: $controller, action: $action, uid: null);
			$actual[$action.' unprivileged'] = $this->runCase(controller: $controller, action: $action, uid: 'intern');
			$actual[$action.' privileged'] = $this->runCase(controller: $controller, action: $action, uid: self::PRIVILEGED_USER);
		}

		$this->assertGolden(file: __DIR__.'/CrmPrivilegeRefusalGolden/'.$controller.'.json', actual: $actual);
	}//end testGolden()

	/**
	 * A session user without a UID is answered as no session, before any privilege check.
	 *
	 * @param string $controller The controller's short name.
	 *
	 * @return void
	 */
	#[DataProvider('controllers')]
	public function testSessionUserWithoutUidIsUnauthenticated(string $controller): void {
		foreach ($this->guardedActions(controller: $controller) as $action) {
			$result = $this->runCase(controller: $controller, action: $action, uid: '');

			$this->assertSame(401, $result['status'], $action);
			$this->assertSame($this->runCase(controller: $controller, action: $action, uid: null), $result, $action);
		}
	}//end testSessionUserWithoutUidIsUnauthenticated()

	/**
	 * A caller without the privilege gets 403 from every guarded action, with nothing else called.
	 *
	 * @param string $controller The controller's short name.
	 *
	 * @return void
	 */
	#[DataProvider('controllers')]
	public function testUnprivilegedCallerIsRefusedBeforeAnythingElse(string $controller): void {
		foreach ($this->guardedActions(controller: $controller) as $action) {
			$result = $this->runCase(controller: $controller, action: $action, uid: 'intern');

			$this->assertSame(403, $result['status'], $action);
			$this->assertSame([['ObjectOwnerAccessPolicy::isPrivileged' => ['intern']]], $result['trace'], $action);
		}
	}//end testUnprivilegedCallerIsRefusedBeforeAnythingElse()

	/**
	 * The guarded actions that are public.
	 *
	 * @param string $controller The controller's short name.
	 *
	 * @return array<int, string>
	 */
	private function guardedActions(string $controller): array {
		return array_values(array_intersect(self::GUARDED[$controller], $this->actions(controller: $controller)));
	}//end guardedActions()

	/**
	 * The controller's own public actions, in source order.
	 *
	 * @param string $controller The controller's short name.
	 *
	 * @return array<int, string>
	 */
	private function actions(string $controller): array {
		$class = 'OCA\\Pipelinq\\Controller\\'.$controller;
		$actions = [];
		foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
			if ($method->getDeclaringClass()->getName() === $class && $method->isConstructor() === false && $method->isStatic() === false) {
				$actions[] = $method->getName();
			}
		}

		return $actions;
	}//end actions()

	/**
	 * Run one action against a freshly autowired controller.
	 *
	 * @param string      $controller The controller's short name.
	 * @param string      $action     The action.
	 * @param string|null $uid        The session user, or null for no session.
	 *
	 * @return array<string, mixed>
	 */
	private function runCase(string $controller, string $action, ?string $uid): array {
		$this->trace = [];
		$class = 'OCA\\Pipelinq\\Controller\\'.$controller;
		$params = ($this->sampleParameters(class: $class, action: $action) + self::FORGED);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);
		$request->method('getParams')->willReturn($params);

		$doubles = [
			'appName' => 'pipelinq',
			IRequest::class => $request,
			IUserSession::class => $this->session(uid: $uid),
			IL10N::class => $this->l10n(),
			ObjectOwnerAccessPolicy::class => $this->recorder(type: ObjectOwnerAccessPolicy::class),
		];
		$this->addLeafDoubles(class: $class, doubles: $doubles);
		$subject = Autowire::build(class: $class, doubles: $doubles);

		// The doubles' neutral arrays lack the keys real services answer with.
		set_error_handler(static fn (): bool => true, (E_WARNING | E_NOTICE));
		try {
			$response = NextcloudDispatch::call(controller: $subject, method: $action, params: $params);
			$result = ['class' => get_class($response), 'status' => $response->getStatus()];
			if ($response instanceof JSONResponse) {
				$result['data'] = $this->plain(value: $response->getData());
			}
		} catch (Throwable $e) {
			$result = ['threw' => get_class($e), 'message' => $this->scrubbed(text: $e->getMessage())];
		} finally {
			restore_error_handler();
		}

		return $result + ['trace' => $this->trace];
	}//end runCase()

	/**
	 * A value for each of the action's parameters, by type.
	 *
	 * @param string $class  The controller class.
	 * @param string $action The action.
	 *
	 * @return array<string, mixed>
	 */
	private function sampleParameters(string $class, string $action): array {
		$params = [];
		foreach ((new ReflectionMethod($class, $action))->getParameters() as $param) {
			$type = $param->getType();
			$name = ($type instanceof ReflectionNamedType ? $type->getName() : 'string');
			$params[$param->getName()] = match ($name) {
				'int'   => '7',
				'float' => '1.5',
				'bool'  => true,
				'array' => ['sample' => 'value'],
				default => 'sample-'.$param->getName(),
			};
		}

		return $params;
	}//end sampleParameters()

	/**
	 * Add a recording double for every constructor collaborator not yet present,
	 * walking through the privilege check and final classes, which cannot be doubled.
	 *
	 * @param string               $class   The class to walk.
	 * @param array<string, mixed> $doubles The doubles so far.
	 *
	 * @return void
	 */
	private function addLeafDoubles(string $class, array &$doubles): void {
		$constructor = (new ReflectionClass($class))->getConstructor();
		if ($constructor === null) {
			return;
		}

		foreach ($constructor->getParameters() as $param) {
			$type = $param->getType();
			if ($type instanceof ReflectionNamedType === false || $type->isBuiltin() === true || isset($doubles[$type->getName()]) === true) {
				continue;
			}

			$typeName = $type->getName();
			$reflection = new ReflectionClass($typeName);
			if ($typeName === CrmPrivilegeCheck::class || $reflection->isFinal() === true || $reflection->isReadOnly() === true) {
				$this->addLeafDoubles(class: $typeName, doubles: $doubles);
				continue;
			}

			$doubles[$typeName] = $this->recorder(type: $typeName);
		}
	}//end addLeafDoubles()

	/**
	 * A double that records its calls and answers each with a neutral value of the return type.
	 *
	 * @param string $type The class or interface.
	 *
	 * @return object
	 */
	private function recorder(string $type): object {
		$double = $this->createMock($type);
		foreach ((new ReflectionClass($type))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
			if ($method->isConstructor() === true || $method->isStatic() === true || $method->isFinal() === true || $method->isDestructor() === true) {
				continue;
			}

			$double->method($method->getName())->willReturnCallback(
				function (...$args) use ($method, $double): mixed {
					$this->trace[] = [$method->getDeclaringClass()->getShortName().'::'.$method->getName() => $this->recordedArguments(method: $method, args: $args)];
					if ($method->getName() === 'isPrivileged') {
						return $args[0] === self::PRIVILEGED_USER;
					}

					return $this->neutral(type: $method->getReturnType(), self: $double);
				}
			);
		}

		return $double;
	}//end recorder()

	/**
	 * The arguments worth pinning: Pipelinq's and Nextcloud's own methods, without trailing defaults.
	 * OpenRegister's signatures differ per loaded version, so its calls are recorded by name only.
	 *
	 * @param ReflectionMethod  $method The called method.
	 * @param array<int, mixed> $args   The arguments.
	 *
	 * @return array<int, mixed>|string
	 */
	private function recordedArguments(ReflectionMethod $method, array $args): array|string {
		if (str_starts_with($method->getDeclaringClass()->getName(), 'OCA\\OpenRegister\\') === true) {
			return '(not recorded)';
		}

		$params = $method->getParameters();
		for ($i = (count($args) - 1); $i >= 0; $i--) {
			if (isset($params[$i]) === false || $params[$i]->isDefaultValueAvailable() === false || $params[$i]->getDefaultValue() !== $args[$i]) {
				break;
			}

			unset($args[$i]);
		}

		return array_values(array_map(fn (mixed $arg): mixed => $this->plain(value: $arg), $args));
	}//end recordedArguments()

	/**
	 * A neutral value of a return type.
	 *
	 * @param ReflectionType|null $type The return type.
	 * @param object              $self The double, for `static` and `self`.
	 *
	 * @return mixed
	 */
	private function neutral(?ReflectionType $type, object $self): mixed {
		if ($type === null || $type->allowsNull() === true) {
			return null;
		}

		if ($type instanceof ReflectionUnionType) {
			$type = $type->getTypes()[0];
		}

		$name = ($type instanceof ReflectionNamedType ? $type->getName() : 'mixed');
		return match ($name) {
			'void', 'mixed', 'null' => null,
			'string' => '',
			'int'    => 0,
			'float'  => 0.0,
			'bool', 'false' => false,
			'true'   => true,
			'array', 'iterable' => [],
			'object' => new stdClass(),
			'self', 'static' => $self,
			default  => $this->createStub($name),
		};
	}//end neutral()

	/**
	 * A value in plain, comparable form: objects by class, times masked, test paths removed.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed
	 */
	private function plain(mixed $value): mixed {
		if (is_array($value) === true) {
			return array_map(fn (mixed $item): mixed => $this->plain(value: $item), $value);
		}

		if ($value instanceof Throwable) {
			return get_class($value).': '.$this->scrubbed(text: $value->getMessage());
		}

		if (is_object($value) === true) {
			return 'object '.$this->scrubbed(text: get_class($value));
		}

		if (is_string($value) === true) {
			return $this->scrubbed(text: $value);
		}

		return $value;
	}//end plain()

	/**
	 * Remove what differs per run or per line: double names, source positions and times.
	 *
	 * @param string $text The text.
	 *
	 * @return string
	 */
	private function scrubbed(string $text): string {
		$text = (string)preg_replace('/MockObject_(\w+?)_[0-9a-f]{6,}/', 'MockObject_$1', $text);
		$text = (string)preg_replace('/ on line \d+/', '', $text);
		$text = (string)preg_replace('/called in \S+/', 'called', $text);
		return (string)preg_replace('/\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2})?(\.\d+)?(Z|[+-]\d{2}:?\d{2})?)?/', '(time)', $text);
	}//end scrubbed()

	/**
	 * A session for the given user (or none).
	 *
	 * @param string|null $uid The acting user.
	 *
	 * @return IUserSession
	 */
	private function session(?string $uid): IUserSession {
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		return $session;
	}//end session()

	/**
	 * A translator that marks what went through it.
	 *
	 * @return IL10N
	 */
	private function l10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array|string $params = []): string => 't:'.vsprintf($text, (array)$params)
		);
		return $l10n;
	}//end l10n()
}//end class
