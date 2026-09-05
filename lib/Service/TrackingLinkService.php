<?php

/**
 * Pipelinq TrackingLinkService.
 *
 * First-party open/click tracking for the marketing blast pipeline
 * (marketing-email-open-click-tracking). Today, open/click telemetry
 * arrives only via inbound provider webhooks into BlastWebhookController
 * — an operator on a plain per-tenant openconnector `send-mail` source
 * without a webhook-capable provider gets no telemetry at all. This
 * service issues HMAC-signed, PII-free tokens for a pipelinq-hosted
 * open pixel + click-redirect, rewrites `<a href>` links and appends
 * the pixel at render time, and records opens/clicks onto the existing
 * `blastDelivery` fields — reusing {@see AttributionService::recordClick()}
 * and {@see BlastService::updateBlastTotals()} so the roll-up matches the
 * webhook path exactly. No new schema.
 *
 * Token shape mirrors {@see PortalController::signLink()}: a base64url
 * JSON payload `{d, u, iat, exp}` joined with `.` to a base64url
 * HMAC-SHA256 signature over the payload, verified with `hash_equals`.
 * The signing secret is a per-instance random value in app-config key
 * `blast.tracking_secret`, minted on first use (ADR-005).
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
 * @spec openspec/changes/marketing-email-open-click-tracking/tasks.md#1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\Security\ISecureRandom;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * TrackingLinkService — sign / verify / inject / record for first-party
 * open + click tracking.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Wires the token-signing
 *  primitives (config, time, CSPRNG) plus the two sibling services whose
 *  semantics this reuses (AttributionService::recordClick(),
 *  BlastService::updateBlastTotals()) — one cohesive tracking service.
 *
 * @spec openspec/changes/marketing-email-open-click-tracking/tasks.md#1
 */
class TrackingLinkService {
	/**
	 * App-config key for the per-instance HMAC signing secret.
	 *
	 * @var string
	 */
	private const SECRET_CONFIG_KEY = 'blast.tracking_secret';

	/**
	 * App-config key for the admin-overridable token TTL (days).
	 *
	 * @var string
	 */
	private const TTL_CONFIG_KEY = 'blast.tracking_token_ttl_days';

	/**
	 * Default token TTL in days (90-day fixed default, admin-overridable).
	 *
	 * @var int
	 */
	private const DEFAULT_TTL_DAYS = 90;

	/**
	 * Public open-pixel route prefix (see appinfo/routes.php).
	 *
	 * @var string
	 */
	private const OPEN_ROUTE_PREFIX = '/api/blast/track/open/';

	/**
	 * Public click-redirect route prefix (see appinfo/routes.php).
	 *
	 * @var string
	 */
	private const CLICK_ROUTE_PREFIX = '/api/blast/track/click/';

	/**
	 * Default register slug used when no `register` app config value is set.
	 *
	 * @var string
	 */
	private const DEFAULT_REGISTER_SLUG = 'pipelinq';

	/**
	 * Default BlastDelivery schema slug.
	 *
	 * @var string
	 */
	private const DEFAULT_BLAST_DELIVERY_SCHEMA_SLUG = 'blastDelivery';

