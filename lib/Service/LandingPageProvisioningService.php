<?php

/**
 * Pipelinq LandingPageProvisioningService.
 *
 * Asks Portaliq for a landing page with a lead-capture form, and records
 * what came back on the campaign. Pages stay in Portaliq (ADR-086):
 * Pipelinq describes the page it wants and never renders a public one.
 *
 * The hand-off is a same-instance typed event, not HTTP (ADR-041). The
 * event FQCN is a string constant and is `class_exists()`-guarded,
 * because a `use` of a Portaliq class would fatal at autoload on an
 * instance without Portaliq, in an app that has nothing to do with
 * portals. Portaliq writes its answer onto the same instance, which is
 * read the moment `dispatchTyped()` returns.
 *
 * Portaliq's five error codes are passed to the caller unchanged.
 * Collapsing them into one message would be a real loss: a duplicate
 * route and an invalid form are fixed in different places by different
 * people.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-campaign-creates-its-landing-page-in-portaliq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * LandingPageProvisioningService: one campaign asks Portaliq for one page.
 *
 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-campaign-creates-its-landing-page-in-portaliq
 */
class LandingPageProvisioningService {

	/**
	 * Portaliq's request event. A string, never a `use`: importing it
	 * would fatal at autoload on an instance without Portaliq.
	 *
	 * @var string
	 */
	public const REQUEST_EVENT_CLASS = 'OCA\\Portaliq\\Event\\LandingPageRequestedEvent';

	/**
	 * The app id Pipelinq identifies itself by on the request. Portaliq
	 * resolves the submission consumer's event class from this value.
	 *
	 * @var string
	 */
	public const SOURCE_APP = 'pipelinq';

	/**
	 * App-config key naming the portal campaign pages are created on.
	 * Falls back to the traffic portal the blast reporting already uses,
	 * so a tenant that configured one portal does not configure it twice.
	 *
	 * @var string
	 */
	public const PORTAL_CONFIG_KEY = 'marketing.landing_portal';

	/**
	 * Our own failure code for an instance without Portaliq. It sits
	 * alongside Portaliq's five rather than pretending to be one of them.
	 *
	 * @var string
	 */
	public const ERROR_PORTALIQ_MISSING = 'portaliq_missing';

