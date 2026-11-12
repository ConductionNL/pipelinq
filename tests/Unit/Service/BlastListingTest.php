<?php

/**
 * Characterisation tests for the BlastService listings and loaders.
 *
 * Pins the two paged listings the Blast REST controller answers from
 * (page and size clamping, the status filter, the count and the page
 * query pushed to OpenRegister, the envelope) and the failure paths of
 * the loaders, so they can be restructured without changing them.
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

use OCA\Pipelinq\Service\BlastService;
use OCA\Pipelinq\Service\Marketing\MailTransportService;
use OCA\Pipelinq\Service\SegmentService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the Blast listings.
 */
class BlastListingTest extends TestCase {

	/**
	 * The calls made to OpenRegister, as [method, config].
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $calls = [];

	/**
	 * Warnings logged, as [message, context].
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $warnings = [];

	/**
	 * The rows findAll answers.
	 *
	 * @var array<int, mixed>
	 */
	private array $rows = [];

	/**
	 * The total count answers.
	 *
	 * @var integer
	 */
	private int $total = 0;

	/**
	 * Whether findAll and count throw.
	 *
	 * @var boolean
	 */
	private bool $fails = false;

	/**
	 * The blast list clamps the page and size, filters on a status, counts
	 * and asks for the newest page.
	 *
	 * @return void
	 */
	public function testTheBlastListQueryAndEnvelope(): void {
		$this->rows = [['id' => 'b-1'], ['id' => 'b-2']];
		$this->total = 45;

		$envelope = $this->service()->listBlasts('sent', 3, 20);

		$this->assertSame(['data' => [['id' => 'b-1'], ['id' => 'b-2']], 'pagination' => ['page' => 3, 'limit' => 20, 'total' => 45, 'pages' => 3]], $envelope);
		$this->assertSame(
			[
				['count', ['filters' => ['register' => 'reg', 'schema' => 'blast-s', 'status' => 'sent']]],
				['findAll', ['filters' => ['register' => 'reg', 'schema' => 'blast-s', 'status' => 'sent'], 'limit' => 20, 'offset' => 40, 'sort' => ['createdAt' => 'desc']]],
			],
			$this->calls
		);

		$this->calls = [];
		$this->service()->listBlasts('', -2, 500);
		$this->assertSame(
			[
				['count', ['filters' => ['register' => 'reg', 'schema' => 'blast-s']]],
				['findAll', ['filters' => ['register' => 'reg', 'schema' => 'blast-s'], 'limit' => 100, 'offset' => 0, 'sort' => ['createdAt' => 'desc']]],
			],
			$this->calls
		);

		$this->total = 0;
		$this->assertSame(['page' => 1, 'limit' => 1, 'total' => 0, 'pages' => 0], $this->service()->listBlasts(null, 0, 0)['pagination']);
	}//end testTheBlastListQueryAndEnvelope()

	/**
	 * The delivery list of a blast pages its deliveries; no blast id answers
	 * an empty page without asking anything.
	 *
	 * @return void
	 */
	public function testTheDeliveryListQueryAndEnvelope(): void {
		$this->rows = [['id' => 'd-1']];
		$this->total = 7;

		$envelope = $this->service()->listDeliveriesForBlast('b-1', 2, 5);

		$this->assertSame(['data' => [['id' => 'd-1']], 'pagination' => ['page' => 2, 'limit' => 5, 'total' => 7, 'pages' => 2]], $envelope);
		$this->assertSame(
			[
				['count', ['filters' => ['register' => 'reg', 'schema' => 'delivery-s', 'blastId' => 'b-1']]],
				['findAll', ['filters' => ['register' => 'reg', 'schema' => 'delivery-s', 'blastId' => 'b-1'], 'limit' => 5, 'offset' => 5]],
			],
			$this->calls
		);

		$this->calls = [];
		$this->assertSame(
			['data' => [], 'pagination' => ['page' => 1, 'limit' => 100, 'total' => 0, 'pages' => 0]],
			$this->service()->listDeliveriesForBlast('', 0, 900)
		);
		$this->assertSame([], $this->calls);
	}//end testTheDeliveryListQueryAndEnvelope()

