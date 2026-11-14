<?php

/**
 * A STOP integriq refused reaches integriq on the next job run.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-writes-every-wish-to-integriq-req-cii-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\BackgroundJob;

use OCA\Pipelinq\BackgroundJob\ReplayConsentToIntegriqJob;
use OCA\Pipelinq\Service\ConsentService;
use OCA\Pipelinq\Service\ContactAddressLookup;
use OCA\Pipelinq\Tests\Unit\Support\FakeIntegriq;
use OCA\Pipelinq\Tests\Unit\Support\InMemoryObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * The replay job, through the real ConsentService.
 */
class ReplayConsentToIntegriqJobTest extends TestCase {

	public function testARefusedStopIsReplayedOnTheNextRun(): void {
		$integriq = new FakeIntegriq();
		$store = new InMemoryObjectService();
		$store->put('contact', ['uuid' => 'c-1', 'phone' => '+31612345678']);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($store);
		$appConfig = $this->createMock(IAppConfig::class);
		$cutoverAt = (string)(time() - 60);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = '') => match ($key) {
				'consent.store' => 'integriq',
				'consent.cutover_at' => $cutoverAt,
				default => $default,
			}
		);
		$logger = new NullLogger();
		$consent = new ConsentService($container, $appConfig, $logger, FakeIntegriq::client($appConfig, $integriq), new ContactAddressLookup($container, $appConfig, $logger));

		$integriq->changeMode = FakeIntegriq::MODE_THROW;
		$consent->recordOptOut('c-1', 'sms', 'keyword-stop', 'STOP');
		self::assertCount(1, $store->ofSchema('messagingConsentRecord'));
		self::assertSame([], $integriq->rows);

		$integriq->changeMode = FakeIntegriq::MODE_ANSWER;
		$job = new ReplayConsentToIntegriqJob($this->createMock(ITimeFactory::class), $consent, $logger);
		(new ReflectionMethod($job, 'run'))->invoke($job, null);

		$rows = $integriq->rowsFor('+31612345678');
		self::assertCount(1, $rows);
		self::assertSame('opted-out', $rows[0]['state']);
		self::assertSame('keyword-stop', $rows[0]['source']);
	}//end testARefusedStopIsReplayedOnTheNextRun()
}//end class
