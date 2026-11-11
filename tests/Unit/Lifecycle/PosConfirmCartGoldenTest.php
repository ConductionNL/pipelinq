<?php

/**
 * Pins the confirm guard's answer across access, transaction id, config and cart lookup shapes.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/pos-lifecycle-guard-adoption/tasks.md#2.3
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Lifecycle;

use OCA\OpenRegister\Db\SchemaMapper;
use OCA\Pipelinq\Lifecycle\PosAccessPolicy;
use OCA\Pipelinq\Lifecycle\PosTransactionConfirmGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

require_once __DIR__ . '/PosTransactionConfirmGuardPhase0Test.php';

/**
 * Every combination, with the verdict, its message, the lookups and the log lines.
 */
class PosConfirmCartGoldenTest extends TestCase {

	/**
	 * Runs one combination.
	 *
	 * @param array<string, mixed> $given access, object, config, mapper and cart shape.
	 *
	 * @return array<string, mixed>
	 */
	private function outcome(array $given): array {
		$calls     = [];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use (&$calls, $given): string {
				$calls[] = ['config', $key];
				return $given['config'][$key] ?? $default;
			}
		);
		$policy = $this->createMock(PosAccessPolicy::class);
		$policy->method('canAccessTransaction')->willReturnCallback(
			function (array $object, string $userId) use (&$calls, $given): bool {
				$calls[] = ['access', $userId];
				return $given['access'];
			}
		);
		$objects = new class($given['cart']) {
			/**
			 * Calls seen.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $seen = [];

			/**
			 * Constructor.
			 *
			 * @param mixed $cart Rows to return, or 'throw'.
			 */
			public function __construct(private mixed $cart) {
			}

			/**
			 * Record and answer.
			 *
			 * @param array<string, mixed> $config The query.
			 *
			 * @return array<int, mixed>|null
			 */
			public function findAll(array $config): ?array {
				$this->seen[] = $config;
				if ($this->cart === 'throw') {
					throw new RuntimeException('or down');
				}

				return $this->cart;
			}
		};
		$mapper    = new ConfirmGuardFakeSchemaMapper($given['mapper']);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use (&$calls, $objects, $mapper) {
				$calls[] = ['container', $id];
				return $id === SchemaMapper::class ? $mapper : $objects;
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'error', 'info', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use (&$calls, $level): void {
					$calls[] = [$level, $message, $context];
				}
			);
		}

		$guard  = new PosTransactionConfirmGuard(policy: $policy, container: $container, appConfig: $appConfig, logger: $logger);
		$result = $guard->check(object: $given['object'], action: 'confirm', userId: 'kassa-1');
		return ['allowed' => $result->isAllowed(), 'message' => $result->getMessage(), 'calls' => $calls, 'queries' => $objects->seen];
	}//end outcome()

	/**
	 * The whole matrix answers exactly as the fixture pins.
	 *
	 * @return void
	 */
	public function testMatrix(): void {
		$objects = ['no id' => [], 'uuid' => ['uuid' => 'tx-u'], 'id' => ['id' => 'tx-i', 'uuid' => 'tx-u']];
		$configs = [
			'configured'  => [['register' => '5', 'posTransactionLine_schema' => '9'], []],
			'by slug'     => [['register' => '5'], ['posTransactionLine' => 12]],
			'no slug'     => [['register' => '5'], []],
			'no register' => [['posTransactionLine_schema' => '9'], []],
		];
		$carts  = ['lines' => [['id' => 'l-1']], 'empty' => [], 'null' => null, 'throws' => 'throw'];
		$actual = [];
		foreach ([true, false] as $access) {
			foreach ($objects as $o => $object) {
				foreach ($configs as $c => [$config, $mapper]) {
					foreach ($carts as $k => $cart) {
						$name = ($access === true ? 'allowed' : 'denied').' '.$o.' '.$c.' '.$k;
						$actual[$name] = $this->outcome(['access' => $access, 'object' => $object, 'config' => $config, 'mapper' => $mapper, 'cart' => $cart]);
					}
				}
			}
		}

		$path = __DIR__.'/Fixtures/pos-confirm-cart.json';
		$this->assertSame(json_decode((string)file_get_contents($path), true), json_decode((string)json_encode($actual), true));
	}//end testMatrix()
}//end class
