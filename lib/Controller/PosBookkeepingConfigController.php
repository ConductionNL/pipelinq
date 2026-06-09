<?php

/**
 * Pipelinq PosBookkeepingConfigController.
 *
 * Admin-only settings for the POS end-of-day bookkeeping pipeline — daily
 * Z-report generation time, Shillinq endpoint + bearer token, alert email,
 * max retry attempts and the default glAccountMapping profile. The bearer
 * token is stored with isSensitive=true so it is never returned by the GET
 * endpoint.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V.
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#4.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use InvalidArgumentException;
use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Admin settings controller for the POS bookkeeping pipeline.
 *
 * `#[AuthorizedAdminSetting(Application::APP_ID)]` restricts both endpoints
 * to admins authorised to manage the pipelinq app's settings; Nextcloud
 * middleware enforces this before the action runs (so the controller body
 * never needs an extra admin gate).
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Aggregates the standard
 *  Nextcloud controller collaborators (request, config, logger).
 *
 * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#4.2
 */
class PosBookkeepingConfigController extends Controller
{
    /**
     * Configurable setting keys (non-sensitive) and their defaults.
     *
     * @var array<string, string>
     */
    private const DEFAULTS = [
        'pos_eod.z_report_time'      => '23:59',
        'pos_eod.shillinq_endpoint'  => '',
        'pos_eod.alert_email'        => '',
        'pos_eod.max_retry_attempts' => '5',
    ];

    /**
     * Constructor.
     *
     * @param IRequest        $request   The request.
     * @param IAppConfig      $appConfig The app config.
     * @param LoggerInterface $logger    The logger.
     */
    public function __construct(
        IRequest $request,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * GET /api/admin/pos-bookkeeping/config — return current settings.
     *
     * The bearer token is NEVER returned. The response carries a
     * `tokenConfigured` boolean so the admin UI can show a "token is set"
     * indicator without exposing the value.
     *
     * @return JSONResponse The settings (token redacted).
     *
     * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#4.2
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function index(): JSONResponse
    {
        return new JSONResponse(['settings' => $this->readAll()]);
    }//end index()

    /**
     * POST /api/admin/pos-bookkeeping/config — persist settings.
     *
     * Body (any subset):
     *   {
     *     "zReportTime":      "23:59",
     *     "shillinqEndpoint": "https://shillinq.example.org",
     *     "shillinqToken":    "sk_live_...",
     *     "alertEmail":       "accounting@example.org",
     *     "maxRetryAttempts": 5
     *   }
     *
     * The bearer token is persisted with `setValueString(...,
     * isSensitive=true)` so it is encrypted at rest and excluded from
     * IAppConfig::getAllValues responses.
     *
     * @return JSONResponse The updated settings, or a validation error.
     *
     * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#4.2
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function update(): JSONResponse
    {
        try {
            $this->applyTimeParam();
            $this->applyEndpointParam();
            $this->applyTokenParam();
            $this->applyEmailParam();
            $this->applyMaxRetryParam();
        } catch (InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
        } catch (Throwable $e) {
            $this->logger->error(
                'PosBookkeepingConfigController::update failed',
                ['exception' => $e->getMessage()]
            );
            return new JSONResponse(
                ['error' => 'Onverwachte fout bij opslaan van bookkeeping instellingen.'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }

        return new JSONResponse(['settings' => $this->readAll()]);
    }//end update()

    /**
     * Read every (non-sensitive) setting with defaults.
     *
     * @return array<string, mixed> The settings (token redacted).
     */
    private function readAll(): array
    {
        return [
            'zReportTime'      => $this->appConfig->getValueString(
                Application::APP_ID,
                'pos_eod.z_report_time',
                self::DEFAULTS['pos_eod.z_report_time']
            ),
            'shillinqEndpoint' => $this->appConfig->getValueString(
                Application::APP_ID,
                'pos_eod.shillinq_endpoint',
                self::DEFAULTS['pos_eod.shillinq_endpoint']
            ),
            'alertEmail'       => $this->appConfig->getValueString(
                Application::APP_ID,
                'pos_eod.alert_email',
                self::DEFAULTS['pos_eod.alert_email']
            ),
            'maxRetryAttempts' => (int) $this->appConfig->getValueString(
                Application::APP_ID,
                'pos_eod.max_retry_attempts',
                self::DEFAULTS['pos_eod.max_retry_attempts']
            ),
            'tokenConfigured'  => trim($this->appConfig->getValueString(Application::APP_ID, 'pos_eod.shillinq_token', '')) !== '',
        ];
    }//end readAll()

    /**
     * Apply the zReportTime param after validating HH:MM format.
     *
     * @return void
     *
     * @throws InvalidArgumentException When the format is invalid.
     */
    private function applyTimeParam(): void
    {
        $raw = $this->request->getParam('zReportTime', null);
        if ($raw === null) {
            return;
        }

        $value = (string) $raw;
        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) !== 1) {
            throw new InvalidArgumentException('zReportTime moet in HH:MM (00:00 - 23:59) formaat zijn.');
        }

