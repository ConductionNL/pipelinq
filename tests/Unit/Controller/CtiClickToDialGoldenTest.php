<?php

/**
 * Pins every answer of click-to-dial, saving the CTI configuration and submitting a disposition.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-4.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\CtiController;
use OCA\Pipelinq\Lifecycle\CrmPrivilegeCheck;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\ConnectionReportService;
use OCA\Pipelinq\Service\Cti\Result\OriginateResult;
use OCA\Pipelinq\Service\CtiService;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Caller, privilege and parameters, with status, body and the calls placed.
 */
class CtiClickToDialGoldenTest extends TestCase {

	/**
	 * Caller, privileged, params, expected status, body and dials.
	 *
	 * @return array<string, array<int, mixed>>
	 */
	public static function cases(): array {
		$required = [400, ['error' => 'targetNumber and extension are required']];
		$badTarget = [400, ['error' => 'targetNumber is not a dialable number']];
		$badExt = [400, ['error' => 'extension is not a dialable extension']];
		$ok = ['success' => true, 'externalCallId' => 'c-1', 'interactionId' => null, 'error' => null, 'platform' => 'asterisk'];
		$failed = ['success' => false, 'externalCallId' => null, 'interactionId' => null, 'error' => 'busy', 'platform' => 'asterisk'];
		return [
			'anonymous'          => [null, true, ['targetNumber' => 'sip:x@evil', 'extension' => '1'], 401, ['error' => 'Authentication required'], []],
			'not privileged'     => ['agent-1', false, ['targetNumber' => '+31612345678', 'extension' => '100'], 403, null, []],
			'no target'          => ['agent-1', true, ['extension' => '100'], ...$required, []],
			'no extension'       => ['agent-1', true, ['targetNumber' => '+31612345678'], ...$required, []],
			'sip target'         => ['agent-1', true, ['targetNumber' => 'sip:attacker@evil.example', 'extension' => '100'], ...$badTarget, []],
			'too long target'    => ['agent-1', true, ['targetNumber' => '+3161234567890123456789', 'extension' => '100'], ...$badTarget, []],
			'bad both'           => ['agent-1', true, ['targetNumber' => 'abc', 'extension' => 'x'], ...$badTarget, []],
			'letter extension'   => ['agent-1', true, ['targetNumber' => '+31 (6) 12-34', 'extension' => '10a'], ...$badExt, []],
			'long extension'     => ['agent-1', true, ['targetNumber' => '0612345678', 'extension' => '12345678901'], ...$badExt, []],
			'dialled'            => ['agent-1', true, ['targetNumber' => '+31 (6) 12-34', 'extension' => '1234567890', 'userId' => 'other'], 200, $ok, [['agent-1', '1234567890', '+31 (6) 12-34']]],
			'platform refused'   => ['agent-1', true, ['targetNumber' => '0612345678', 'extension' => '7'], 502, $failed, [['agent-1', '7', '0612345678']]],
		];
	}//end cases()

