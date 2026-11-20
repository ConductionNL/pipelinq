<?php

/**
 * Pins the Bluesky read, the empty handle and the shape of every read item.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Competitor
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Competitor;

use OCA\Pipelinq\Service\Competitor\FediverseWatchReader;
use OCA\Pipelinq\Service\Competitor\WatchOutcome;
use OCA\Pipelinq\Service\Egress\ConnectorEgress;
use OCA\Pipelinq\Service\Egress\EgressResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Author-feed answers, the request behind them, and the items they become.
 *
 * @covers \OCA\Pipelinq\Service\Competitor\FediverseWatchReader
 * @uses \OCA\Pipelinq\Service\Competitor\WatchOutcome
 * @uses \OCA\Pipelinq\Service\Egress\EgressResult
 * @uses \OCA\Pipelinq\Service\Competitor\MastodonAccount
 */
class FediverseWatchReaderBlueskyTest extends TestCase {

	/**
	 * The request every Bluesky read sends.
	 *
	 * @var array<int, mixed>
	 */
	private const FEED_REQUEST = [
		'competitor.egress_source',
		'https://public.api.bsky.app/xrpc/app.bsky.feed.getAuthorFeed',
		['query' => ['actor' => 'gem.bsky.social', 'limit' => '25']],
		'src-3',
	];

	/**
	 * The requests sent to the mocked egress.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $sent = [];

	/**
	 * Run the reader against scripted egress answers.
	 *
	 * @param array<int, EgressResult> $answers The answers, in order.
	 * @param string                   $handle  The watched handle.
	 *
	 * @return WatchOutcome The outcome.
	 */
	private function read(array $answers, string $handle): WatchOutcome {
		$this->sent = [];
		$sent       = &$this->sent;
		$egress     = $this->createMock(ConnectorEgress::class);
		$egress->method('readUrl')->willReturnCallback(
			function (string $configKey, string $url, array $config = [], string $sourceId = '') use (&$sent, &$answers): EgressResult {
				$sent[] = [$configKey, $url, $config, $sourceId];
				return array_shift($answers);
			}
		);

		return (new FediverseWatchReader(egress: $egress))->read(handle: $handle, sourceId: 'src-3');
	}//end read()

	/**
	 * A handle that is empty once trimmed is refused without a request.
	 *
	 * @return void
	 */
	public function testEmptyHandleSendsNothing(): void {
		$result = $this->read(answers: [], handle: '  @ ');

		$this->assertSame(EgressResult::NOT_CONFIGURED, $result->outcome);
		$this->assertSame('This watch has no handle.', $result->reason);
		$this->assertSame([], $result->items);
		$this->assertSame([], $this->sent);
	}//end testEmptyHandleSendsNothing()

	/**
	 * Feed answers that are not a readable feed, and the outcome each gives.
	 *
	 * @return array<string, array{EgressResult, string, string}>
	 */
	public static function failures(): array {
		$unreadable = 'The feed of gem.bsky.social could not be read.';
		return [
			'feed fails'        => [EgressResult::failed(failure: EgressResult::UNAVAILABLE, reason: 'timeout'), 'unavailable', 'timeout'],
			'feed not json'     => [EgressResult::success(body: '<html>'), 'unparsable', $unreadable],
			'feed key missing'  => [EgressResult::success(body: '{"cursor":"x"}'), 'unparsable', $unreadable],
			'feed not a list'   => [EgressResult::success(body: '{"feed":"x"}'), 'unparsable', $unreadable],
		];
	}//end failures()

	/**
	 * A failed or unreadable feed fails the watch after one request.
	 *
	 * @param EgressResult $answer  The feed answer.
	 * @param string       $outcome The outcome.
	 * @param string       $reason  The reason.
	 *
	 * @return void
	 */
	#[DataProvider('failures')]
	public function testBlueskyFailures(EgressResult $answer, string $outcome, string $reason): void {
		$result = $this->read(answers: [$answer], handle: '@gem.bsky.social');

		$this->assertSame($outcome, $result->outcome);
		$this->assertSame($reason, $result->reason);
		$this->assertSame([], $result->items);
		$this->assertSame([self::FEED_REQUEST], $this->sent);
	}//end testBlueskyFailures()

	/**
	 * Posts become items with a permalink, a short summary and a stamp; the rest is skipped.
	 *
	 * @return void
	 */
	public function testBlueskyFeedBecomesItems(): void {
		$feed = [
			'feed' => [
				[
					'post' => [
						'uri'       => 'at://did:plc:abc/app.bsky.feed.post/3kxyz',
						'cid'       => ' bafy1 ',
						'indexedAt' => ' 2026-09-01T10:00:00Z ',
						'record'    => ['text' => '  <b>Hoi</b> allemaal '],
					],
				],
				'noise',
				['post' => 'not a post'],
				['reason' => 'repost without post'],
				['post' => ['uri' => '  ', 'cid' => 'skipped']],
				['post' => ['uri' => 'at://did:plc:abc/app.bsky.feed.post/', 'record' => 'not a record']],
			],
		];

		$result = $this->read(answers: [EgressResult::success(body: json_encode($feed))], handle: 'gem.bsky.social');

		$this->assertSame(WatchOutcome::OK, $result->outcome);
		$this->assertSame('', $result->reason);
		$this->assertSame(
			[
				[
					'url'         => 'https://bsky.app/profile/gem.bsky.social/post/3kxyz',
					'title'       => 'gem.bsky.social posted',
					'summary'     => 'Hoi allemaal',
					'stamp'       => 'bafy1',
					'publishedAt' => '2026-09-01T10:00:00Z',
				],
				[
					'url'         => 'at://did:plc:abc/app.bsky.feed.post/',
					'title'       => 'gem.bsky.social posted',
					'summary'     => '',
					'stamp'       => 'at://did:plc:abc/app.bsky.feed.post/',
					'publishedAt' => '',
				],
			],
			$result->items
		);
		$this->assertSame([self::FEED_REQUEST], $this->sent);
	}//end testBlueskyFeedBecomesItems()

	/**
	 * Mastodon statuses keep their id as stamp, fall back to the url, and long text is cut.
	 *
	 * @return void
	 */
	public function testMastodonItemShapeAndShortening(): void {
		$long     = str_repeat('é', 600);
		$exact    = str_repeat('a', 500);
		$statuses = [
			['url' => 'https://m.example/@gem/1', 'id' => ' 111 ', 'content' => '<p>' . $long . '</p>', 'created_at' => '2026-09-02T08:00:00Z'],
			['uri' => 'https://m.example/@gem/2', 'content' => $exact],
		];

		$result = $this->read(
			answers: [EgressResult::success(body: '{"id":"9"}'), EgressResult::success(body: json_encode($statuses))],
			handle: 'gem@m.example'
		);

		$this->assertTrue($result->succeeded());
		$this->assertSame(
			[
				[
					'url'         => 'https://m.example/@gem/1',
					'title'       => 'gem@m.example posted',
					'summary'     => str_repeat('é', 497) . '...',
					'stamp'       => '111',
					'publishedAt' => '2026-09-02T08:00:00Z',
				],
				[
					'url'         => 'https://m.example/@gem/2',
					'title'       => 'gem@m.example posted',
					'summary'     => $exact,
					'stamp'       => 'https://m.example/@gem/2',
					'publishedAt' => '',
				],
			],
			$result->items
		);
		$this->assertSame('https://m.example/api/v1/accounts/9/statuses', $this->sent[1][1]);
	}//end testMastodonItemShapeAndShortening()
}//end class
