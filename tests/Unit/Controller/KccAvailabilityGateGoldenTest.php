<?php

/**
 * Pins every answer of the availability toggle across caller, parameter and save outcome.
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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\KccWerkplekController;
use OCA\Pipelinq\Service\KccWerkplekService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Caller × parameter × save outcome, with status, body, saves and log lines.
 */
class KccAvailabilityGateGoldenTest extends TestCase {

	/**
	 * Runs one combination.
	 *
	 * @param array<string, mixed> $given The caller, the parameter (absent when the key is missing) and whether the save fails.
	 *
	 * @return array<string, mixed>
	 */
	private function outcome(array $given): array {
		$calls   = [];
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => array_key_exists('param', $given) === true && $key === 'isAvailable' ? $given['param'] : $default
		);
		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($given['uid'] !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($given['uid']);
		}

		$session->method('getUser')->willReturn($user);
		$service = $this->createMock(KccWerkplekService::class);
		$service->method('setAvailability')->willReturnCallback(
			function (string $uid, bool $available) use (&$calls, $given): array {
				$calls[] = ['save', $uid, $available];
				if ($given['fails'] === true) {
					throw new RuntimeException('disk full');
				}

				return ['userId' => $uid, 'isAvailable' => $available];
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['error', 'warning', 'info', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use (&$calls, $level): void {
					$calls[] = [$level, $message, $context];
				}
			);
		}

		$response = (new KccWerkplekController($request, $service, $session, $logger, null))->setAvailabilityAction();
		return ['status' => $response->getStatus(), 'body' => $response->getData(), 'calls' => $calls];
	}//end outcome()

	/**
	 * The whole matrix answers exactly as pinned.
	 *
	 * @return void
	 */
	public function testMatrix(): void {
		$params = ['absent' => [], 'null' => ['param' => null], 'maybe' => ['param' => 'maybe'], 'TRUE' => ['param' => 'TRUE'], 'zero' => ['param' => 0]];
		$actual = [];
		foreach (['anonymous' => null, 'agent' => 'agent-7'] as $who => $uid) {
			foreach ($params as $name => $param) {
				foreach ([false, true] as $fails) {
					$actual[$who.' '.$name.($fails === true ? ' failing' : '')] = $this->outcome(array_merge(['uid' => $uid, 'fails' => $fails], $param));
				}
			}
		}

		$unauth   = ['status' => 401, 'body' => ['message' => 'Authentication required'], 'calls' => []];
		$required = ['status' => 400, 'body' => ['message' => 'isAvailable is required'], 'calls' => []];
		$boolean  = ['status' => 400, 'body' => ['message' => 'isAvailable must be a boolean'], 'calls' => []];
		$saved    = static fn (bool $available): array => [
			'status' => 200,
			'body'   => ['userId' => 'agent-7', 'isAvailable' => $available],
			'calls'  => [['save', 'agent-7', $available]],
		];
		$failed   = static fn (bool $available): array => [
			'status' => 500,
			'body'   => ['message' => 'Operation failed'],
			'calls'  => [
				['save', 'agent-7', $available],
				['error', '[KccWerkplekController] setAvailability failed', ['error' => 'disk full']],
			],
		];
		$expected = [];
		foreach (array_keys($params) as $name) {
			$expected['anonymous '.$name] = $unauth;
			$expected['anonymous '.$name.' failing'] = $unauth;
		}

		$expected += [
			'agent absent'         => $required,
			'agent absent failing' => $required,
			'agent null'           => $required,
			'agent null failing'   => $required,
			'agent maybe'          => $boolean,
			'agent maybe failing'  => $boolean,
			'agent TRUE'           => $saved(true),
			'agent TRUE failing'   => $failed(true),
			'agent zero'           => $saved(false),
			'agent zero failing'   => $failed(false),
		];
		$this->assertSame($expected, $actual);
	}//end testMatrix()
}//end class
