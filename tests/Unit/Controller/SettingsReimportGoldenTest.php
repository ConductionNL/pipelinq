<?php

/**
 * Pins every answer of re-importing the configuration.
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
 * @spec openspec/specs/admin-settings/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\SettingsController;
use OCA\Pipelinq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Clean import, rejected schemas, a failing import and a failing settings read.
 */
class SettingsReimportGoldenTest extends TestCase {

	/**
	 * Runs one re-import.
	 *
	 * @param callable $load     What loadSettings does.
	 * @param callable $settings What getSettings does.
	 *
	 * @return array<string, mixed>
	 */
	private function reimport(callable $load, callable $settings): array {
		$calls   = [];
		$service = $this->createMock(SettingsService::class);
		$service->method('loadSettings')->willReturnCallback(
			function (bool $force) use (&$calls, $load): array {
				$calls[] = ['load', $force];
				return $load();
			}
		);
		$service->method('getSettings')->willReturnCallback(
			function () use (&$calls, $settings): array {
				$calls[] = ['settings'];
				return $settings();
			}
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $args = []): string => $text.($args === [] ? '' : ' '.json_encode($args)));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context = []) use (&$calls): void {
				$calls[] = ['error', $message, $context['exception']->getMessage()];
			}
		);
		$controller = new SettingsController(
			request: $this->createMock(IRequest::class),
			container: $this->createMock(ContainerInterface::class),
			appManager: $this->createMock(IAppManager::class),
			groupManager: $this->createMock(IGroupManager::class),
			settingsService: $service,
			userSession: $this->createMock(IUserSession::class),
			l10n: $l10n,
			logger: $logger,
		);
		$response = $controller->reimport();
		return ['status' => $response->getStatus(), 'body' => $response->getData(), 'calls' => $calls];
	}//end reimport()

	/**
	 * Every outcome answers exactly as pinned.
	 *
	 * @return void
	 */
	public function testEveryOutcome(): void {
		$config = static fn (): array => ['register' => '18'];
		$this->assertSame(
			[
				'status' => 200,
				'body'   => [
					'success' => true,
					'message' => 'Configuration re-imported successfully',
					'config'  => ['register' => '18'],
					'result'  => ['registers' => 0, 'schemas' => 2, 'rejectedSchemas' => []],
				],
				'calls'  => [['load', true], ['settings']],
			],
			$this->reimport(static fn (): array => ['schemas' => [['id' => 1], ['id' => 2]]], $config)
		);

		$rejected = $this->reimport(
			static fn (): array => [
				'registers' => [['id' => 18]],
				'failed'    => ['schemas' => [['key' => 'a', 'slug' => 'survey', 'error' => 'no slug'], ['slug' => 'party']]],
			],
			$config
		);
		$this->assertSame(200, $rejected['status']);
		$this->assertFalse($rejected['body']['success']);
		$this->assertSame('Configuration re-imported, but OpenRegister rejected %1$s schema(s): %2$s [2,"survey, party"]', $rejected['body']['message']);
		$this->assertSame(['registers' => 1, 'schemas' => 0], array_slice($rejected['body']['result'], 0, 2));
		$this->assertSame(['survey', 'party'], array_column($rejected['body']['result']['rejectedSchemas'], 'slug'));

		$failure = ['success' => false, 'message' => 'An unexpected error occurred', 'error' => 'disk full'];
		$this->assertSame(
			['status' => 500, 'body' => $failure, 'calls' => [['load', true], ['error', 'SettingsController::reimport failed', 'disk full']]],
			$this->reimport(static fn (): array => throw new RuntimeException('disk full'), $config)
		);
		$this->assertSame(
			['status' => 500, 'body' => $failure, 'calls' => [['load', true], ['settings'], ['error', 'SettingsController::reimport failed', 'disk full']]],
			$this->reimport(static fn (): array => [], static fn (): array => throw new RuntimeException('disk full'))
		);
	}//end testEveryOutcome()
}//end class
