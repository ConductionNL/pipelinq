<?php

/**
 * Pipelinq MdmTrustConfigController.
 *
 * Admin CRUD for per-(entityType, attribute, source) trust-tier configuration
 * (REQ-MDM-005). All mutations are admin-gated; the read list is available to
 * authenticated stewards driving the conflict-resolution wizard.
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
 * @spec openspec/changes/master-data-management/specs.md#REQ-MDM-005
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Mdm\TrustConfigurationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for trust-configuration CRUD.
 */
class MdmTrustConfigController extends Controller
{
    /**
     * Valid trust tiers.
     *
     * @var array<int, string>
     */
    private const TIERS = ['gold', 'silver', 'bronze', 'discard'];

    /**
     * Constructor.
     *
     * @param IRequest                  $request     The request.
     * @param TrustConfigurationService $trust       The trust-config service.
     * @param IUserSession              $userSession The user session.
     * @param LoggerInterface           $logger      The logger.
     */
    public function __construct(
        IRequest $request,
        private TrustConfigurationService $trust,
        private IUserSession $userSession,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * List trust-configuration entries (authenticated stewards).
     *
     * @return JSONResponse The configuration list.
     *
     * @spec openspec/changes/master-data-management/specs.md#REQ-MDM-005
     */
    #[NoAdminRequired]
    public function index(): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }

        $entityType = (string) $this->request->getParam('entityType', '');

        try {
            $filterType = null;
            if ($entityType !== '') {
                $filterType = $entityType;
            }

            $configs = $this->trust->listTrustConfigs(entityType: $filterType);
            return new JSONResponse(['configs' => $configs]);
        } catch (\Throwable $e) {
            $this->logger->warning('MDM trust-config list failed', ['exception' => $e->getMessage()]);
            return new JSONResponse(['message' => 'Could not list trust configurations'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }//end index()

    /**
     * Create or update a trust-configuration entry (admin only).
     *
     * @param string|null $id Optional entry uuid to update.
     *
     * @return JSONResponse The saved configuration.
     *
     * @spec openspec/changes/master-data-management/specs.md#REQ-MDM-005
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function save(?string $id=null): JSONResponse
    {
        $config = $this->validate();
        if (isset($config['error']) === true) {
            return new JSONResponse(['message' => $config['error']], Http::STATUS_BAD_REQUEST);
        }

        try {
            $saved = $this->trust->updateTrustConfig($config, $id);
            return new JSONResponse(['success' => true, 'config' => $saved]);
        } catch (\Throwable $e) {
            $this->logger->error('MDM trust-config save failed', ['exception' => $e]);
            return new JSONResponse(['message' => 'Could not save trust configuration'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }//end save()

    /**
     * Delete a trust-configuration entry (admin only).
     *
     * @param string $id The entry uuid.
     *
     * @return JSONResponse The result.
     *
     * @spec openspec/changes/master-data-management/specs.md#REQ-MDM-005
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function destroy(string $id): JSONResponse
    {
        try {
            $this->trust->deleteTrustConfig($id);
            return new JSONResponse(['success' => true]);
        } catch (\Throwable $e) {
            $this->logger->error('MDM trust-config delete failed', ['exception' => $e]);
            return new JSONResponse(['message' => 'Could not delete trust configuration'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }//end destroy()

    /**
     * Validate and normalise the trust-configuration request body.
     *
     * @return array<string, mixed> The validated config, or an `error` key.
     */
    private function validate(): array
    {
        $entityType   = (string) $this->request->getParam('entityType', '');
        $attribute    = (string) $this->request->getParam('attribute', '');
        $sourceSystem = (string) $this->request->getParam('sourceSystem', '');
        $trustTier    = (string) $this->request->getParam('trustTier', '');

        if ($entityType === '' || $attribute === '' || $sourceSystem === '' || $trustTier === '') {
            return ['error' => 'entityType, attribute, sourceSystem and trustTier are required'];
        }

        if (in_array($trustTier, self::TIERS, true) === false) {
            return ['error' => 'Invalid trustTier'];
        }

        $decay      = $this->request->getParam('freshnessDecayDays', null);
        $decayValue = null;
        if ($decay !== null && $decay !== '') {
            $decayValue = (int) $decay;
        }

        return [
            'entityType'            => $entityType,
            'attribute'             => $attribute,
            'sourceSystem'          => $sourceSystem,
            'trustTier'             => $trustTier,
            'freshnessDecayDays'    => $decayValue,
            'manualOverrideAllowed' => ((bool) $this->request->getParam('manualOverrideAllowed', true)),
            'rationale'             => (string) $this->request->getParam('rationale', ''),
            'effectiveFrom'         => (string) $this->request->getParam('effectiveFrom', ''),
        ];
    }//end validate()
}//end class
