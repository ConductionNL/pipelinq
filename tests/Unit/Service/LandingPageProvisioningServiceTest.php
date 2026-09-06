<?php

/**
 * Unit tests for LandingPageProvisioningService.
 *
 * Covers:
 * - a created page's route, id, public URL and form id land on the campaign
 * - each of Portaliq's failure codes reaches the caller unchanged
 * - a failure records nothing on the campaign
 * - Portaliq absent answers portaliq_missing and dispatches nothing
 * - the route derives from the campaign value when none is asked for
 *
 * Portaliq is never installed here. The request event resolves to the
 * declaration-only stub under tests/Stubs/Portaliq, required explicitly
 * because it is deliberately outside every autoload prefix, and the
 * availability probe is a protected method an anonymous subclass answers
 * for.
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

require_once __DIR__ . '/../Support/InMemoryListObjectStore.php';
require_once __DIR__ . '/../../Stubs/Portaliq/Event/LandingPageRequestedEvent.php';

/**
 * Tests for LandingPageProvisioningService.
 *
 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-campaign-creates-its-landing-page-in-portaliq
 */
class LandingPageProvisioningServiceTest extends TestCase {

	/**
	 * The seeded campaign.
	 *
	 * @var array<string, mixed>
	 */
	private const CAMPAIGN = [
		'uuid' => 'camp-1',
		'name' => 'Webinar AI voor gemeenten',
		'utmCampaign' => 'webinar-ai-voor-gemeenten',
		'utmSource' => 'nieuwsbrief',
		'utmMedium' => 'email',
		'articleSummary' => 'Praktische AI-toepassingen voor de publieke sector.',
		'articleBody' => '## Programma',
	];

	/**
	 * The store the campaign lives in.
	 *
	 * @var InMemoryListObjectStore
	 */
	private InMemoryListObjectStore $store;

	/**
	 * Events the dispatcher saw.
	 *
	 * @var array<int, object>
	 */
	private array $dispatched = [];