        $this->appConfig->setValueString(Application::APP_ID, 'pos_eod.z_report_time', $value);
    }//end applyTimeParam()

    /**
     * Apply the shillinqEndpoint param after URL validation.
     *
     * Empty string is allowed (disables the integration). When supplied,
     * the value must be an http(s) URL.
     *
     * @return void
     *
     * @throws InvalidArgumentException When the URL is invalid.
     */
    private function applyEndpointParam(): void
    {
        $raw = $this->request->getParam('shillinqEndpoint', null);
        if ($raw === null) {
            return;
        }

        $value = trim((string) $raw);
        if ($value !== '' && filter_var($value, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('shillinqEndpoint moet een geldige URL zijn.');
        }

        if ($value !== '' && preg_match('#^https?://#i', $value) !== 1) {
            throw new InvalidArgumentException('shillinqEndpoint moet beginnen met http:// of https://.');
        }

        $this->appConfig->setValueString(Application::APP_ID, 'pos_eod.shillinq_endpoint', $value);
    }//end applyEndpointParam()

    /**
     * Apply the shillinqToken param (stored as sensitive).
     *
     * Empty string clears the token (disables auth). Otherwise the value is
     * stored with isSensitive=true via setValueString so it is encrypted at
     * rest and excluded from IAppConfig::getAllValues responses.
     *
     * @return void
     */
    private function applyTokenParam(): void
    {
        $raw = $this->request->getParam('shillinqToken', null);
        if ($raw === null) {
            return;
        }

        $value = (string) $raw;
        $this->appConfig->setValueString(
            Application::APP_ID,
            'pos_eod.shillinq_token',
            $value,
            false,
            true
        );
    }//end applyTokenParam()

    /**
     * Apply the alertEmail param after email validation.
     *
     * @return void
     *
     * @throws InvalidArgumentException When the email is invalid.
     */
    private function applyEmailParam(): void
    {
        $raw = $this->request->getParam('alertEmail', null);
        if ($raw === null) {
            return;
        }

        $value = trim((string) $raw);
        if ($value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('alertEmail moet een geldig e-mailadres zijn.');
        }

        $this->appConfig->setValueString(Application::APP_ID, 'pos_eod.alert_email', $value);
    }//end applyEmailParam()

    /**
     * Apply the maxRetryAttempts param after bounds validation (1..10).
     *
     * @return void
     *
     * @throws InvalidArgumentException When the value is out of range.
     */
    private function applyMaxRetryParam(): void
    {
        $raw = $this->request->getParam('maxRetryAttempts', null);
        if ($raw === null) {
            return;
        }

        $value = (int) $raw;
        if ($value < 1 || $value > 10) {
            throw new InvalidArgumentException('maxRetryAttempts moet tussen 1 en 10 liggen.');
        }

        $this->appConfig->setValueString(Application::APP_ID, 'pos_eod.max_retry_attempts', (string) $value);
    }//end applyMaxRetryParam()
}//end class
