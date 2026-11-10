<?php

/**
 * Pins the Mastodon timeline read: every request sent and every outcome.
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
use OCA\Pipelinq\Service\Egress\ConnectorEgress;
use OCA\Pipelinq\Service\Egress\EgressResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Lookup and timeline answers, the requests behind them, and the outcome.
 *
 * @covers \OCA\Pipelinq\Service\Competitor\FediverseWatchReader
 * @uses \OCA\Pipelinq\Service\Competitor\WatchOutcome
 * @uses \OCA\Pipelinq\Service\Egress\EgressResult
 */
class FediverseWatchReaderTest extends TestCase {

	/**
	 * The scripted egress answers and the expected outcome, reason, item urls and request count.
	 *
	 * @return array<string, array{array<int, EgressResult>, string, string, array<int, string>, int}>
	 */
	public static function cases(): array {
		$account  = EgressResult::success(body: '{"id":" 10 9 "}');
		$timeline = EgressResult::success(body: '[{"url":"https://m.example/@gem/1","content":"<p>Hoi</p>","created_at":"2026-09-01T10:00:00Z"},{"uri":"https://m.example/@gem/2"},"noise",{"content":"no url"}]');
		$down     = EgressResult::failed(failure: EgressResult::UNAVAILABLE, reason: 'timeout');
		return [
			'lookup fails'       => [[$down], 'unavailable', 'timeout', [], 1],
			'account unknown'    => [[EgressResult::success(body: '{"id":"  "}')], 'unparsable', 'The account gem@m.example was not found on m.example.', [], 1],
			'lookup not json'    => [[EgressResult::success(body: 'nope')], 'unparsable', 'The account gem@m.example was not found on m.example.', [], 1],
			'timeline fails'     => [[$account, EgressResult::failed(failure: EgressResult::REFUSED, reason: '403')], 'refused', '403', [], 2],
			'timeline not json'  => [[$account, EgressResult::success(body: '<html>')], 'unparsable', 'The timeline of gem@m.example could not be read.', [], 2],
			'timeline read'      => [[$account, $timeline], 'ok', '', ['https://m.example/@gem/1', 'https://m.example/@gem/2'], 2],
		];
	}//end cases()

	/**
	 * Each answer leads to exactly the pinned requests and outcome.
	 *
	 * @param array<int, EgressResult> $answers  The egress answers, in order.
	 * @param string                   $outcome  The outcome.
	 * @param string                   $reason   The reason.
	 * @param array<int, string>       $urls     The item urls.
	 * @param int                      $requests How many requests were sent.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testMastodon(array $answers, string $outcome, string $reason, array $urls, int $requests): void {
		$sent   = [];
		$egress = $this->createMock(ConnectorEgress::class);
		$egress->method('readUrl')->willReturnCallback(
			function (string $configKey, string $url, array $config = [], string $sourceId = '') use (&$sent, &$answers): EgressResult {
				$sent[] = [$configKey, $url, $config, $sourceId];
				return array_shift($answers);
			}
		);

		$result = (new FediverseWatchReader(egress: $egress))->read(handle: ' @gem@m.example ', sourceId: 'src-7');

		$this->assertSame($outcome, $result->outcome);
		$this->assertSame($reason, $result->reason);
		$this->assertSame($urls, array_column($result->items, 'url'));
		$expected = [
			['competitor.egress_source', 'https://m.example/api/v1/accounts/lookup', ['query' => ['acct' => 'gem@m.example']], 'src-7'],
			['competitor.egress_source', 'https://m.example/api/v1/accounts/10%209/statuses', ['query' => ['limit' => '25', 'exclude_replies' => 'true']], 'src-7'],
		];
		$this->assertSame(array_slice($expected, 0, $requests), $sent);
	}//end testMastodon()
}//end class
