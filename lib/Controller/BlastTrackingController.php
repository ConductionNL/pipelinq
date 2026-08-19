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
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Controller for /api/blast/track/* — first-party open/click tracking.
 *
 * @spec openspec/changes/marketing-email-open-click-tracking/tasks.md#2
 */
class BlastTrackingController extends Controller
{
    /**
     * A minimal 1x1 transparent GIF89a (43 bytes) — the open pixel.
     *
     * @var string
     */
    private const PIXEL_GIF = "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff\x21\xf9\x04\x01\x00"
        ."\x00\x00\x00\x2c\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02\x44\x01\x00\x3b";

    /**
     * Constructor.
     *
     * @param IRequest            $request             The request.
     * @param TrackingLinkService $trackingLinkService Sign/verify/record.
     * @param LoggerInterface     $logger              Logger.
     */
    public function __construct(
        IRequest $request,
        private TrackingLinkService $trackingLinkService,
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
     * @param string $token The signed open token.
     *
     * @return DataDisplayResponse The 1x1 GIF (200), caching disabled.
     *
     * @spec openspec/changes/marketing-email-open-click-tracking/tasks.md#2.1
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function open(string $token): DataDisplayResponse
    {
        try {
            $payload    = $this->trackingLinkService->verifyToken(token: $token);
            $deliveryId = (string) ($payload['d'] ?? '');
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
                'Content-Type'  => 'image/gif',
                'Cache-Control' => 'no-store, no-cache, must-revalidate',
                'Pragma'        => 'no-cache',
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
    public function click(string $token): JSONResponse|RedirectResponse
    {
        $payload = $this->trackingLinkService->verifyToken(token: $token);
        if ($payload === null) {
            return new JSONResponse(['error' => 'Link expired or invalid'], Http::STATUS_GONE);
        }

        $deliveryId = (string) ($payload['d'] ?? '');
        $targetUrl  = (string) ($payload['u'] ?? '');
        if ($deliveryId === '' || $targetUrl === '') {
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