	/**
	 * Constructor.
	 *
	 * @param IEventDispatcher $dispatcher Typed cross-app dispatch.
	 * @param CampaignService $campaigns Reads and updates the campaign.
	 * @param IAppConfig $appConfig Pipelinq app config.
	 * @param IL10N $l10n Translates the form labels the visitor reads.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-campaign-creates-its-landing-page-in-portaliq
	 */
	public function __construct(
		private IEventDispatcher $dispatcher,
		private CampaignService $campaigns,
		private IAppConfig $appConfig,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Create a landing page for one campaign.
	 *
	 * @param string $campaignId The campaign.
	 * @param string $portal The portal slug; empty uses the configured one.
	 * @param string $route The in-portal route; empty derives one from the campaign value.
	 *
	 * @return array{error: string, portal: string, route: string, pageId: string, publicUrl: string, formId: string}
	 *         `error` is empty on success, otherwise Portaliq's own code or `portaliq_missing`.
	 *
	 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-campaign-creates-its-landing-page-in-portaliq
	 */
	public function createFor(string $campaignId, string $portal = '', string $route = ''): array {
		$campaign = $this->campaigns->find(id: $campaignId);
		if ($campaign === null) {
			return $this->failure(error: 'not_found');
		}

		$targetPortal = trim($portal);
		if ($targetPortal === '') {
			$targetPortal = $this->configuredPortal();
		}

		if ($targetPortal === '') {
			return $this->failure(error: 'unknown_portal');
		}

		$targetRoute = $this->routeFor(campaign: $campaign, requested: $route);

		if ($this->isPortaliqInstalled() === false) {
			return $this->failure(error: self::ERROR_PORTALIQ_MISSING);
		}

		return $this->dispatchAndRecord(
			campaignId: $campaignId,
			campaign: $campaign,
			portal: $targetPortal,
			route: $targetRoute
		);
	}//end createFor()

	/**
	 * Whether Portaliq's request event exists. Protected so a test can
	 * answer for it without Portaliq installed.
	 *
	 * @return bool True when Portaliq is present.
	 *
	 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-campaign-creates-its-landing-page-in-portaliq
	 */
	protected function isPortaliqInstalled(): bool {
		return class_exists(self::REQUEST_EVENT_CLASS);
	}//end isPortaliqInstalled()

	/**
	 * The article payload Portaliq renders the page from.
	 *
	 * Portaliq refuses an empty summary or body (`invalid_article`), so a
	 * campaign that has neither is refused there rather than here: the
	 * marketer gets one vocabulary of errors, not two.
	 *
	 * @param array<string, mixed> $campaign The campaign.
	 *
	 * @return array<string, mixed> `summary`, `body`, `heroImageRef`, `links`.
	 *
	 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-campaign-creates-its-landing-page-in-portaliq
	 */
	public function articleFor(array $campaign): array {
		return [
			'summary' => trim((string)($campaign['articleSummary'] ?? '')),
			'body' => trim((string)($campaign['articleBody'] ?? '')),
			'heroImageRef' => null,
			'links' => [],
		];
	}//end articleFor()

	/**
	 * The lead-capture form Portaliq binds to the page.
	 *
	 * Name and email are required because a submission without either
	 * cannot become a contact. Organisation is optional and is what makes
	 * the lead worth following up.
	 *
	 * @return array<string, mixed> `fields`, `submitLabel`, `consentText`.
	 *
	 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-campaign-creates-its-landing-page-in-portaliq
	 */
	public function formDefinition(): array {
		return [
			'fields' => [
				['id' => 'name', 'label' => $this->l10n->t('Name'), 'type' => 'text', 'required' => true],
				['id' => 'email', 'label' => $this->l10n->t('Email'), 'type' => 'email', 'required' => true],
				['id' => 'organisation', 'label' => $this->l10n->t('Organisation'), 'type' => 'text', 'required' => false],
			],
			'submitLabel' => $this->l10n->t('Sign up'),
			'consentText' => $this->l10n->t('We use your details to answer your request. You can ask us to delete them at any time.'),
		];
	}//end formDefinition()

	/**
	 * The route a campaign's page gets when the caller names none.
	 *
	 * @param array<string, mixed> $campaign The campaign.
	 * @param string $requested What the caller asked for, may be empty.
	 *
	 * @return string A route with a leading slash.
	 *
	 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-campaign-creates-its-landing-page-in-portaliq
	 */
	public function routeFor(array $campaign, string $requested = ''): string {
		$route = trim($requested);
		if ($route === '') {
			$route = ('/campagne/' . trim((string)($campaign['utmCampaign'] ?? '')));
		}

		if (str_starts_with($route, '/') === false) {
			$route = ('/' . $route);
		}

		return rtrim($route, '/');
	}//end routeFor()

	/**
	 * Dispatch the request and record what Portaliq answered.
	 *
	 * @param string $campaignId The campaign id.
	 * @param array<string, mixed> $campaign The campaign row.
	 * @param string $portal The portal slug.
	 * @param string $route The in-portal route.
	 *
	 * @return array{error: string, portal: string, route: string, pageId: string, publicUrl: string, formId: string}
	 */
	private function dispatchAndRecord(string $campaignId, array $campaign, string $portal, string $route): array {
		$eventClass = self::REQUEST_EVENT_CLASS;

		try {
			$event = new $eventClass(
				self::SOURCE_APP,
				$portal,
				$route,
				trim((string)($campaign['name'] ?? '')),
				$this->l10n->getLanguageCode(),
				$this->articleFor(campaign: $campaign),
				$this->formDefinition(),
				[
					'campaign' => (string)($campaign['utmCampaign'] ?? ''),
					'source' => (string)($campaign['utmSource'] ?? ''),
					'medium' => (string)($campaign['utmMedium'] ?? ''),
				],
				('pipelinq:campaign:' . $campaignId),
				('pipelinq-campaign-' . $campaignId)
			);
			$this->dispatcher->dispatchTyped($event);
		} catch (Throwable $e) {
			$this->logger->warning(
				'LandingPageProvisioningService: dispatching the landing page request failed',
				['campaign' => $campaignId, 'portal' => $portal, 'exception' => $e->getMessage()]
			);
			return $this->failure(error: 'write_failed');
		}

		$error = (string)($event->getError() ?? '');
		if ($error !== '' || $event->isHandled() === false) {
			if ($error === '') {
				$error = self::ERROR_PORTALIQ_MISSING;
			}

			return $this->failure(error: $error, portal: $portal, route: $route);
		}

		$landingPage = [
			'portal' => $portal,
			'route' => $this->firstNonEmpty(preferred: $event->getRoute(), fallback: $route),
			'pageId' => (string)($event->getPageId() ?? ''),
			'publicUrl' => (string)($event->getPublicUrl() ?? ''),
			'createdAt' => gmdate('Y-m-d\TH:i:sP'),
		];
		$formId = (string)($event->getFormId() ?? '');

		$this->campaigns->recordLandingPage(id: $campaignId, landingPage: $landingPage, formId: $formId);

		return [
			'error' => '',
			'portal' => $portal,
			'route' => $landingPage['route'],
			'pageId' => $landingPage['pageId'],
			'publicUrl' => $landingPage['publicUrl'],
			'formId' => $formId,
		];
	}//end dispatchAndRecord()

	/**
	 * The first of two values that is not empty.
	 *
	 * @param string $preferred What portaliq echoed back.
	 * @param string $fallback What was asked for.
	 *
	 * @return string The value.
	 */
	private function firstNonEmpty(string $preferred, string $fallback): string {
		if (trim($preferred) !== '') {
			return $preferred;
		}

		return $fallback;
	}//end firstNonEmpty()

	/**
	 * The portal campaign pages are created on.
	 *
	 * @return string The slug, or an empty string when none is configured.
	 */
	private function configuredPortal(): string {
		$portal = trim($this->appConfig->getValueString(Application::APP_ID, self::PORTAL_CONFIG_KEY, ''));
		if ($portal !== '') {
			return $portal;
		}

		return trim($this->appConfig->getValueString(Application::APP_ID, TrafficEventEmitter::PORTAL_CONFIG_KEY, ''));
	}//end configuredPortal()

	/**
	 * A refusal, shaped like a success so the caller reads one envelope.
	 *
	 * @param string $error The machine-readable code.
	 * @param string $portal The portal that was tried.
	 * @param string $route The route that was tried.
	 *
	 * @return array{error: string, portal: string, route: string, pageId: string, publicUrl: string, formId: string}
	 */
	private function failure(string $error, string $portal = '', string $route = ''): array {
		return [
			'error' => $error,
			'portal' => $portal,
			'route' => $route,
			'pageId' => '',
			'publicUrl' => '',
			'formId' => '',
		];
	}//end failure()
}//end class
