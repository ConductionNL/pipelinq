<?php

/**
 * Pins every answer of deleting a contactmoment, refused and allowed.
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
 * @spec openspec/specs/contactmomenten/spec.md#requirement-contactmoment-update-and-deletion
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\ContactmomentController;
use OCA\Pipelinq\Service\ContactmomentService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\NotPermittedException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use TypeError;

/**
 * Caller × delete outcome, with status, body, delete calls and log lines.
 */
class ContactmomentDestroyGoldenTest extends TestCase {

	/**
	 * Runs one deletion.
	 *
	 * @param string|null    $uid     The signed-in uid, or null.
	 * @param Throwable|null $failure What the delete throws, or null.
	 *
	 * @return array<string, mixed>
	 */
	private function outcome(?string $uid, ?Throwable $failure): array {
		$calls   = [];
		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);
		$service = $this->createMock(ContactmomentService::class);
		$service->method('delete')->willReturnCallback(
			function (...$args) use (&$calls, $failure): bool {
				$calls[] = ['delete', $args];
				if ($failure !== null) {
					throw $failure;
				}

				return true;
			}
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => '['.$text.']');
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['error', 'warning', 'info', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use (&$calls, $level): void {
					$calls[] = [$level, $message, $context['exception']->getMessage()];
				}
			);
		}

		$controller = new ContactmomentController($this->createMock(IRequest::class), $service, $session, $l10n, $logger);
		try {
			$response = $controller->destroy('cm-1');
		} catch (Throwable $e) {
			return ['thrown' => get_class($e), 'calls' => $calls];
		}

		return ['status' => $response->getStatus(), 'body' => $response->getData(), 'calls' => $calls];
	}//end outcome()

	/**
	 * The whole matrix answers exactly as pinned.
	 *
	 * @return void
	 */
	public function testMatrix(): void {
		$failures = [
			'ok'         => null,
			'missing'    => new DoesNotExistException('gone'),
			'forbidden'  => new NotPermittedException('not yours'),
			'broken'     => new RuntimeException('db down'),
			'type error' => new TypeError('bad type'),
		];
		$actual = [];
		foreach (['anonymous' => null, 'agent' => 'agent-3'] as $who => $uid) {
			foreach ($failures as $name => $failure) {
				$actual[$who.' '.$name] = $this->outcome(uid: $uid, failure: $failure);
			}
		}

		$delete   = ['delete', ['cm-1', 'agent-3']];
		$expected = [];
		foreach (array_keys($failures) as $name) {
			$expected['anonymous '.$name] = ['status' => 401, 'body' => ['error' => '[Authentication required]'], 'calls' => []];
		}

		$expected += [
			'agent ok'         => ['status' => 200, 'body' => ['success' => true], 'calls' => [$delete]],
			'agent missing'    => ['status' => 404, 'body' => ['error' => '[Contactmoment not found]'], 'calls' => [$delete]],
			'agent forbidden'  => ['status' => 403, 'body' => ['error' => '[You do not have permission to delete this contactmoment]'], 'calls' => [$delete]],
			'agent broken'     => [
				'status' => 500,
				'body'   => ['error' => '[Operation failed]'],
				'calls'  => [$delete, ['error', 'ContactmomentController::destroy failed', 'db down']],
			],
			'agent type error' => ['thrown' => TypeError::class, 'calls' => [$delete]],
		];
		$this->assertSame($expected, $actual);
	}//end testMatrix()
}//end class
