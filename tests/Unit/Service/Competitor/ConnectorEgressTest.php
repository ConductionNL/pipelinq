<?php

/**
 * Tests for ConnectorEgress and the change's egress discipline.
 *
 * Two things are asserted here. The seam itself tells its four failure modes
 * apart, so no caller can ever present a failure as an empty result. And no
 * service this change adds declares an HTTP client of its own, which is the
 * mechanical form of rule 3 of the marketing architecture and of ADR-067: a
 * class with an `IClientService` has left the egress plane, whatever its
 * comments say.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Competitor
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
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
use OCA\Pipelinq\Service\Competitor\WatchEventStore;
use OCA\Pipelinq\Service\Egress\ConnectorEgress;
use OCA\Pipelinq\Service\Egress\EgressResult;
use OCA\Pipelinq\Service\Matomo\MatomoReportService;
use OCA\Pipelinq\Service\Search\SiteContentCrawler;
use OCA\Pipelinq\Service\Social\ConnectionAuditService;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @covers \OCA\Pipelinq\Service\Egress\ConnectorEgress
 * @covers \OCA\Pipelinq\Service\Egress\EgressResult
 */
class ConnectorEgressTest extends TestCase {

	/**
	 * A seam over a container that resolves nothing and a config that holds
	 * the given source id.
	 *
	 * @param string $sourceId The configured source id.
	 *
	 * @return ConnectorEgress
	 */
	private function egress(string $sourceId): ConnectorEgress {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('not installed'));
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn($sourceId);

		return new ConnectorEgress(
			container: $container,
			appConfig: $appConfig,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end egress()

	/**
	 * No source id configured is `not_configured`, and nothing is attempted.
	 *
	 * @return void
	 */
	public function testReportsNotConfiguredWithoutASourceId(): void {
		$result = $this->egress(sourceId: '')->read(configKey: 'competitor.egress_source', endpoint: '/feed');

		$this->assertFalse($result->ok);
		$this->assertSame(EgressResult::NOT_CONFIGURED, $result->failure);
		$this->assertStringContainsString('competitor.egress_source', $result->reason);
	}//end testReportsNotConfiguredWithoutASourceId()

	/**
	 * A configured source that cannot be resolved, because OpenRegister or
	 * OpenConnector is absent, is `unavailable` rather than an exception.
	 *
	 * @return void
	 */
	public function testReportsUnavailableWithoutOpenConnector(): void {
		$result = $this->egress(sourceId: 'source-1')->read(configKey: 'competitor.egress_source', endpoint: '/feed');

		$this->assertFalse($result->ok);
		$this->assertSame(EgressResult::UNAVAILABLE, $result->failure);
	}//end testReportsUnavailableWithoutOpenConnector()

	/**
	 * `isConfigured()` answers on the app-config value alone, so a page can
	 * say "nothing is configured" without attempting a call.
	 *
	 * @return void
	 */
	public function testIsConfiguredReadsTheAppConfigValue(): void {
		$this->assertFalse($this->egress(sourceId: '')->isConfigured(configKey: 'competitor.egress_source'));
		$this->assertTrue($this->egress(sourceId: 'source-1')->isConfigured(configKey: 'competitor.egress_source'));
	}//end testIsConfiguredReadsTheAppConfigValue()

	/**
	 * A non-2xx answer is `refused`, and it carries the status.
	 *
	 * @return void
	 */
	public function testReportsRefusedOnANonTwoHundredStatus(): void {
		$refused = EgressResult::failed(failure: EgressResult::REFUSED, reason: 'answered 404', status: 404);

		$this->assertFalse($refused->ok);
		$this->assertSame(404, $refused->status);
		$this->assertSame(EgressResult::REFUSED, $refused->failure);
		$this->assertNull($refused->json());
	}//end testReportsRefusedOnANonTwoHundredStatus()

	/**
	 * A successful read decodes JSON, and a body that is not JSON answers
	 * null rather than an empty array.
	 *
	 * @return void
	 */
	public function testJsonDecodesOnlyRealJson(): void {
		$this->assertSame(['a' => 1], EgressResult::success(body: '{"a":1}')->json());
		$this->assertNull(EgressResult::success(body: '<html></html>')->json());
		$this->assertNull(EgressResult::success(body: '')->json());
	}//end testJsonDecodesOnlyRealJson()

	/**
	 * The failure vocabulary is closed, so a page can render every value.
	 *
	 * @return void
	 */
	public function testTheFailureVocabularyIsClosed(): void {
		$this->assertSame(
			['not_configured', 'unavailable', 'refused', 'unparsable'],
			EgressResult::FAILURES
		);
	}//end testTheFailureVocabularyIsClosed()

	/**
	 * No service this change adds declares an HTTP client. This is the
	 * mechanical form of "one egress plane": a comment cannot be checked and
	 * a constructor parameter can.
	 *
	 * @return void
	 */
	public function testNoServiceInThisChangeInjectsAnHttpClient(): void {
		$classes = [
			ConnectorEgress::class,
			SiteContentCrawler::class,
			MatomoReportService::class,
			FeedWatchReader::class,
			SitemapWatchReader::class,
			PageWatchReader::class,
			FediverseWatchReader::class,
			SearchWatchReader::class,
			RelevanceScorer::class,
			WatchEventStore::class,
			CompetitorWatchService::class,
			ConnectionAuditService::class,
		];

		foreach ($classes as $class) {
			$parameters = (new ReflectionClass($class))->getConstructor()?->getParameters() ?? [];
			foreach ($parameters as $parameter) {
				$this->assertStringNotContainsString(
					'IClient',
					(string)$parameter->getType(),
					$class . ' must read through ConnectorEgress, not through an HTTP client of its own'
				);
			}
		}
	}//end testNoServiceInThisChangeInjectsAnHttpClient()

	/**
	 * No background job drives competitor watches. ADR-094 gives the
	 * schedule to the flow engine, and the observable difference between
	 * "on a flow" and "on a cron with extra steps" is exactly this.
	 *
	 * @return void
	 */
	public function testNoTimedJobDrivesCompetitorWatches(): void {
		$directory = (__DIR__ . '/../../../../lib/BackgroundJob');
		$this->assertDirectoryExists($directory);

		foreach ((array)glob($directory . '/*.php') as $file) {
			$source = (string)file_get_contents((string)$file);
			$this->assertStringNotContainsString(
				'CompetitorWatch',
				$source,
				basename((string)$file) . ' must not drive competitor watches; the flow engine does'
			);
		}
	}//end testNoTimedJobDrivesCompetitorWatches()
}//end class