	/**
	 * BlastDelivery statuses that `recordOpen()` is allowed to advance to
	 * `opened` — mirrors {@see WebhookProcessorService::processOpen()} so
	 * the first-party path never downgrades a terminal status.
	 *
	 * @var array<int, string>
	 */
	private const OPENABLE_STATUSES = ['delivered', 'sent'];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container — lazy
	 *                                      ObjectService lookup.
	 * @param IAppConfig $appConfig Pipelinq app config.
	 * @param ITimeFactory $time Time factory (token
	 *                           iat/exp).
	 * @param ISecureRandom $secureRandom CSPRNG (signing-key
	 *                                    minting).
	 * @param AttributionService $attributionService Click-record semantics.
	 * @param BlastService $blastService Totals roll-up.
	 * @param LoggerInterface $logger Logger.
	 * @param TrafficEventEmitter $trafficEventEmitter Portaliq traffic
	 *                                                 dual-write.
	 *
	 * @spec openspec/changes/marketing-email-open-click-tracking/tasks.md#1
	 */
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private ITimeFactory $time,
		private ISecureRandom $secureRandom,
		private AttributionService $attributionService,
		private BlastService $blastService,
		private LoggerInterface $logger,
		private TrafficEventEmitter $trafficEventEmitter,
	) {
	}//end __construct()

	/**
	 * Sign an open-pixel token for a BlastDelivery.
	 *
	 * @param string $blastDeliveryId BlastDelivery UUID or slug.
	 *
	 * @return string The dotted `<payload>.<signature>` token.
	 *
	 * @spec openspec/changes/marketing-email-open-click-tracking/tasks.md#1.1
	 */
	public function signOpenToken(string $blastDeliveryId): string {
		return $this->signPayload(payload: ['d' => $blastDeliveryId, 'u' => null]);
	}//end signOpenToken()

	/**
	 * Sign a click-redirect token binding a BlastDelivery to a target URL.
	 *
	 * The target URL is embedded in the signed payload — it is trusted by
	 * {@see \OCA\Pipelinq\Controller\BlastTrackingController::click()} only
	 * after `verifyToken()` confirms the signature, so the endpoint cannot
	 * be abused as an open redirector.
	 *
	 * @param string $blastDeliveryId BlastDelivery UUID or slug.
	 * @param string $targetUrl The original link target.
	 *
	 * @return string The dotted `<payload>.<signature>` token.
	 *
	 * @spec openspec/changes/marketing-email-open-click-tracking/tasks.md#1.1
	 */
	public function signClickToken(string $blastDeliveryId, string $targetUrl): string {
		return $this->signPayload(payload: ['d' => $blastDeliveryId, 'u' => $targetUrl]);
	}//end signClickToken()

	/**
	 * Verify a token issued by `signOpenToken()` / `signClickToken()`.
	 *
	 * Constant-time signature comparison via `hash_equals`; fails closed
	 * (returns null) on a malformed token, a signature mismatch, or an
	 * expired token. Never throws.
	 *
	 * @param string $token The presented token.
	 *
	 * @return array<string, mixed>|null Decoded payload (`d`, `u`, `iat`,
	 *                                   `exp`) or null when invalid/expired.
	 *
	 * @spec openspec/changes/marketing-email-open-click-tracking/tasks.md#1.1
	 */
	public function verifyToken(string $token): ?array {
		$parts = explode('.', $token);
		if (count($parts) !== 2) {
			return null;
		}

		[$encoded, $signature] = $parts;
		if ($encoded === '' || $signature === '') {
			return null;
		}

		$expected = $this->base64UrlEncode(data: hash_hmac('sha256', $encoded, $this->signingKey(), true));
		if (hash_equals($expected, $signature) === false) {
			return null;
		}

		$decoded = json_decode((string)$this->base64UrlDecode(data: $encoded), true);
		if (is_array($decoded) === false || isset($decoded['d']) === false || isset($decoded['exp']) === false) {
			return null;
		}

		if ((int)$decoded['exp'] < $this->time->getTime()) {
			return null;
		}

		return $decoded;
	}//end verifyToken()

	/**
	 * Rewrite outbound links + append the open pixel to a rendered blast
	 * email, when first-party tracking is enabled.
	 *
	 * Each `<a href="URL">` is rewritten to the signed click-redirect;
	 * anchors whose href is empty, an in-page fragment (`#...`), or still
	 * carries an unresolved merge token (contains `{{`) are left untouched
	 * — this protects `{{unsubscribe_link}}` and any other compliance
	 * merge tag from being rewritten. A 1x1 open pixel is appended before
	 * `</body>` (or to the end of the body when no `</body>` tag exists).
	 *
	 * @param string $html Rendered email body HTML.
	 * @param string $blastDeliveryId BlastDelivery UUID or slug the links
	 *                                are bound to.
	 *
	 * @return string The rewritten HTML (unchanged on empty input).
	 *
	 * @spec openspec/changes/marketing-email-open-click-tracking/tasks.md#1.2
	 */
	public function injectTracking(string $html, string $blastDeliveryId): string {
		if ($html === '' || $blastDeliveryId === '') {
			return $html;
		}

		$rewritten = preg_replace_callback(
			'/<a\b([^>]*?)\shref=(["\'])(.*?)\2([^>]*)>/i',
			function (array $matches) use ($blastDeliveryId): string {
				return $this->rewriteAnchor(matches: $matches, blastDeliveryId: $blastDeliveryId);
			},
			$html
		);
		if (is_string($rewritten) === false) {
			$this->logger->warning(
				'TrackingLinkService.injectTracking: link rewrite failed — appending pixel only',
				['blastDeliveryId' => $blastDeliveryId]
			);
			$rewritten = $html;
		}

		return $this->appendOpenPixel(html: $rewritten, blastDeliveryId: $blastDeliveryId);
	}//end injectTracking()

	/**
	 * Record an open on a BlastDelivery — set-once `openedAt`, advance
	 * `status` toward `opened` (never downgrading a terminal status), then
	 * refresh the per-blast totals roll-up, then report the open to the
	 * configured Portaliq portal as an `email_open` traffic event.
	 *
	 * @param string $blastDeliveryId BlastDelivery UUID or slug.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/marketing-email-open-click-tracking/tasks.md#1.3
	 * @spec openspec/specs/marketing-email-tracking/spec.md#requirement-opens-and-clicks-are-reported-to-portaliq-as-email-traffic-events
	 */
	public function recordOpen(string $blastDeliveryId): void {
		$delivery = $this->loadDelivery(id: $blastDeliveryId);
		if ($delivery === null) {
			return;
		}

		$payload = $delivery;
		if (empty($payload['openedAt']) === true) {
			$payload['openedAt'] = $this->nowIso();
		}

		$current = (string)($payload['status'] ?? '');
		if (in_array($current, self::OPENABLE_STATUSES, true) === true) {
			$payload['status'] = 'opened';
		}

		$this->saveDelivery(payload: $payload, id: $this->extractId(payload: $delivery));

		$blastId = (string)($delivery['blastId'] ?? '');
		if ($blastId !== '') {
			$this->blastService->updateBlastTotals(blastId: $blastId);
		}

		$this->reportToTraffic(kind: 'open', delivery: $delivery, clickedUrl: null);
	}//end recordOpen()

	/**
	 * Record a click on a BlastDelivery — delegates the click semantics to
	 * {@see AttributionService::recordClick()} (set-once `firstClickAt`,
	 * deduped `clickedUrls`, status advance), then refreshes the per-blast
	 * totals roll-up, then reports the click to the configured Portaliq
	 * portal as an `email_click` traffic event.
	 *
	 * @param string $blastDeliveryId BlastDelivery UUID or slug.
	 * @param string $url The clicked (decoded) target URL.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/marketing-email-open-click-tracking/tasks.md#1.3
	 * @spec openspec/specs/marketing-email-tracking/spec.md#requirement-opens-and-clicks-are-reported-to-portaliq-as-email-traffic-events
	 */
	public function recordClick(string $blastDeliveryId, string $url): void {
		$delivery = $this->loadDelivery(id: $blastDeliveryId);
		if ($delivery === null) {
			return;
		}

		$this->attributionService->recordClick(
			blastDeliveryId: $blastDeliveryId,
			clickEvent: ['url' => $url, 'timestamp' => $this->nowIso()],
		);

		$blastId = (string)($delivery['blastId'] ?? '');
		if ($blastId !== '') {
			$this->blastService->updateBlastTotals(blastId: $blastId);
		}

		$this->reportToTraffic(kind: 'click', delivery: $delivery, clickedUrl: $url);
	}//end recordClick()

	/**
	 * Dual-write a recorded open or click to Portaliq's traffic collector.
	 *
	 * Runs strictly AFTER the blastDelivery write and the totals roll-up so
	 * a Portaliq outage can never lose the record, and swallows anything
	 * the emitter lets through so the pixel or redirect still answers.
	 *
	 * @param string $kind `open` or `click`.
	 * @param array<string, mixed> $delivery The loaded blastDelivery row.
	 * @param string|null $clickedUrl The decoded click target, or null.
	 *
	 * @return void
	 */
	private function reportToTraffic(string $kind, array $delivery, ?string $clickedUrl): void {
		try {
			$blastId = (string)($delivery['blastId'] ?? '');
			$blast = null;
			if ($blastId !== '') {
				$blast = $this->blastService->getBlastById(blastId: $blastId);
			}

			$this->trafficEventEmitter->emitEmailEvent(
				kind: $kind,
				delivery: $delivery,
				blast: ($blast ?? []),
				clickedUrl: $clickedUrl,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'TrackingLinkService.reportToTraffic: traffic dual-write failed',
				['kind' => $kind, 'exception' => $e->getMessage()]
			);
		}
	}//end reportToTraffic()

	/**
	 * Rewrite one `<a href="...">` match from `injectTracking()`.
	 *
	 * @param array<int, string> $matches Regex match groups: 0=full,
	 *                                    1=attrs before href,
	 *                                    2=quote char, 3=href value,
	 *                                    4=attrs after href.
	 * @param string $blastDeliveryId BlastDelivery id.
	 *
	 * @return string The rewritten (or unchanged) anchor tag.
	 */
	private function rewriteAnchor(array $matches, string $blastDeliveryId): string {
		$before = ($matches[1] ?? '');
		$quote = ($matches[2] ?? '"');
		$href = ($matches[3] ?? '');
		$after = ($matches[4] ?? '');

		if ($href === '' || str_starts_with($href, '#') === true || str_contains($href, '{{') === true) {
			return $matches[0];
		}

		// The attribute value is HTML: `&amp;` between query parameters is
		// the correct spelling there, but the redirect target must be the
		// URL itself, or every second parameter arrives as `amp;name`.
		$target = html_entity_decode($href, (ENT_QUOTES | ENT_HTML5));
		$token = $this->signClickToken(blastDeliveryId: $blastDeliveryId, targetUrl: $target);
		$newHref = (self::CLICK_ROUTE_PREFIX . $token);
		return ('<a' . $before . ' href=' . $quote . $newHref . $quote . $after . '>');
	}//end rewriteAnchor()

	/**
	 * Append the 1x1 open-pixel `<img>` tag before `</body>` (or at the
	 * end of the document when no `</body>` tag is present).
	 *
	 * @param string $html Rendered body HTML.
	 * @param string $blastDeliveryId BlastDelivery id.
	 *
	 * @return string HTML with the pixel appended.
	 */
	private function appendOpenPixel(string $html, string $blastDeliveryId): string {
		$token = $this->signOpenToken(blastDeliveryId: $blastDeliveryId);
		$pixel = '<img src="' . self::OPEN_ROUTE_PREFIX . $token
			. '" width="1" height="1" alt="" style="display:none;border:0;width:1px;height:1px;" />';

		if (stripos($html, '</body>') !== false) {
			$withPixel = preg_replace('/<\/body>/i', ($pixel . '</body>'), $html, 1);
			if (is_string($withPixel) === true) {
				return $withPixel;
			}
		}

		return ($html . $pixel);
	}//end appendOpenPixel()

	/**
	 * Sign a payload — adds `iat`/`exp`, base64url-encodes, and appends the
	 * base64url HMAC-SHA256 signature.
	 *
	 * @param array<string, mixed> $payload The `{d, u}` payload.
	 *
	 * @return string The dotted `<payload>.<signature>` token.
	 */
	private function signPayload(array $payload): string {
		$issuedAt = $this->time->getTime();
		$expiresAt = ($issuedAt + $this->resolveTtlSeconds());

		$full = array_merge($payload, ['iat' => $issuedAt, 'exp' => $expiresAt]);

		$encoded = $this->base64UrlEncode(data: (string)json_encode($full));
		$signature = $this->base64UrlEncode(data: hash_hmac('sha256', $encoded, $this->signingKey(), true));
		return ($encoded . '.' . $signature);
	}//end signPayload()

	/**
	 * Resolve (or lazily mint) the per-instance HMAC signing key.
	 *
	 * @return string The signing key.
	 */
	private function signingKey(): string {
		$key = $this->appConfig->getValueString(Application::APP_ID, self::SECRET_CONFIG_KEY, '');
		if ($key === '') {
			$key = $this->secureRandom->generate(64, ISecureRandom::CHAR_ALPHANUMERIC);
			$this->appConfig->setValueString(Application::APP_ID, self::SECRET_CONFIG_KEY, $key, false, true);
		}

		return $key;
	}//end signingKey()

	/**
	 * Resolve the token TTL in seconds — 90-day fixed default,
	 * admin-overridable via app-config key `blast.tracking_token_ttl_days`.
	 *
	 * @return int TTL in seconds (>=1 day).
	 */
	private function resolveTtlSeconds(): int {
		$raw = $this->appConfig->getValueString(
			Application::APP_ID,
			self::TTL_CONFIG_KEY,
			(string)self::DEFAULT_TTL_DAYS,
		);
		if ($raw === '' || is_numeric($raw) === false) {
			return (self::DEFAULT_TTL_DAYS * 86400);
		}

		$days = (int)$raw;
		if ($days <= 0) {
			return (self::DEFAULT_TTL_DAYS * 86400);
		}

		return ($days * 86400);
	}//end resolveTtlSeconds()

	/**
	 * Base64url-encode (RFC 4648 §5, no padding).
	 *
	 * @param string $data The raw data.
	 *
	 * @return string The encoded string.
	 */
	private function base64UrlEncode(string $data): string {
		return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
	}//end base64UrlEncode()

	/**
	 * Base64url-decode.
	 *
	 * @param string $data The encoded string.
	 *
	 * @return string The decoded data (empty on failure).
	 */
	private function base64UrlDecode(string $data): string {
		$decoded = base64_decode(strtr($data, '-_', '+/'), true);
		if ($decoded === false) {
			return '';
		}

		return $decoded;
	}//end base64UrlDecode()

	/**
	 * Load one BlastDelivery row by id.
	 *
	 * @param string $id BlastDelivery UUID or slug.
	 *
	 * @return array<string, mixed>|null Row or null.
	 */
	private function loadDelivery(string $id): ?array {
		$register = $this->getRegisterSlug();
		$schema = $this->getBlastDeliverySchemaSlug();
		if ($register === '' || $schema === '' || $id === '') {
			return null;
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return null;
		}

		try {
			$entity = $objectService->find(id: $id, register: $register, schema: $schema);
		} catch (Throwable $e) {
			$this->logger->info(
				'TrackingLinkService.loadDelivery: not found',
				['id' => $id, 'exception' => $e->getMessage()]
			);
			return null;
		}

		if ($entity === null) {
			return null;
		}

		return $this->toArray(value: $entity);
	}//end loadDelivery()

	/**
	 * Persist a BlastDelivery payload via OpenRegister's ObjectService.
	 *
	 * @param array<string, mixed> $payload Delivery payload.
	 * @param string $id Delivery id.
	 *
	 * @return void
	 */
	private function saveDelivery(array $payload, string $id): void {
		$register = $this->getRegisterSlug();
		$schema = $this->getBlastDeliverySchemaSlug();
		if ($register === '' || $schema === '' || $id === '') {
			return;
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return;
		}

		try {
			$objectService->saveObject(object: $payload, register: $register, schema: $schema, uuid: $id);
		} catch (Throwable $e) {
			$this->logger->warning(
				'TrackingLinkService.saveDelivery: save failed',
				['id' => $id, 'exception' => $e->getMessage()]
			);
		}
	}//end saveDelivery()

	/**
	 * Extract the object id from a payload (`uuid` / `id` / `slug`,
	 * falling back to the `@self` envelope).
	 *
	 * @param array<string, mixed> $payload Payload.
	 *
	 * @return string Id or empty.
	 */
	private function extractId(array $payload): string {
		foreach (['uuid', 'id', 'slug'] as $key) {
			if (isset($payload[$key]) === true && is_scalar($payload[$key]) === true && (string)$payload[$key] !== '') {
				return (string)$payload[$key];
			}
		}

		if (isset($payload['@self']) === true && is_array($payload['@self']) === true) {
			foreach (['uuid', 'id', 'slug'] as $key) {
				$value = ($payload['@self'][$key] ?? null);
				if (is_scalar($value) === true && (string)$value !== '') {
					return (string)$value;
				}
			}
		}

		return '';
	}//end extractId()

	/**
	 * Resolve the BlastDelivery schema slug from app config.
	 *
	 * @return string Slug.
	 */
	private function getBlastDeliverySchemaSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'blastDelivery_schema', '');
		if ($slug !== '') {
			return $slug;
		}

		return self::DEFAULT_BLAST_DELIVERY_SCHEMA_SLUG;
	}//end getBlastDeliverySchemaSlug()

	/**
	 * Resolve the register slug from app config.
	 *
	 * @return string Slug.
	 */
	private function getRegisterSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		if ($slug !== '') {
			return $slug;
		}

		return self::DEFAULT_REGISTER_SLUG;
	}//end getRegisterSlug()

	/**
	 * Resolve the OpenRegister ObjectService lazily.
	 *
	 * @return object|null ObjectService or null when OR is unavailable.
	 */
	private function getObjectService(): ?object {
		try {
			return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			$this->logger->warning(
				'TrackingLinkService.getObjectService: OpenRegister unavailable',
				['exception' => $e->getMessage()]
			);
			return null;
		}
	}//end getObjectService()

	/**
	 * Normalise an OpenRegister entity or array to a plain array.
	 *
	 * @param mixed $value Entity object or array.
	 *
	 * @return array<string, mixed> Plain payload.
	 */
	private function toArray(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			$serialised = $value->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		if (is_object($value) === true && method_exists($value, 'getObject') === true) {
			$payload = $value->getObject();
			if (is_array($payload) === true) {
				return $payload;
			}
		}

		return [];
	}//end toArray()

	/**
	 * Current time as an ISO-8601 string.
	 *
	 * @return string Timestamp.
	 */
	private function nowIso(): string {
		return gmdate('Y-m-d\TH:i:s\Z');
	}//end nowIso()
}//end class