	/**
	 * Build a service whose Portaliq answers with a fixed outcome.
	 *
	 * @param bool $installed What the availability probe answers.
	 * @param string|null $error The code Portaliq's listener writes, null on success.
	 * @param bool $handled Whether Portaliq's listener ran at all.
	 *
	 * @return LandingPageProvisioningService
	 */
	private function build(bool $installed = true, ?string $error = null, bool $handled = true): LandingPageProvisioningService {
		$this->store = new InMemoryListObjectStore(['campaign' => [self::CAMPAIGN]]);
		$this->dispatched = [];

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				return ($key === 'marketing.landing_portal') ? 'open-tilburg' : $default;
			}
		);

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (object $event) use ($error, $handled): void {
				$this->dispatched[] = $event;
				$event->setHandled($handled);
				if ($error !== null) {
					$event->setError($error);
					return;
				}

				$event->setPageId('page-1');
				$event->setFormId('form-1');
				$event->setPublicUrl('https://open-tilburg.nl/campagne/webinar-ai-voor-gemeenten');
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$l10n->method('getLanguageCode')->willReturn('nl');

		$campaigns = new CampaignService($this->store, $appConfig, new CampaignLinkDecorator($appConfig));

		return new class ($installed, $dispatcher, $campaigns, $appConfig, $l10n, $this->createMock(LoggerInterface::class)) extends LandingPageProvisioningService {

			/**
			 * @param bool $installed What the probe answers.
			 * @param mixed ...$args The real constructor arguments.
			 */
			public function __construct(private bool $installed, ...$args) {
				parent::__construct(...$args);
			}//end __construct()

			/**
			 * @return bool
			 */
			protected function isPortaliqInstalled(): bool {
				return $this->installed;
			}//end isPortaliqInstalled()
		};
	}//end build()

	/**
	 * @return void
	 */
	public function testRecordsThePageRouteFormAndPublicUrlOnTheCampaign(): void {
		$result = $this->build()->createFor(campaignId: 'camp-1');

		$this->assertSame('', $result['error']);
		$this->assertSame('/campagne/webinar-ai-voor-gemeenten', $result['route']);
		$this->assertSame('page-1', $result['pageId']);
		$this->assertSame('form-1', $result['formId']);

		$stored = $this->store->find(schemaSlug: 'campaign', id: 'camp-1');
		$this->assertSame('/campagne/webinar-ai-voor-gemeenten', $stored['landingPage']['route']);
		$this->assertSame('open-tilburg', $stored['landingPage']['portal']);
		$this->assertSame('form-1', $stored['formRef']);
	}//end testRecordsThePageRouteFormAndPublicUrlOnTheCampaign()

	/**
	 * @return void
	 */
	public function testTheRequestCarriesTheCampaignsArticleFormAndUtm(): void {
		$this->build()->createFor(campaignId: 'camp-1');

		$this->assertCount(1, $this->dispatched);
		$event = $this->dispatched[0];

		$this->assertSame('pipelinq', $event->getSourceApp());
		$this->assertSame('open-tilburg', $event->getPortal());
		$this->assertSame('Webinar AI voor gemeenten', $event->getTitle());
		$this->assertSame('Praktische AI-toepassingen voor de publieke sector.', $event->getArticle()['summary']);
		$this->assertSame('## Programma', $event->getArticle()['body']);
		$this->assertSame(['name', 'email', 'organisation'], array_column($event->getForm()['fields'], 'id'));
		$this->assertSame('webinar-ai-voor-gemeenten', $event->getUtm()['campaign']);
		$this->assertSame('pipelinq:campaign:camp-1', $event->getExternalReference());
	}//end testTheRequestCarriesTheCampaignsArticleFormAndUtm()

	/**
	 * @return void
	 */
	public function testSurfacesDuplicateRouteVerbatim(): void {
		$result = $this->build(error: 'duplicate_route')->createFor(campaignId: 'camp-1');

		$this->assertSame('duplicate_route', $result['error']);
	}//end testSurfacesDuplicateRouteVerbatim()

	/**
	 * @return void
	 */
	public function testSurfacesInvalidFormVerbatim(): void {
		$result = $this->build(error: 'invalid_form')->createFor(campaignId: 'camp-1');

		$this->assertSame('invalid_form', $result['error']);
	}//end testSurfacesInvalidFormVerbatim()

	/**
	 * @return void
	 */
	public function testRecordsNothingOnFailure(): void {
		$this->build(error: 'invalid_article')->createFor(campaignId: 'camp-1');

		$stored = $this->store->find(schemaSlug: 'campaign', id: 'camp-1');
		$this->assertArrayNotHasKey('landingPage', $stored);
		$this->assertArrayNotHasKey('formRef', $stored);
	}//end testRecordsNothingOnFailure()

	/**
	 * A listener that never ran is a platform fault, not a rejection: the
	 * request was neither accepted nor refused, so it must not read as
	 * success with empty ids.
	 *
	 * @return void
	 */
	public function testAnUnhandledRequestIsNotSuccess(): void {
		$result = $this->build(handled: false)->createFor(campaignId: 'camp-1');

		$this->assertSame('portaliq_missing', $result['error']);
		$this->assertSame('', $result['pageId']);
	}//end testAnUnhandledRequestIsNotSuccess()

	/**
	 * @return void
	 */
	public function testPortaliqAbsentAnswersItsOwnCodeAndDispatchesNothing(): void {
		$result = $this->build(installed: false)->createFor(campaignId: 'camp-1');

		$this->assertSame('portaliq_missing', $result['error']);
		$this->assertSame([], $this->dispatched);
	}//end testPortaliqAbsentAnswersItsOwnCodeAndDispatchesNothing()

	/**
	 * @return void
	 */
	public function testAnUnknownCampaignIsNotFound(): void {
		$result = $this->build()->createFor(campaignId: 'nope');

		$this->assertSame('not_found', $result['error']);
		$this->assertSame([], $this->dispatched);
	}//end testAnUnknownCampaignIsNotFound()

	/**
	 * @return void
	 */
	public function testAnExplicitRouteWinsAndAlwaysCarriesALeadingSlash(): void {
		$service = $this->build();

		$this->assertSame('/aanmelden', $service->routeFor(campaign: self::CAMPAIGN, requested: 'aanmelden'));
		$this->assertSame('/aanmelden', $service->routeFor(campaign: self::CAMPAIGN, requested: '/aanmelden/'));
		$this->assertSame('/campagne/webinar-ai-voor-gemeenten', $service->routeFor(campaign: self::CAMPAIGN));
	}//end testAnExplicitRouteWinsAndAlwaysCarriesALeadingSlash()
}//end class
