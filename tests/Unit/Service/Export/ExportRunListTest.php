<?php

/**
 * ExportRunService::listRuns: every filter and the order.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Export
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Export;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\Export\ExportRunService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * The job and status narrow the read; the dates narrow the result; newest first.
 */
class ExportRunListTest extends TestCase {

	/**
	 * Every filter: a blank job or status is not passed on; a set one is,
	 * under its stored name; a date range keeps runs started within it
	 * (bounds included) and drops runs without a start; runs come back
	 * newest first.
	 *
	 * @return void
	 */
	public function testEveryFilterAndTheOrder(): void {
		$calls = [];
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willReturnCallback(
			static function (array $config = []) use (&$calls): array {
				$calls[] = $config['filters'];
				return [
					['id' => 'a', 'startedAt' => '2026-03-02T10:00:00Z'],
					['id' => 'b', 'startedAt' => '2026-03-05T10:00:00Z'],
					['id' => 'c'],
					['id' => 'd', 'startedAt' => '2026-03-01T00:00:00Z'],
				];
			}
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => ['register' => 'r', 'exportRun_schema' => 's'][$key] ?? $default);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objects);
		$service = new ExportRunService($container, $appConfig, $objects);

		$this->assertSame(['b', 'a', 'd', 'c'], array_column($service->listRuns(filters: ['job_id' => '', 'status' => '']), 'id'));
		$this->assertSame(['b', 'a'], array_column($service->listRuns(filters: ['job_id' => 7, 'status' => 'failed', 'date_from' => '2026-03-02T10:00:00Z', 'date_to' => '2026-03-05T10:00:00Z']), 'id'));
		$this->assertSame(['a', 'd'], array_column($service->listRuns(filters: ['date_to' => '2026-03-03']), 'id'));
		$this->assertSame([['register' => 'r', 'schema' => 's'], ['register' => 'r', 'schema' => 's', 'jobId' => '7', 'status' => 'failed'], ['register' => 'r', 'schema' => 's']], $calls);
	}//end testEveryFilterAndTheOrder()
}//end class
