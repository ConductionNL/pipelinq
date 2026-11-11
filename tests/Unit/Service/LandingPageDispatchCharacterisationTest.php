<?php
/**
 * Characterisation test for the dispatch step of LandingPageProvisioningService.
 *
 * Pins what LandingPageProvisioningServiceTest leaves open: a dispatch that
 * throws (logged, answered as write_failed with no portal or route, nothing
 * recorded) and the createdAt stamp written with a recorded page.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-campaign-creates-its-landing-page-in-portaliq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\CampaignLinkDecorator;
use OCA\Pipelinq\Service\CampaignService;
use OCA\Pipelinq\Service\LandingPageProvisioningService;
use OCA\Pipelinq\Tests\Unit\Support\InMemoryListObjectStore;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

require_once __DIR__ . '/../Support/InMemoryListObjectStore.php';
require_once __DIR__ . '/../../Stubs/Portaliq/Event/LandingPageRequestedEvent.php';

/**
 * Dispatch failure and createdAt.
 */
class LandingPageDispatchCharacterisationTest extends TestCase {
	/**
	 * Warnings logged.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $warnings = [];

	/**
	 * The store.
	 *
	 * @var InMemoryListObjectStore
	 */
	private InMemoryListObjectStore $store;

	/**
	 * A throwing dispatch answers write_failed, logs, and records nothing.
	 *
	 * @return void
	 */
	public function testAThrowingDispatchIsLoggedAndAnsweredAsWriteFailed(): void {
		$result = $this->build(throws: true)->createFor(campaignId: 'camp-1', portal: 'p-x', route: 'r');

		self::assertSame(['error' => 'write_failed', 'portal' => '', 'route' => '', 'pageId' => '', 'publicUrl' => '', 'formId' => ''], $result);
		self::assertSame(
			[['LandingPageProvisioningService: dispatching the landing page request failed', ['campaign' => 'camp-1', 'portal' => 'p-x', 'exception' => 'portaliq down']]],
			$this->warnings
		);
		self::assertArrayNotHasKey('landingPage', $this->store->find(schemaSlug: 'campaign', id: 'camp-1'));
	}//end testAThrowingDispatchIsLoggedAndAnsweredAsWriteFailed()

	/**
	 * A recorded page carries the portal, route, ids and a UTC createdAt.
	 *
	 * @return void
	 */
	public function testARecordedPageCarriesItsCreatedAt(): void {
		$result = $this->build(throws: false)->createFor(campaignId: 'camp-1', portal: 'p-x', route: 'r');

		self::assertSame(['error' => '', 'portal' => 'p-x', 'route' => '/r', 'pageId' => 'page-9', 'publicUrl' => '', 'formId' => ''], $result);
		$page = $this->store->find(schemaSlug: 'campaign', id: 'camp-1')['landingPage'];
		self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/', $page['createdAt']);
		unset($page['createdAt']);
		self::assertSame(['portal' => 'p-x', 'route' => '/r', 'pageId' => 'page-9', 'publicUrl' => ''], $page);
		self::assertSame([], $this->warnings);
	}//end testARecordedPageCarriesItsCreatedAt()

	/**
	 * Build the service.
	 *
	 * @param bool $throws Whether the dispatch throws.
	 *
	 * @return LandingPageProvisioningService
	 */
	private function build(bool $throws): LandingPageProvisioningService {
		$this->store = new InMemoryListObjectStore(['campaign' => [['uuid' => 'camp-1', 'name' => 'C', 'utmCampaign' => 'c']]]);
		$this->warnings = [];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => $default);
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (object $event) use ($throws): void {
				if ($throws === true) {
					throw new RuntimeException('portaliq down');
				}

				$event->setHandled(true);
				$event->setPageId('page-9');
			}
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$l10n->method('getLanguageCode')->willReturn('nl');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->warnings[] = [$message, $context];
			}
		);
		$campaigns = new CampaignService($this->store, $appConfig, new CampaignLinkDecorator($appConfig));

		return new class ($dispatcher, $campaigns, $appConfig, $l10n, $logger) extends LandingPageProvisioningService {
			/**
			 * Portaliq counts as installed.
			 *
			 * @return bool
			 */
			protected function isPortaliqInstalled(): bool {
				return true;
			}//end isPortaliqInstalled()
		};
	}//end build()
}//end class
