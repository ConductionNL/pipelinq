<?php

/**
 * Golden test for the outbound message send endpoint.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/outbound-messaging/spec.md#requirement-req-om-004-server-side-send-endpoint
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\MessagingController;
use OCA\Pipelinq\Lifecycle\CrmPrivilegeCheck;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\ChannelProviderRepository;
use OCA\Pipelinq\Service\ConsentService;
use OCA\Pipelinq\Service\MessagingService;
use OCA\Pipelinq\Tests\Unit\Support\NextcloudDispatch;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Throwable;

/**
 * MessagingController::send called as Nextcloud dispatches it: the session
 * and privilege checks (only agent holds the CRM privilege), the per-object
 * contact load, the route, content and actor handed to MessagingService::send
 * and the HTTP status of every outcome, the consent refusals included.
 * Compared with MessagingSendGolden.json; set MESSAGING_SEND_GOLDEN_RECORD=1
 * to rewrite it, then run once more without it.
 *
 * @covers \OCA\Pipelinq\Controller\MessagingController
 * @covers \OCA\Pipelinq\Util\ReadsTypedRequestParameters
 * @uses   \OCA\Pipelinq\Service\Messaging\MessageContent
 * @uses   \OCA\Pipelinq\Service\Messaging\MessageRoute
 *
 * @spec openspec/specs/outbound-messaging/spec.md#requirement-req-om-004-server-side-send-endpoint
 * @uses \OCA\Pipelinq\Lifecycle\CrmPrivilegeCheck
 */
class MessagingSendGoldenTest extends TestCase {
	private const GOLDEN = __DIR__ . '/MessagingSendGolden.json';

