<?php

/**
 * Pipelinq BlastTrackingController.
 *
 * Public, unauthenticated first-party open-pixel + click-redirect endpoints
 * for the marketing blast pipeline (marketing-email-open-click-tracking).
 * Mirrors {@see BlastWebhookController}'s public shape — both endpoints are
 * `#[PublicPage]` + `#[NoCSRFRequired]` because a recipient's mail client /
 * browser cannot send an NC session cookie or CSRF token. Authenticity is
 * enforced by {@see TrackingLinkService::verifyToken()} (HMAC-SHA256,
 * `hash_equals`); the click target is trusted only after verification
 * passes, so this controller cannot be abused as an open redirector.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/marketing-email-open-click-tracking/tasks.md#2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\TrackingLinkService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Controller for /api/blast/track/* — first-party open/click tracking.
 *
 * @spec openspec/changes/marketing-email-open-click-tracking/tasks.md#2
 */
class BlastTrackingController extends Controller {

	/**
	 * Brute-force throttler action for rejected tracking tokens.
	 *
	 * @var string
	 */
	private const THROTTLE_ACTION = 'pipelinq_blast_tracking_token';

	/**
	 * Record a rejected tracking token with the brute-force throttler.
	 *
	 * The tokens are signed, so guessing one is expensive already — but these
	 * two endpoints are the most-fetched public surface the app has (every
	 * recipient's mail client hits them), which makes them the cheapest place
	 * to probe from and the easiest to overlook.
	 *
	 * The half that COUNTS; `#[BruteForceProtection]` is the half that
	 * ENFORCES. Either alone is inert — see ADR-082.
	 *
	 * @return void
	 */
	private function registerRejectedToken(): void {
		try {
			$this->throttler->registerAttempt(
				action: self::THROTTLE_ACTION,
				ip: $this->request->getRemoteAddress()
			);
		} catch (\Throwable $throttlerFailure) {
			$this->logger->warning(
				'BlastTrackingController: registerAttempt failed: ' . $throttlerFailure->getMessage()
			);
		}
	}//end registerRejectedToken()

	/**
	 * A minimal 1x1 transparent GIF89a (43 bytes) — the open pixel.
	 *
	 * @var string
	 */
	private const PIXEL_GIF = "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff\x21\xf9\x04\x01\x00"
		. "\x00\x00\x00\x2c\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02\x44\x01\x00\x3b";

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param TrackingLinkService $trackingLinkService Sign/verify/record.
	 * @param IThrottler $throttler Brute-force throttler for rejected tracking tokens.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		IRequest $request,
		private TrackingLinkService $trackingLinkService,
		private IThrottler $throttler,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * GET /api/blast/track/open/{token} — the open pixel.
	 *
	 * Always returns the 1x1 transparent GIF so the recipient's email
	 * client renders it normally, regardless of token validity. Records an
	 * open only when the token verifies — fail closed on the *record*, not
	 * the response, and never raises a 500.
	 *
	 * The open pixel deliberately does NOT register a rejected token: it always
	 * answers with the same 1x1 GIF whatever the token, precisely so a mail
	 * client never renders a broken image. There is no failure branch to hang
	 * a counter on, and inventing one would leak the very signal the uniform
	 * response exists to hide. The AnonRateLimit below is the control here.
	 *
	 * @param string $token The signed open token.
	 *
	 * @return DataDisplayResponse The 1x1 GIF (200), caching disabled.
	 *
	 * @spec openspec/changes/marketing-email-open-click-tracking/tasks.md#2.1
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 240, period: 60)]
	public function open(string $token): DataDisplayResponse {
		try {
			$payload = $this->trackingLinkService->verifyToken(token: $token);
			$deliveryId = (string)($payload['d'] ?? '');
			if ($payload !== null && $deliveryId !== '') {
				$this->trackingLinkService->recordOpen(blastDeliveryId: $deliveryId);
			}
		} catch (Throwable $e) {
			$this->logger->warning('BlastTrackingController.open: record failed', ['exception' => $e->getMessage()]);
		}

		return new DataDisplayResponse(
			self::PIXEL_GIF,
			Http::STATUS_OK,
			[
				'Content-Type' => 'image/gif',
				'Cache-Control' => 'no-store, no-cache, must-revalidate',
				'Pragma' => 'no-cache',
			]
		);
	}//end open()

	/**
	 * GET /api/blast/track/click/{token} — the click redirect.
	 *
	 * Verifies the token first; the target URL embedded in the token is
	 * trusted ONLY after `hash_equals` passes (never a caller-supplied
	 * query parameter), so this endpoint cannot be used as an open
	 * redirector. An invalid/expired/malformed token returns 410 Gone and
	 * performs no redirect and no record.
	 *
	 * @param string $token The signed click token.
	 *
	 * @return JSONResponse|RedirectResponse The 302 redirect, or a 410 on
	 *                                       an invalid token.
	 *
	 * @spec openspec/changes/marketing-email-open-click-tracking/tasks.md#2.1
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function click(string $token): JSONResponse|RedirectResponse {
		$payload = $this->trackingLinkService->verifyToken(token: $token);
		if ($payload === null) {
			$this->registerRejectedToken();
			return new JSONResponse(['error' => 'Link expired or invalid'], Http::STATUS_GONE);
		}

		$deliveryId = (string)($payload['d'] ?? '');
		$targetUrl = (string)($payload['u'] ?? '');
		if ($deliveryId === '' || $targetUrl === '') {
			$this->registerRejectedToken();
			return new JSONResponse(['error' => 'Link expired or invalid'], Http::STATUS_GONE);
		}

		try {
			$this->trackingLinkService->recordClick(blastDeliveryId: $deliveryId, url: $targetUrl);
		} catch (Throwable $e) {
			$this->logger->warning('BlastTrackingController.click: record failed', ['exception' => $e->getMessage()]);
		}

		// 302 (Found) per the requirement spec — RedirectResponse defaults
		// to 303 (See Other), which mail/link scanners are less likely to
		// treat as a standard link-follow redirect.
		return new RedirectResponse($targetUrl, Http::STATUS_FOUND);
	}//end click()
}//end class
