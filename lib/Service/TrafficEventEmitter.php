<?php

/**
 * Pipelinq TrafficEventEmitter.
 *
 * Dual-writes a recorded mail open or click to Portaliq's traffic
 * collector as an `email_open` / `email_click` event, so a portal's
 * campaign attribution can see mail traffic next to site traffic. The
 * blastDelivery write stays the system of record: this emitter runs AFTER
 * it, is skipped silently when no portal is configured or Portaliq is not
 * installed, and never throws.
 *
 * The contract lives in the fleet traffic analytics contract (section 6,
 * server-side ingest): the event carries a `blastRef` and a `contactRef`
 * but NO client id, NO email address and NO IP address. Portaliq derives
 * its visitor hash from `contactRef`, salted per portal per day, so mail
 * and site visits do not link by person.
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
 * @spec openspec/specs/marketing-email-tracking/spec.md#requirement-opens-and-clicks-are-reported-to-portaliq-as-email-traffic-events
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * TrafficEventEmitter: report a mail open or click to Portaliq as traffic.
 *
 * @spec openspec/specs/marketing-email-tracking/spec.md#requirement-opens-and-clicks-are-reported-to-portaliq-as-email-traffic-events
 */
class TrafficEventEmitter {

	/**
	 * App-config key naming the Portaliq portal slug mail events are
	 * attributed to. Empty (the default) keeps mail tracking inside Pipelinq.
	 *
	 * @var string
	 */
	public const PORTAL_CONFIG_KEY = 'blast.traffic_portal';

	/**
	 * FQCN of Portaliq's server-side ingest service. Resolved duck-typed:
	 * Pipelinq never imports it, so the app boots without Portaliq present.
	 *
	 * @var string
	 */
	public const INGEST_SERVICE_CLASS = 'OCA\\Portaliq\\Service\\TrafficIngestService';

	/**
	 * Event name and sequence per kind, per contract section 6.
	 *
	 * @var array<string, array{name: string, sequence: int}>
	 */
	private const KINDS = [
		'open' => ['name' => 'email_open', 'sequence' => 0],
		'click' => ['name' => 'email_click', 'sequence' => 1],
	];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container for the lazy,
	 *                                      duck-typed ingest lookup.
	 * @param IAppConfig $appConfig Pipelinq app config.
	 * @param ITimeFactory $time Time factory for the event timestamp.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @spec openspec/specs/marketing-email-tracking/spec.md#requirement-opens-and-clicks-are-reported-to-portaliq-as-email-traffic-events
	 */
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Report one recorded mail open or click to the configured portal.
	 *
	 * Skips silently (debug log only) when `blast.traffic_portal` is empty
	 * or Portaliq's ingest service is not installed. Every failure past that
	 * point is caught and logged at warning: the blastDelivery write that
	 * preceded this call is never at risk, and the pixel or redirect that
	 * triggered it still answers.
	 *
	 * @param string $kind `open` or `click`.
	 * @param array<string, mixed> $delivery The blastDelivery row as
	 *                                       loaded by the caller.
	 * @param array<string, mixed> $blast The parent blast row, or an
	 *                                    empty array when it could not be
	 *                                    loaded.
	 * @param string|null $clickedUrl The decoded click target, or null
	 *                                for an open.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/marketing-email-tracking/spec.md#requirement-opens-and-clicks-are-reported-to-portaliq-as-email-traffic-events
	 */
	public function emitEmailEvent(string $kind, array $delivery, array $blast, ?string $clickedUrl = null): void {
		if (isset(self::KINDS[$kind]) === false) {
			$this->logger->warning('TrafficEventEmitter: unknown event kind', ['kind' => $kind]);
			return;
		}

		$portalSlug = trim($this->appConfig->getValueString(Application::APP_ID, self::PORTAL_CONFIG_KEY, ''));
		if ($portalSlug === '') {
			$this->logger->debug('TrafficEventEmitter: no traffic portal configured, skipping');
			return;
		}

		if ($this->isIngestServiceAvailable() === false) {
			$this->logger->debug('TrafficEventEmitter: Portaliq ingest service not installed, skipping');
			return;
		}

		try {
			$ingest = $this->container->get(self::INGEST_SERVICE_CLASS);
			if (is_object($ingest) === false || method_exists($ingest, 'ingest') === false) {
				$this->logger->warning('TrafficEventEmitter: ingest service has no ingest() method');
				return;
			}

			$event = $this->buildEvent(kind: $kind, delivery: $delivery, blast: $blast, clickedUrl: $clickedUrl);
			$result = $ingest->ingest($portalSlug, [$event], ['serverSide' => true, 'consent' => true]);
			$this->logAccepted(kind: $kind, portalSlug: $portalSlug, result: $result);
		} catch (Throwable $e) {
			$this->logger->warning(
				'TrafficEventEmitter: reporting to Portaliq failed',
				['kind' => $kind, 'portal' => $portalSlug, 'exception' => $e->getMessage()]
			);
		}
	}//end emitEmailEvent()

