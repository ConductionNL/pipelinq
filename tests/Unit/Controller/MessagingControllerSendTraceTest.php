<?php

/**
 * Trace test for what MessagingController::send hands to MessagingService.
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
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\ChannelProviderRepository;
use OCA\Pipelinq\Service\ConsentService;
use OCA\Pipelinq\Service\MessagingService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * The send call carries the contact loaded through the RBAC, every request
 * value, and the session user as actor, whatever the request claims.
 *
 * The call is flattened to one map of field => value (value objects by their
 * public properties), so the trace does not depend on how the fields are
 * grouped.
 *
 * @spec openspec/specs/outbound-messaging/spec.md#requirement-req-om-004-server-side-send-endpoint
 */
class MessagingControllerSendTraceTest extends TestCase {
	/**
	 * Every request value reaches the service; the actor is the session user.
	 *
	 * @return void
	 */
	public function testSmsSendCarriesEveryValueAndTheSessionUser(): void {
		$call = $this->trace(
			request: [
				'contactId' => 'c-1',
				'channel' => 'sms',
				'body' => 'hallo',
				'templateId' => 'tpl-9',
				'parameters' => ['p'],
				'providerHint' => 'cmcom',
				'clientId' => 'cl-1',
				'inReplyTo' => 'in-1',
			]
		);

		$this->assertSame(
			[
				'actor' => 'agent-session',
				'body' => 'hallo',
				'channel' => 'sms',
				'clientId' => 'cl-1',
				'contact' => ['uuid' => 'c-1', 'loadedAs' => 'c-1'],
				'inReplyTo' => 'in-1',
				'parameters' => ['p'],
				'providerHint' => 'cmcom',
				'templateId' => 'tpl-9',
			],
			$call
		);
	}//end testSmsSendCarriesEveryValueAndTheSessionUser()

	/**
	 * Left-out request values reach the service as their defaults.
	 *
	 * @return void
	 */
	public function testWhatsAppTemplateSendKeepsDefaults(): void {
		$call = $this->trace(request: ['contactId' => 'c-2', 'channel' => 'whatsapp', 'templateId' => 'tpl-1', 'parameters' => ['Jan']]);

		$this->assertSame(
			[
				'actor' => 'agent-session',
				'body' => '',
				'channel' => 'whatsapp',
				'clientId' => '',
				'contact' => ['uuid' => 'c-2', 'loadedAs' => 'c-2'],
				'inReplyTo' => '',
				'parameters' => ['Jan'],
				'providerHint' => null,
				'templateId' => 'tpl-1',
			],
			$call
		);
	}//end testWhatsAppTemplateSendKeepsDefaults()

	/**
	 * Call the controller and return the flattened send call.
	 *
	 * The request object answers a forged user for every parameter, so an
	 * actor read from the request instead of the session shows up.
	 *
	 * @param array<string, mixed> $request The controller arguments.
	 *
	 * @return array<string, mixed>
	 */
	private function trace(array $request): array {
		$httpRequest = $this->createMock(IRequest::class);
		$httpRequest->method('getParam')->willReturn('forged-user');
		$httpRequest->method('getParams')->willReturn(['userId' => 'forged-user', 'actor' => 'forged-user', 'agent' => 'forged-user']);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('agent-session');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$policy = $this->createMock(ObjectOwnerAccessPolicy::class);
		$policy->method('isPrivileged')->willReturnCallback(static fn (string $uid): bool => $uid === 'agent-session');

		$calls = [];
		$service = $this->createMock(MessagingService::class);
		$service->method('loadContact')->willReturnCallback(
			static fn (string $contactId): array => ['uuid' => $contactId, 'loadedAs' => $contactId]
		);
		$service->method('send')->willReturnCallback(
			function (...$args) use (&$calls): array {
				$calls[] = $this->flatten(args: $args);
				return ['status' => 'sent', 'messageId' => 'm-1'];
			}
		);

		$controller = new MessagingController(
			$httpRequest,
			$service,
			$this->createMock(ChannelProviderRepository::class),
			$this->createMock(ConsentService::class),
			$session,
			$policy,
			$this->createMock(LoggerInterface::class),
		);
		$controller->send(...$request);

		$this->assertCount(1, $calls);
		return $calls[0];
	}//end trace()

	/**
	 * Flatten positional send() arguments into one sorted field map.
	 *
	 * @param array<int, mixed> $args The arguments as PHPUnit passes them.
	 *
	 * @return array<string, mixed>
	 */
	private function flatten(array $args): array {
		$flat = [];
		foreach ((new ReflectionMethod(MessagingService::class, 'send'))->getParameters() as $i => $param) {
			$value = $args[$i];
			if (is_object($value) === true) {
				$flat = array_merge($flat, get_object_vars($value));
				continue;
			}

			$flat[$param->getName()] = $value;
		}

		ksort($flat);
		return $flat;
	}//end flatten()
}//end class
