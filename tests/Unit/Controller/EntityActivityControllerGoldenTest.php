<?php

/**
 * Pins the answers of the entity activity endpoint, refused and allowed.
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

use InvalidArgumentException;
use OCA\Pipelinq\Controller\EntityActivityController;
use OCA\Pipelinq\Service\EntityActivityService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Every outcome of the activity endpoint: status, body, service calls and log lines.
 */
class EntityActivityControllerGoldenTest extends TestCase {

	/**
	 * The cases: signed in, entity type, query, what the service does, and the expected outcome.
	 *
	 * @return array<string, array{array<string, mixed>, array<string, mixed>}>
	 */
	public static function cases(): array {
		$ok = ['items' => [['id' => 'n-1']], 'total' => 1];
		return [
			'anonymous'          => [
				['user' => false, 'type' => 'client', 'query' => [], 'service' => $ok],
				['status' => 401, 'body' => ['message' => 'Authentication required'], 'calls' => []],
			],
			'anonymous bad type' => [
				['user' => false, 'type' => 'planet', 'query' => [], 'service' => $ok],
				['status' => 401, 'body' => ['message' => 'Authentication required'], 'calls' => []],
			],
			'unknown type'       => [
				['user' => true, 'type' => 'planet', 'query' => [], 'service' => $ok],
				['status' => 400, 'body' => ['message' => 'Invalid entity type'], 'calls' => [['allowed', 'planet']]],
			],
			'defaults'           => [
				['user' => true, 'type' => 'client', 'query' => [], 'service' => $ok],
				[
					'status' => 200,
					'body'   => $ok,
					'calls'  => [['allowed', 'client'], ['get', 'client', 'e-1', 'all', 1, 20]],
				],
			],
			'query'              => [
				['user' => true, 'type' => 'lead', 'query' => ['type' => 'note', '_page' => '3', '_limit' => '5'], 'service' => $ok],
				[
					'status' => 200,
					'body'   => $ok,
					'calls'  => [['allowed', 'lead'], ['get', 'lead', 'e-1', 'note', 3, 5]],
				],
			],
			'service refuses'    => [
				['user' => true, 'type' => 'contact', 'query' => [], 'service' => new InvalidArgumentException('no contact')],
				[
					'status' => 400,
					'body'   => ['message' => 'Invalid entity type'],
					'calls'  => [
						['allowed', 'contact'],
						['get', 'contact', 'e-1', 'all', 1, 20],
						['info', 'EntityActivityController: rejected request', 'no contact'],
					],
				],
			],
			'service fails'      => [
				['user' => true, 'type' => 'request', 'query' => [], 'service' => new RuntimeException('db gone')],
				[
					'status' => 500,
					'body'   => ['message' => 'Failed to load activity'],
					'calls'  => [
						['allowed', 'request'],
						['get', 'request', 'e-1', 'all', 1, 20],
						['error', 'EntityActivityController: failed to load activity', 'db gone'],
					],
				],
			],
		];
	}//end cases()

	/**
	 * Each case answers exactly as pinned.
	 *
	 * @param array<string, mixed> $given    The situation.
	 * @param array<string, mixed> $expected The outcome.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testOutcome(array $given, array $expected): void {
		$calls   = [];
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => $given['query'][$key] ?? $default
		);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($given['user'] === true ? $this->createMock(IUser::class) : null);
		$service = $this->createMock(EntityActivityService::class);
		$service->method('isAllowedEntityType')->willReturnCallback(
			function (string $entityType) use (&$calls): bool {
				$calls[] = ['allowed', $entityType];
				return in_array($entityType, ['client', 'contact', 'lead', 'request'], true);
			}
		);
		$service->method('getActivity')->willReturnCallback(
			function (string $entityType, string $entityId, string $type, int $page, int $limit) use (&$calls, $given): array {
				$calls[] = ['get', $entityType, $entityId, $type, $page, $limit];
				if ($given['service'] instanceof Throwable) {
					throw $given['service'];
				}

				return $given['service'];
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'error', 'warning', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use (&$calls, $level): void {
					$calls[] = [$level, $message, $context['exception']->getMessage()];
				}
			);
		}

		$controller = new EntityActivityController($request, $service, $session, $logger);
		$response   = $controller->index(entityType: $given['type'], entityId: 'e-1');

		$this->assertSame($expected['status'], $response->getStatus());
		$this->assertSame($expected['body'], $response->getData());
		$this->assertSame($expected['calls'], $calls);
	}//end testOutcome()
}//end class
