<?php

/**
 * Pipelinq MdmApiController.
 *
 * Read-only API for downstream apps (Shillinq, Procest, …) to retrieve golden
 * records by masterId, alias (pre-merge id) or natural key. All endpoints
 * require an authenticated session / bearer token; no mutations are exposed to
 * external consumers (REQ-MDM-010).
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
 * @spec openspec/changes/master-data-management/specs.md#REQ-MDM-010
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Mdm\MdmObjectRepository;
use OCP\AppFramework\Http;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Read-only golden-record API for downstream apps.
 */
class MdmApiController extends Controller
{
    /**
     * Natural keys queryable per entity type, mapping query parameter to the
     * golden-record attribute it matches.
     *
     * @var array<string, array<string, string>>
     */
    private const NATURAL_KEYS = [
        'account' => ['kvk' => 'kvkNumber', 'vat' => 'vatNumber', 'email' => 'email', 'phone' => 'phone'],
        'contact' => ['email' => 'email', 'phone' => 'phone'],
        'product' => ['sku' => 'sku'],
        'vendor'  => ['kvk' => 'kvkNumber', 'vat' => 'vatNumber'],
    ];

    /**
     * Constructor.
     *
     * @param IRequest            $request     The request.
     * @param MdmObjectRepository $repository  The MDM object repository (OR-materialised reads).
     * @param IUserSession        $userSession The user session.
     */
    public function __construct(
        IRequest $request,
        private MdmObjectRepository $repository,
        private IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Query a master entity by masterId or alias, or by natural key.
     *
     * `GET /api/mdm/master?type={entityType}&kvk={value}` resolves by natural
     * key; `GET /api/mdm/master/{id}` resolves by master id or alias.
     *
     * @return JSONResponse The golden record or an error.
     *
     * @spec openspec/changes/master-data-management/specs.md#REQ-MDM-010
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) The method is a sequence of
     *  flat input-validation guards (auth, type, natural-key selection, match
     *  count) each returning a distinct HTTP status; the branches are linear.
     * @SuppressWarnings(PHPMD.NPathComplexity)      Same rationale: independent guard
     *  clauses, not nested logic.
     */
    #[NoAdminRequired]
    public function queryByNaturalKey(): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }

        $entityType = (string) $this->request->getParam('type', '');
        if ($entityType === '' || isset(self::NATURAL_KEYS[$entityType]) === false) {
            return new JSONResponse(['message' => 'Unknown or missing entity type'], Http::STATUS_BAD_REQUEST);
        }

        $matchAttribute = '';
        $matchValue     = '';
        foreach (self::NATURAL_KEYS[$entityType] as $param => $attribute) {
            $value = (string) $this->request->getParam($param, '');
            if ($value !== '') {
                $matchAttribute = $attribute;
                $matchValue     = $value;
                break;
            }
        }

        if ($matchAttribute === '') {
            return new JSONResponse(['message' => 'No supported natural key supplied'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $entities = $this->repository->findMasterEntities($entityType, 'active');
        } catch (\Throwable $e) {
            return new JSONResponse(['message' => 'Master entity lookup failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        $matches = array_values(
            array_filter(
                $entities,
                static fn (array $e): bool => ((string) ($e['goldenRecord'][$matchAttribute] ?? '') === $matchValue)
            )
        );

        if (empty($matches) === true) {
            return new JSONResponse(['message' => 'No master entity found for natural key'], Http::STATUS_NOT_FOUND);
        }

        if (count($matches) > 1) {
            return new JSONResponse(
                ['message' => 'Multiple master entities match this natural key; manual review required'],
                Http::STATUS_CONFLICT
            );
        }

        return new JSONResponse($this->present(entity: $matches[0]));
    }//end queryByNaturalKey()

    /**
     * Query a master entity by masterId or alias (pre-merge id).
     *
     * @param string $id The master id or alias.
     *
     * @return JSONResponse The golden record or an error.
     *
     * @spec openspec/changes/master-data-management/specs.md#REQ-MDM-010
     */
    #[NoAdminRequired]
    public function show(string $id): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $entity = $this->repository->findMasterEntity($id);
        } catch (\Throwable $e) {
            return new JSONResponse(['message' => 'Lookup failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        if ($entity !== null) {
            // Follow a merge pointer so a stale id resolves to the survivor.
            if ((string) ($entity['status'] ?? '') === 'merged-into-other'
                && (string) ($entity['mergedIntoMasterId'] ?? '') !== ''
            ) {
                $survivor = $this->repository->findMasterEntity(masterId: (string) $entity['mergedIntoMasterId']);
                if ($survivor !== null) {
                    return new JSONResponse(
                        $this->present(entity: $survivor) + [
                            'note'       => 'This masterId was merged; current masterId is '.(string) $survivor['masterId'],
                            'mergedFrom' => $id,
                        ]
                    );
                }
            }

            return new JSONResponse($this->present(entity: $entity));
        }

        // Not a live id — resolve as an alias of some surviving entity.
        $survivor = $this->resolveAlias(aliasId: $id);
        if ($survivor !== null) {
            return new JSONResponse(
                $this->present(entity: $survivor) + [
                    'note'       => 'This masterId was merged; current masterId is '.(string) $survivor['masterId'],
                    'mergedFrom' => $id,
                ]
            );
        }

        return new JSONResponse(['message' => 'Master entity not found'], Http::STATUS_NOT_FOUND);
    }//end show()

    /**
     * Resolve a pre-merge alias id to its current surviving entity.
     *
     * @param string $aliasId The alias id.
     *
     * @return array<string, mixed>|null The surviving entity, or null.
     */
    private function resolveAlias(string $aliasId): ?array
    {
        foreach ($this->repository->findMasterEntities(null, 'active') as $entity) {
            $aliases = (array) ($entity['aliases'] ?? []);
            if (in_array($aliasId, $aliases, true) === true) {
                return $entity;
            }
        }

        return null;
    }//end resolveAlias()

    /**
     * Project a master entity onto the public read-API shape.
     *
     * @param array<string, mixed> $entity The master entity.
     *
     * @return array<string, mixed> The public projection.
     */
    private function present(array $entity): array
    {
        return [
            'masterId'            => (string) ($entity['masterId'] ?? ($entity['id'] ?? '')),
            'entityType'          => (string) ($entity['entityType'] ?? ''),
            'goldenRecord'        => ($entity['goldenRecord'] ?? []),
            // OpenRegister materialises `qualityScore` on save (x-openregister-quality).
            // The app-side blend (DataQualityScorer + agreement term) is retired, so
            // the read API exposes OR's score directly under the stable key.
            'dataQualityScore'    => ($entity['qualityScore'] ?? ($entity['dataQualityScore'] ?? null)),
            'attributeProvenance' => ($entity['attributeProvenance'] ?? []),
            'aliases'             => ($entity['aliases'] ?? []),
            'status'              => (string) ($entity['status'] ?? ''),
        ];
    }//end present()
}//end class
