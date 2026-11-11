<?php

/**
 * Pins every answer of click-to-dial: the CRM gate, the dial-string checks and the originate.
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
}//end class