	/**
	 * Each case answers and dials exactly as pinned.
	 *
	 * @param string|null              $uid        The signed-in uid.
	 * @param bool                     $privileged Whether the caller holds the CRM privilege.
	 * @param array<string, string>    $params     The request parameters.
	 * @param int                      $status     The expected status.
	 * @param array<string, mixed>|null $body      The expected body (null: not pinned).
	 * @param array<int, array<int, string>> $dials The expected originate calls.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testClickToDial(?string $uid, bool $privileged, array $params, int $status, ?array $body, array $dials): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => $params[$key] ?? $default);
		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);
		$placed  = [];
		$service = $this->createMock(CtiService::class);
		$service->method('originateCall')->willReturnCallback(
			function (string $userId, string $extension, string $targetNumber) use (&$placed): OriginateResult {
				$placed[] = [$userId, $extension, $targetNumber];
				return $extension === '7'
					? new OriginateResult(success: false, error: 'busy', platform: 'asterisk')
					: new OriginateResult(success: true, externalCallId: 'c-1', platform: 'asterisk');
			}
		);
		$policy = $this->createConfiguredMock(ObjectOwnerAccessPolicy::class, ['isPrivileged' => $privileged, 'mayAccess' => $privileged]);
		$controller = new CtiController(
			$request,
			$service,
			new CrmPrivilegeCheck($session, $policy),
			$this->createMock(IGroupManager::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(ConnectionReportService::class),
		);

		$response = $controller->clickToDial();
		$this->assertSame($status, $response->getStatus());
		if ($body !== null) {
			$this->assertSame($body, $response->getData());
		}

		$this->assertSame($dials, $placed);
	}//end testClickToDial()

	/**
	 * A controller over the given request parameters, caller and service.
	 *
	 * @param array<string, mixed> $params  The request parameters.
	 * @param array<string, mixed> $context uid, privileged, admin, service, logger, reports.
	 *
	 * @return CtiController
	 */
	private function controllerFor(array $params, array $context): CtiController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => array_key_exists($key, $params) === true ? $params[$key] : $default);
		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($context['uid'] !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($context['uid']);
		}

		$session->method('getUser')->willReturn($user);
		$policy = $this->createConfiguredMock(ObjectOwnerAccessPolicy::class, ['isPrivileged' => $context['privileged'], 'mayAccess' => $context['privileged']]);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($context['admin']);
		return new CtiController($request, $context['service'], new CrmPrivilegeCheck($session, $policy), $groups, $context['logger'], $context['reports']);
	}//end controllerFor()

	/**
	 * Saving the CTI configuration: admin gate, the allowed fields, the report and the stripped secret.
	 *
	 * @return void
	 */
	public function testUpdateConfigIsExact(): void {
		$calls  = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			function (string $message, array $context = []) use (&$calls): void {
				$calls[] = ['info', $message, $context];
			}
		);
		$service = $this->createMock(CtiService::class);
		$service->method('saveConfig')->willReturnCallback(
			function (array $payload) use (&$calls): array {
				$calls[] = ['save', $payload];
				return $payload === [] ? [] : $payload + ['id' => 'cfg-1'];
			}
		);
		$service->method('testConnection')->willReturn(['ok' => true]);
		$reports = $this->createMock(ConnectionReportService::class);
		$reports->method('reportCtiCheck')->willReturnCallback(
			function (array $outcome) use (&$calls): bool {
				$calls[] = ['report', $outcome];
				return true;
			}
		);
		$context = ['uid' => 'admin', 'privileged' => true, 'admin' => true, 'service' => $service, 'logger' => $logger, 'reports' => $reports];

		$refused = $this->controllerFor(['platform' => 'asterisk'], ['admin' => false] + $context)->updateConfig();
		$this->assertSame([403, ['error' => 'Admin required']], [$refused->getStatus(), $refused->getData()]);
		$anonymous = $this->controllerFor(['platform' => 'asterisk'], ['uid' => null] + $context)->updateConfig();
		$this->assertSame(403, $anonymous->getStatus());
		$this->assertSame([], $calls);

		$params = ['webhook_secret' => 's3cret', 'platform' => 'asterisk', 'screen_pop_enabled' => false, 'api_base_url' => null, 'unknown' => 'x', 'default_country_code' => '31'];
		$saved  = $this->controllerFor($params, $context)->updateConfig();
		$this->assertSame([200, ['platform' => 'asterisk', 'screen_pop_enabled' => false, 'default_country_code' => '31', 'id' => 'cfg-1']], [$saved->getStatus(), $saved->getData()]);
		$fields = ['platform', 'screen_pop_enabled', 'webhook_secret', 'default_country_code'];
		$payload = ['platform' => 'asterisk', 'screen_pop_enabled' => false, 'webhook_secret' => 's3cret', 'default_country_code' => '31'];
		$this->assertSame(
			[['save', $payload], ['info', 'CTI config updated', ['user' => 'admin', 'fields' => $fields]], ['report', ['ok' => true]]],
			$calls
		);

		$calls = [];
		$empty = $this->controllerFor([], $context)->updateConfig();
		$this->assertSame([200, []], [$empty->getStatus(), $empty->getData()]);
		$this->assertSame([['save', []], ['info', 'CTI config updated', ['user' => 'admin', 'fields' => []]]], $calls);
	}//end testUpdateConfigIsExact()

	/**
	 * Submitting a disposition: CRM gate, required fields, the service call and its failures.
	 *
	 * @return void
	 */
	public function testDispositionIsExact(): void {
		$calls  = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context = []) use (&$calls): void {
				$calls[] = ['error', $message, $context];
			}
		);
		$service = $this->createMock(CtiService::class);
		$service->method('processDisposition')->willReturnCallback(
			function (string $interactionId, string $subject, string $outcome, string $notes = '', string $queue = '') use (&$calls): array {
				$calls[] = ['process', $interactionId, $subject, $outcome, $notes, $queue];
				if ($outcome === 'bad') {
					throw new \InvalidArgumentException('Unknown outcome');
				}

				if ($outcome === 'crash') {
					throw new \RuntimeException('db gone');
				}

				return ['ok' => true, 'taskId' => 't-1'];
			}
		);
		$context = ['uid' => 'agent-1', 'privileged' => true, 'admin' => false, 'service' => $service, 'logger' => $logger, 'reports' => $this->createMock(ConnectionReportService::class)];
		$answer = function (array $params, array $over = []) use ($context): array {
			$response = $this->controllerFor($params, $over + $context)->disposition('cm-1');
			return [$response->getStatus(), $response->getData()];
		};

		$this->assertSame([401, ['error' => 'Authentication required']], $answer(['subject' => 'a', 'outcome' => 'b'], ['uid' => null]));
		$this->assertSame(403, $answer(['subject' => 'a', 'outcome' => 'b'], ['privileged' => false])[0]);
		$this->assertSame([400, ['error' => 'subject and outcome are required']], $answer(['subject' => 'Vraag']));
		$this->assertSame([400, ['error' => 'subject and outcome are required']], $answer(['outcome' => 'done']));
		$this->assertSame([], $calls);

		$this->assertSame([200, ['ok' => true, 'taskId' => 't-1']], $answer(['subject' => 'Vraag', 'outcome' => 'done', 'notes' => 'n', 'queue' => 'q']));
		$this->assertSame([400, ['error' => 'Unknown outcome']], $answer(['subject' => 'Vraag', 'outcome' => 'bad']));
		$this->assertSame([500, ['error' => 'Disposition processing failed']], $answer(['subject' => 'Vraag', 'outcome' => 'crash']));
		$this->assertSame(
			[
				['process', 'cm-1', 'Vraag', 'done', 'n', 'q'],
				['process', 'cm-1', 'Vraag', 'bad', '', ''],
				['process', 'cm-1', 'Vraag', 'crash', '', ''],
				['error', 'CTI disposition failed', ['exception' => 'db gone']],
			],
			$calls
		);
	}//end testDispositionIsExact()
}//end class
