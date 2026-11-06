<?php

/**
 * Golden test for a competitor watch pass.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Competitor
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-a-watch-runs-on-an-openregister-flow-schedule-and-never-on-a-job-of-ours
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Competitor;

use OCA\Pipelinq\Service\Competitor\CompetitorWatchService;
use OCA\Pipelinq\Service\Competitor\FediverseWatchReader;
use OCA\Pipelinq\Service\Competitor\FeedWatchReader;
use OCA\Pipelinq\Service\Competitor\PageWatchReader;
use OCA\Pipelinq\Service\Competitor\RelevanceScorer;
use OCA\Pipelinq\Service\Competitor\SearchWatchReader;
use OCA\Pipelinq\Service\Competitor\SitemapWatchReader;
use OCA\Pipelinq\Service\Competitor\WatchOutcome;
use OCA\Pipelinq\Service\Marketing\ListObjectStore;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

/**
 * Every call a watch pass makes on its readers, the scorer, the register and
 * the logger, and what the pass returns, compared case by case with
 * CompetitorWatchRunGolden.json. Set COMPETITOR_WATCH_GOLDEN_RECORD=1 to
 * rewrite the file, then run once more without it.
 *
 * @covers \OCA\Pipelinq\Service\Competitor\CompetitorWatchService
 * @covers \OCA\Pipelinq\Service\Competitor\UrlWatchReaders
 * @covers \OCA\Pipelinq\Service\Competitor\WatchEventStore
 * @covers \OCA\Pipelinq\Service\Competitor\WatchReaders
 * @uses   \OCA\Pipelinq\Service\Competitor\WatchOutcome
 * @uses   \OCA\Pipelinq\Service\Marketing\ListObjectStore
 * @uses \OCA\Pipelinq\Util\EntityPayload
 * @uses \OCA\Pipelinq\Util\PayloadToArray
 * @uses \OCA\Pipelinq\Util\SerialisedPayload
 * @uses \OCA\Pipelinq\Util\ObjectToPlainArray
 * @uses \OCA\Pipelinq\Util\AnyPayloadToArray
 * @uses \OCA\Pipelinq\Util\CastingPayloadToArray
 * @uses \OCA\Pipelinq\Util\NullablePayloadToArray
 * @uses \OCA\Pipelinq\Util\OptionalPayloadToArray
 * @uses \OCA\Pipelinq\Util\SerialisedToArray
 *
 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-a-watch-runs-on-an-openregister-flow-schedule-and-never-on-a-job-of-ours
 */
class CompetitorWatchRunGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/CompetitorWatchRunGolden.json';

	private const NOW = 1767225600;

	/**
	 * Calls made in the current case, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $calls = [];

	/**
	 * Run every case and compare it with the golden file.
	 *
	 * @return void
	 */
	public function testGolden(): void {
		$actual = [];
		foreach ($this->cases() as $name => $case) {
			$actual[$name] = $this->runCase(case: $case);
		}

		if (getenv('COMPETITOR_WATCH_GOLDEN_RECORD') !== false) {
			file_put_contents(
				self::GOLDEN,
				json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n"
			);
			$this->markTestSkipped('recorded');
		}

		$expected = json_decode((string)file_get_contents(self::GOLDEN), true);
		foreach ($expected as $name => $want) {
			$this->assertSame($want, ($actual[$name] ?? null), $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testGolden()

	/**
	 * The cases: the stored watches and events, what each reader answers, and the call to make.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		$hourAgo = gmdate('Y-m-d\TH:i:s\Z', (self::NOW - 3600));
		$watches = [
			['id' => 'w-rss', 'kind' => 'rss', 'target' => ' https://rival.example/feed ', 'connectorSourceId' => ' src-1 ', 'competitorId' => 'c-1'],
			['uuid' => 'w-map', 'kind' => 'sitemap', 'target' => 'https://rival.example/sitemap.xml', 'schedule' => 'hourly', '@self' => ['x' => 1]],
			['id' => 'w-recent', 'kind' => 'rss', 'target' => 'https://x.example', 'lastRunAt' => $hourAgo],
			['id' => 'w-page', 'kind' => 'page', 'target' => 'https://rival.example/prices', 'selector' => ' .price ', 'fingerprint' => ' fp-0 ', 'lineFingerprints' => [1, 'b']],
			['id' => 'w-fedi', 'kind' => 'fediverse', 'target' => ' @rival@mastodon.example ', 'connectorSourceId' => ' src-f '],
			['id' => 'w-search', 'kind' => 'search', 'target' => ' rival crm '],
			['id' => 'w-odd', 'kind' => 'carrier-pigeon', 'target' => 'roof'],
			['id' => 'w-throw', 'kind' => 'rss', 'target' => 'https://down.example/feed'],
			['id' => 'w-fail', 'kind' => 'page', 'target' => 'https://gone.example'],
		];
		$events = [
			['id' => 'e-1', 'watchId' => 'w-map', 'url' => ' https://rival.example/a ', 'sourceStamp' => ' 2026-01-01 ', 'seenAt' => '2025-12-01T00:00:00Z', 'relevanceScore' => 7],
			['id' => 'e-2', 'watchId' => 'w-map', 'url' => '', 'sourceStamp' => 'skip'],
			['id' => 'e-3', 'watchId' => 'w-map', 'url' => 'https://rival.example/b'],
			['id' => 'e-4', 'watchId' => 'w-rss', 'url' => 'https://rival.example/post-1', 'seenAt' => '2025-11-01T00:00:00Z'],
		];
		$answers = [
			'https://rival.example/feed' => ['seen', [['url' => 'https://rival.example/post-1', 'title' => 'One'], ['url' => 'https://rival.example/post-2', 'summary' => 'Two'], ['title' => 'no url']]],
			'https://rival.example/sitemap.xml' => ['seen', [['url' => 'https://rival.example/c', 'stamp' => 's-c']]],
			'https://rival.example/prices' => ['seen', [['url' => 'https://rival.example/prices', 'summary' => 'changed']], ['fingerprint' => 'fp-1', 'lineFingerprints' => ['l1'], 'other' => 'x']],
			'@rival@mastodon.example' => ['seen', []],
			'rival crm' => ['seen', [['url' => 'https://news.example/rival', 'title' => str_repeat('t', 510)]]],
			'https://down.example/feed' => ['throw', 'feed host is down'],
			'https://gone.example' => ['failed', 'not_found', str_repeat('r', 520)],
		];
		$base = ['watches' => $watches, 'events' => $events, 'answers' => $answers];

		$cases = [
			'run due, no identity' => [...$base, 'call' => ['runDue', []]],
			'run due, limit two, acting user' => [...$base, 'call' => ['runDue', ['limit' => 2, 'actingUserId' => 'admin']]],
			'due now, limit three' => [...$base, 'call' => ['dueNow', ['limit' => 3]]],
			'run one sitemap without id' => [...$base, 'call' => ['runOne', ['watch' => ['kind' => 'sitemap', 'target' => 'https://rival.example/sitemap.xml']]]],
			'run one without kind' => [...$base, 'call' => ['runOne', ['watch' => ['id' => 'w-none', 'target' => 'x']]]],
		];
		foreach ($watches as $watch) {
			$cases['run one ' . ($watch['id'] ?? $watch['uuid'])] = [...$base, 'call' => ['runOne', ['watch' => $watch, 'actingUserId' => 'u-7']]];
		}

		return $cases;
	}//end cases()

	/**
	 * Run one case against a freshly wired service.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<string, mixed>
	 */
	private function runCase(array $case): array {
		$this->calls = [];
		$service = $this->build(class: CompetitorWatchService::class, doubles: $this->doubles(case: $case));
		[$method, $args] = $case['call'];
		try {
			$result = ['result' => $service->{$method}(...$args)];
		} catch (\Throwable $e) {
			$result = ['error' => get_class($e) . ': ' . $e->getMessage()];
		}

		return [...$result, 'calls' => $this->calls];
	}//end runCase()

	/**
	 * The doubles every class in the graph is built from, by type.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<string, object>
	 */
	private function doubles(array $case): array {
		$store = $this->getMockBuilder(ListObjectStore::class)
			->disableOriginalConstructor()
			->onlyMethods(['schemaSlug', 'find', 'findAll', 'save'])
			->getMock();
		$store->method('schemaSlug')->willReturnCallback(
			fn (string $configKey, string $default): string => $this->record(call: 'store.schemaSlug', args: [$configKey, $default], result: 'slug-' . $default)
		);
		$store->method('findAll')->willReturnCallback(
			fn (string $schemaSlug, array $filters = []): array => $this->record(call: 'store.findAll', args: [$schemaSlug, $filters], result: $this->select(case: $case, schemaSlug: $schemaSlug, filters: $filters))
		);
		$store->method('save')->willReturnCallback(
			fn (string $schemaSlug, array $payload, ?string $id = null): array => $this->record(call: 'store.save', args: [$schemaSlug, $payload, $id], result: [...$payload, 'id' => ($id ?? 'new-id')])
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'info', 'error', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->record(call: 'logger.' . $level, args: [$message, $context], result: null);
				}
			);
		}

		$scorer = $this->createMock(RelevanceScorer::class);
		$scorer->method('fieldsFor')->willReturnCallback(
			fn (array $item, ?string $actingUserId = null): array => $this->record(call: 'scorer.fieldsFor', args: [$item, $actingUserId], result: ['relevanceScore' => strlen((string)($item['url'] ?? '')) % 10, 'scoredFor' => $actingUserId])
		);

		$doubles = [
			ListObjectStore::class => $store,
			ITimeFactory::class => $time,
			LoggerInterface::class => $logger,
			RelevanceScorer::class => $scorer,
		];
		$readers = [
			FeedWatchReader::class => 'feeds',
			SitemapWatchReader::class => 'sitemaps',
			PageWatchReader::class => 'pages',
			FediverseWatchReader::class => 'fediverse',
			SearchWatchReader::class => 'searches',
		];
		foreach ($readers as $class => $label) {
			$reader = $this->createMock($class);
			$reader->method('read')->willReturnCallback(
				fn (mixed ...$args): WatchOutcome => $this->answer(case: $case, call: 'reader.' . $label, args: $args)
			);
			$doubles[$class] = $reader;
		}

		return $doubles;
	}//end doubles()

	/**
	 * Build a class by its constructor's parameter types: a double when one
	 * is given, otherwise the real class built the same way.
	 *
	 * @param string $class The class.
	 * @param array<string, object> $doubles Doubles by type.
	 *
	 * @return object
	 */
	private function build(string $class, array $doubles): object {
		$args = [];
		foreach ((new ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
			$type = $parameter->getType();
			if (($type instanceof ReflectionNamedType) === false) {
				throw new RuntimeException('untyped parameter ' . $parameter->getName());
			}

			$args[$parameter->getName()] = ($doubles[$type->getName()] ?? $this->build(class: $type->getName(), doubles: $doubles));
		}

		return new $class(...$args);
	}//end build()

	/**
	 * The stored objects a findAll answers with.
	 *
	 * @param array<string, mixed> $case The case.
	 * @param string $schemaSlug The schema.
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function select(array $case, string $schemaSlug, array $filters): array {
		$rows = $case['events'];
		if (str_contains($schemaSlug, 'Watch') === true && str_contains($schemaSlug, 'Event') === false) {
			$rows = $case['watches'];
		}

		return array_values(
			array_filter(
				$rows,
				static function (array $row) use ($filters): bool {
					foreach ($filters as $key => $value) {
						if (trim((string)($row[$key] ?? '')) !== $value) {
							return false;
						}
					}

					return true;
				}
			)
		);
	}//end select()

	/**
	 * A reader's answer for the target it was given.
	 *
	 * @param array<string, mixed> $case The case.
	 * @param string $call The reader.
	 * @param array<int, mixed> $args The arguments it was called with.
	 *
	 * @return WatchOutcome
	 */
	private function answer(array $case, string $call, array $args): WatchOutcome {
		$this->record(call: $call, args: $args, result: null);
		$answer = ($case['answers'][$args[0]] ?? ['seen', [['url' => 'https://default.example/' . $call]]]);
		if ($answer[0] === 'throw') {
			throw new RuntimeException($answer[1]);
		}

		if ($answer[0] === 'failed') {
			return WatchOutcome::failed(outcome: $answer[1], reason: $answer[2]);
		}

		return WatchOutcome::seen(items: $answer[1], state: ($answer[2] ?? []));
	}//end answer()

	/**
	 * Record a call and pass its result through.
	 *
	 * @param string $call What was called.
	 * @param array<int, mixed> $args The arguments.
	 * @param mixed $result The answer.
	 *
	 * @return mixed
	 */
	private function record(string $call, array $args, mixed $result): mixed {
		$this->calls[] = ['call' => $call, 'args' => $args];

		return $result;
	}//end record()
}//end class