	/**
	 * Whether Portaliq's ingest service class is loadable in this instance.
	 *
	 * Protected so a test can substitute the probe without Portaliq present.
	 *
	 * @return bool
	 */
	protected function isIngestServiceAvailable(): bool {
		return class_exists(self::INGEST_SERVICE_CLASS);
	}//end isIngestServiceAvailable()

	/**
	 * Build the single event envelope for the contract's section 6 shape.
	 *
	 * `campaign` is the blast's display name, falling back to its template
	 * id and then to the blast id, because the `blast` schema carries `name`
	 * and `templateId` and no campaign title of its own. `params` is an
	 * empty array: Portaliq's PHP ingest receives it as-is and serialises it
	 * to the contract's empty object.
	 *
	 * @param string $kind `open` or `click`.
	 * @param array<string, mixed> $delivery The blastDelivery row.
	 * @param array<string, mixed> $blast The parent blast row (may be empty).
	 * @param string|null $clickedUrl The decoded click target, or null.
	 *
	 * @return array<string, mixed> The event.
	 */
	private function buildEvent(string $kind, array $delivery, array $blast, ?string $clickedUrl): array {
		$blastId = (string)($delivery['blastId'] ?? '');
		$contactId = (string)($delivery['contactId'] ?? '');
		$pageLocation = ('mailto:blast/' . $blastId);
		if ($clickedUrl !== null && $clickedUrl !== '') {
			$pageLocation = $clickedUrl;
		}

		$campaign = (string)($blast['name'] ?? '');
		if ($campaign === '') {
			$campaign = (string)($blast['templateId'] ?? '');
		}

		if ($campaign === '') {
			$campaign = $blastId;
		}

		return [
			'name' => self::KINDS[$kind]['name'],
			'timestamp' => gmdate('Y-m-d\TH:i:s\Z', $this->time->getTime()),
			'sequence' => self::KINDS[$kind]['sequence'],
			'pageLocation' => $pageLocation,
			'params' => [],
			'campaign' => $campaign,
			'source' => 'email',
			'medium' => 'email',
			'blastRef' => $blastId,
			'contactRef' => $contactId,
		];
	}//end buildEvent()

	/**
	 * Log the ingest outcome: debug when accepted, info when refused.
	 *
	 * @param string $kind `open` or `click`.
	 * @param string $portalSlug The portal the event went to.
	 * @param mixed $result Whatever `ingest()` returned; expected
	 *                      `{accepted:int, refused:array<string,int>}`.
	 *
	 * @return void
	 */
	private function logAccepted(string $kind, string $portalSlug, mixed $result): void {
		$accepted = 0;
		$refused = [];
		if (is_array($result) === true) {
			$accepted = (int)($result['accepted'] ?? 0);
			if (is_array($result['refused'] ?? null) === true) {
				$refused = $result['refused'];
			}
		}

		if ($accepted < 1 || $refused !== []) {
			$this->logger->info(
				'TrafficEventEmitter: Portaliq did not accept the event',
				['kind' => $kind, 'portal' => $portalSlug, 'accepted' => $accepted, 'refused' => $refused]
			);
			return;
		}

		$this->logger->debug('TrafficEventEmitter: event reported', ['kind' => $kind, 'portal' => $portalSlug]);
	}//end logAccepted()
}//end class
