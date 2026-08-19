<?php

/**
 * Unit tests for ForecastExportService::exportSnapshots query pushdown.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\ForecastExportService;
use OCA\Pipelinq\Service\ForecastService;
use OCA\Pipelinq\Service\ReportingService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for ForecastExportService::exportSnapshots.
 *
 * @spec openspec/changes/pipelinq-query-pushdown-batch-1/tasks.md#task-8
 */
class ForecastExportServiceTest extends TestCase
{

    /**
     * The DI container mock.
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface $container;

    /**
     * The app config mock.
     *
     * @var IAppConfig&MockObject
     */
    private IAppConfig $appConfig;

    /**
     * Set up the test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->container = $this->createMock(ContainerInterface::class);
        $this->appConfig = $this->createMock(IAppConfig::class);

        $this->appConfig->method('getValueString')->willReturnCallback(
            static function (string $app, string $key, string $default): string {
                return match ($key) {
                    'register'                 => 'pipelinq',
                    'forecastSnapshot_schema'  => 'forecastSnapshot',
                    default                    => $default,
                };
            }
        );
    }//end setUp()

    /**
     * Build a fake OR ObjectService whose findAll honours sort/limit/offset and
     * whose count returns the full matching total (ignoring paging).
     *
     * @param array<int, array<string, mixed>> $rows Snapshot rows.
     *
     * @return object The fake ObjectService.
     */
    private function fakeObjectService(array $rows): object
    {
        return new class($rows) {
            /**
             * @param array<int, array<string, mixed>> $rows Rows.
             */
            public function __construct(private array $rows)
            {
            }//end __construct()

            /**
             * @param array<string, mixed> $config Config with filters/sort/limit/offset.
             *
             * @return array<int, array<string, mixed>> Page of matching rows.
             */
            public function findAll(array $config=[]): array
            {
                $out = $this->match(($config['filters'] ?? []));

                $sort = ($config['sort'] ?? []);
                foreach ($sort as $field => $direction) {
                    usort(
                        $out,
                        static function (array $a, array $b) use ($field, $direction): int {
                            $cmp = strcmp((string) ($a[$field] ?? ''), (string) ($b[$field] ?? ''));
                            return (strtoupper((string) $direction) === 'DESC') ? -$cmp : $cmp;
                        }
                    );
                }

                $offset = (int) ($config['offset'] ?? 0);
                $limit  = $config['limit'] ?? null;
                if ($offset > 0 || $limit !== null) {
                    $out = array_slice($out, $offset, $limit);
                }

                return $out;
            }//end findAll()

            /**
             * @param array<string, mixed> $config Config with `filters`.
             *
             * @return int Full matching count.
             */
            public function count(array $config=[]): int
            {
                return count($this->match(($config['filters'] ?? [])));
            }//end count()

            /**
             * @param array<string, mixed> $filters Filter map.
             *
             * @return array<int, array<string, mixed>> Matching rows.
             */
            private function match(array $filters): array
            {
                unset($filters['register'], $filters['schema']);
                $out = [];
                foreach ($this->rows as $row) {
                    foreach ($filters as $k => $v) {
                        if (($row[$k] ?? null) !== $v) {
                            continue 2;
                        }
                    }

                    $out[] = $row;
                }

                return $out;
            }//end match()
        };
    }//end fakeObjectService()

    /**
     * exportSnapshots returns the full total (server-side count) and a sorted
     * (as_of_date ASC) page window pushed down to OpenRegister.
     *
     * @return void
     */
    public function testExportSnapshotsSortsAscAndPagesWithServerTotal(): void
    {
        $rows = [
            ['owner_id' => 'r3', 'level' => 'rep', 'period_id' => 'p1', 'as_of_date' => '2026-03-01'],
            ['owner_id' => 'r1', 'level' => 'rep', 'period_id' => 'p1', 'as_of_date' => '2026-01-01'],
            ['owner_id' => 'r2', 'level' => 'rep', 'period_id' => 'p1', 'as_of_date' => '2026-02-01'],
            ['owner_id' => 'r4', 'level' => 'rep', 'period_id' => 'p1', 'as_of_date' => '2026-04-01'],
            // Different level — excluded by the filter, must not inflate total.
            ['owner_id' => 't1', 'level' => 'team', 'period_id' => 'p1', 'as_of_date' => '2026-01-15'],
        ];

        $this->container->method('get')->willReturn($this->fakeObjectService($rows));

        $service = new ForecastExportService(
            $this->container,
            $this->appConfig,
            $this->createMock(ForecastService::class),
            $this->createMock(ReportingService::class),
            $this->createMock(LoggerInterface::class),
        );

        $result = $service->exportSnapshots(periodId: 'p1', level: 'rep', ownerId: null, limit: 2, offset: 1);

        // Total counts all 4 rep snapshots, not just the page.
        $this->assertSame(4, $result['total']);
        $this->assertSame(2, $result['limit']);
        $this->assertSame(1, $result['offset']);

        // Sorted as_of_date ASC = r1, r2, r3, r4; page (offset 1, limit 2) = r2, r3.
        $this->assertCount(2, $result['snapshots']);
        $this->assertSame('r2', $result['snapshots'][0]['owner_id']);
        $this->assertSame('r3', $result['snapshots'][1]['owner_id']);
        $this->assertSame('2026-02-01', $result['snapshots'][0]['as_of_date']);
    }//end testExportSnapshotsSortsAscAndPagesWithServerTotal()
}//end class