	/**
	 * A delivery load without a page asks for every matching row.
	 *
	 * @return void
	 */
	public function testAnUnpagedDeliveryLoadAsksForEveryRow(): void {
		$this->service()->transitionQueuedDeliveries('c-1', 'b-1');

		$this->assertSame(
			[['findAll', ['filters' => ['register' => 'reg', 'schema' => 'delivery-s', 'blastId' => 'b-1', 'contactId' => 'c-1', 'status' => 'queued']]]],
			$this->calls
		);
	}//end testAnUnpagedDeliveryLoadAsksForEveryRow()

	/**
	 * A failing count or load is logged and gives none.
	 *
	 * @return void
	 */
	public function testFailingQueriesAreLoggedAndGiveNone(): void {
		$this->fails = true;

		$this->assertSame(['data' => [], 'pagination' => ['page' => 1, 'limit' => 10, 'total' => 0, 'pages' => 0]], $this->service()->listBlasts('draft', 1, 10));
		$this->assertSame(['data' => [], 'pagination' => ['page' => 1, 'limit' => 10, 'total' => 0, 'pages' => 0]], $this->service()->listDeliveriesForBlast('b-1', 1, 10));

		$this->assertSame(
			[
				['BlastService.countObjects: count failed', ['schema' => 'blast-s', 'exception' => 'store down']],
				['BlastService.loadObjects: findAll failed', ['schema' => 'blast-s', 'exception' => 'store down']],
				['BlastService.countDeliveries: count failed', ['filters' => ['blastId' => 'b-1'], 'exception' => 'store down']],
				['BlastService.loadDeliveries: findAll failed', ['filters' => ['blastId' => 'b-1'], 'exception' => 'store down']],
			],
			$this->warnings
		);
	}//end testFailingQueriesAreLoggedAndGiveNone()

	/**
	 * The service over a recording ObjectService.
	 *
	 * @return BlastService
	 */
	private function service(): BlastService {
		$store = new class ($this) {
			/**
			 * Constructor.
			 *
			 * @param BlastListingTest $test The test answering the calls.
			 */
			public function __construct(private BlastListingTest $test) {
			}

			/**
			 * Find rows.
			 *
			 * @param array<string, mixed> $config The query.
			 *
			 * @return array<int, mixed>
			 */
			public function findAll(array $config = []): array {
				return $this->test->answerFindAll(config: $config);
			}

			/**
			 * Count rows.
			 *
			 * @param array<string, mixed> $config The query.
			 *
			 * @return int
			 */
			public function count(array $config = []): int {
				return $this->test->answerCount(config: $config);
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($store);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => (['register' => 'reg', 'blast_schema' => 'blast-s', 'blastDelivery_schema' => 'delivery-s'][$key] ?? $default)
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->warnings[] = [$message, $context];
			}
		);

		return new BlastService($container, $appConfig, $this->createMock(SegmentService::class), $this->createMock(MailTransportService::class), $logger);
	}//end service()

	/**
	 * Record and answer a findAll.
	 *
	 * @param array<string, mixed> $config The query.
	 *
	 * @return array<int, mixed>
	 */
	public function answerFindAll(array $config): array {
		$this->calls[] = ['findAll', $config];
		if ($this->fails === true) {
			throw new RuntimeException('store down');
		}

		return $this->rows;
	}//end answerFindAll()

	/**
	 * Record and answer a count.
	 *
	 * @param array<string, mixed> $config The query.
	 *
	 * @return int
	 */
	public function answerCount(array $config): int {
		$this->calls[] = ['count', $config];
		if ($this->fails === true) {
			throw new RuntimeException('store down');
		}

		return $this->total;
	}//end answerCount()
}//end class