	/**
	 * What the current scenario did, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $trace = [];

	/**
	 * Run every scenario and compare it with the golden file.
	 *
	 * @return void
	 */
	public function testGolden(): void {
		$actual = [];
		foreach ($this->scenarios() as $name => $scenario) {
			$actual[$name] = $this->runScenario(scenario: $scenario);
		}

		$json = json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)) . "\n";
		if (getenv('MESSAGING_SEND_GOLDEN_RECORD') !== false) {
			file_put_contents(self::GOLDEN, $json);
			$this->markTestSkipped('recorded');
		}

		$expected = json_decode((string)file_get_contents(self::GOLDEN), true);
		$actual = json_decode($json, true);
		foreach ($expected as $name => $want) {
			$this->assertSame($want, ($actual[$name] ?? null), $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testGolden()

	/**
	 * Forged identity values in the request (getParam and getParams) change nothing: the actor is the session user.
	 *
	 * @return void
	 */
	public function testForgedIdentityParametersChangeNothing(): void {
		$forged = ['userId' => 'mallory', 'uid' => 'mallory', 'actor' => 'mallory', 'agent' => 'mallory', 'owner' => 'mallory', 'user' => 'mallory', '_rbac' => false];
		foreach ($this->scenarios() as $name => $scenario) {
			$plain = $this->runScenario(scenario: $scenario);
			$scenario['params'] = ($scenario['params'] ?? []) + $forged;
			$this->assertSame($plain, $this->runScenario(scenario: $scenario), $name);
		}
	}//end testForgedIdentityParametersChangeNothing()

	/**
	 * The scenarios: who calls, with which request parameters, and how the services answer.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function scenarios(): array {
		$sms = ['contactId' => 'c-1', 'channel' => 'sms', 'body' => 'Hallo', 'providerHint' => 'cmcom', 'clientId' => 'cl-1', 'inReplyTo' => 'in-1'];
		$wa = ['contactId' => 'c-1', 'channel' => 'whatsapp', 'templateId' => 'tpl-1', 'parameters' => ['Jan', 3]];
		return [
			'sms sent'                       => ['params' => $sms],
			'whatsapp template sent'         => ['params' => $wa],
			'defaults only'                  => ['params' => []],
			'channel only'                   => ['params' => ['channel' => 'sms']],
			'unknown channel'                => ['params' => ['contactId' => 'c-1', 'channel' => 'pigeon']],
			'upper-case channel'             => ['params' => ['contactId' => 'c-1', 'channel' => 'SMS']],
			'contact not readable'           => ['params' => $sms + ['contactId' => 'c-hidden'], 'hidden' => true],
			'refused without consent'        => ['params' => $sms, 'outcome' => ['status' => 'consent-refused', 'reason' => 'no opt-in']],
			'consent authority unavailable'  => ['params' => $sms, 'outcome' => ['status' => 'consent-unavailable']],
			'budget exhausted'               => ['params' => $sms, 'outcome' => ['status' => 'budget-exhausted']],
			'provider failed'                => ['params' => $sms, 'outcome' => ['status' => 'failed', 'error' => 'gateway']],
			'numeric outcome status'         => ['params' => $sms, 'outcome' => ['status' => 0]],
			'null values fall back'          => ['params' => ['contactId' => 'c-1', 'channel' => 'sms', 'body' => null, 'templateId' => null, 'parameters' => null, 'clientId' => null]],
			'integer contact id'             => ['params' => ['contactId' => 42, 'channel' => 'sms']],
			'array body'                     => ['params' => ['contactId' => 'c-1', 'channel' => 'sms', 'body' => ['x']]],
			'boolean template'               => ['params' => ['contactId' => 'c-1', 'channel' => 'whatsapp', 'templateId' => true]],
			'string parameters'              => ['params' => ['contactId' => 'c-1', 'channel' => 'whatsapp', 'parameters' => 'Jan']],
			'float provider hint'            => ['params' => ['contactId' => 'c-1', 'channel' => 'sms', 'providerHint' => 1.5]],
			'empty parameters list'          => ['params' => ['contactId' => 'c-1', 'channel' => 'whatsapp', 'parameters' => []]],
			'not privileged'                 => ['params' => $sms, 'user' => 'intern'],
			'not privileged, integer id'     => ['params' => ['contactId' => 42, 'channel' => 'sms'], 'user' => 'intern'],
			'no session'                     => ['params' => $sms, 'user' => null],
			'no session, array body'         => ['params' => ['body' => ['x']], 'user' => null],
		];
	}//end scenarios()

	/**
	 * Run one scenario against a fresh controller.
	 *
	 * @param array<string, mixed> $scenario The scenario.
	 *
	 * @return array<string, mixed>
	 */
	private function runScenario(array $scenario): array {
		$this->trace = [];
		$params = ($scenario['params'] ?? []);
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => (isset($params[$key]) === true ? $params[$key] : $default)
		);
		$request->method('getParams')->willReturn($params);

		$uid = (array_key_exists('user', $scenario) === true ? $scenario['user'] : 'agent');
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$policy = $this->createMock(ObjectOwnerAccessPolicy::class);
		$policy->method('isPrivileged')->willReturnCallback(
			function (string $uid): bool {
				$this->trace[] = ['isPrivileged' => $uid];
				return $uid === 'agent';
			}
		);

		$service = $this->createMock(MessagingService::class);
		$service->method('loadContact')->willReturnCallback(
			function (string $contactId) use ($scenario): ?array {
				$this->trace[] = ['loadContact' => $contactId];
				return (($scenario['hidden'] ?? false) === true ? null : ['uuid' => $contactId, 'phone' => '+316']);
			}
		);
		$service->method('send')->willReturnCallback(
			function (...$args) use ($scenario): array {
				$this->trace[] = ['send' => $this->flatten(args: $args)];
				return ($scenario['outcome'] ?? ['status' => 'sent', 'messageId' => 'm-1']);
			}
		);

		$controller = new MessagingController(
			request: $request,
			messagingService: $service,
			providerRepo: $this->createMock(ChannelProviderRepository::class),
			consentService: $this->createMock(ConsentService::class),
			privilege: new CrmPrivilegeCheck($session, $policy),
		);

		try {
			$response = NextcloudDispatch::call(controller: $controller, method: 'send', params: $params);
			$result = ['class' => get_class($response), 'status' => $response->getStatus()];
			if ($response instanceof JSONResponse) {
				$result['data'] = $response->getData();
			}
		} catch (Throwable $e) {
			$result = ['threw' => get_class($e), 'message' => $e->getMessage()];
		}

		return $result + ['trace' => $this->trace];
	}//end runScenario()

	/**
	 * MessagingService::send's arguments by name, value objects as their properties.
	 *
	 * @param array<int, mixed> $args The positional arguments.
	 *
	 * @return array<string, mixed>
	 */
	private function flatten(array $args): array {
		$flat = [];
		foreach ((new ReflectionMethod(MessagingService::class, 'send'))->getParameters() as $i => $param) {
			if (array_key_exists($i, $args) === false) {
				continue;
			}

			$value = $args[$i];
			$flat[$param->getName()] = (is_object($value) === true ? ['class' => get_class($value)] + get_object_vars($value) : $value);
		}

		return $flat;
	}//end flatten()
}//end class
