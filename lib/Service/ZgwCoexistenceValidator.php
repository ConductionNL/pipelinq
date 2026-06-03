<?php

/**
 * Pipelinq ZgwCoexistenceValidator.
 *
 * Enforces that at most one zaaksysteem write path (StUF adapter OR ZGW bridge)
 * is active for a gemeente at a time, so a single pipelinq Request cannot be
 * double-registered in two backends during a StUF→ZGW migration. Read-only
 * coexistence is allowed. The StUF side is a soft dependency: when the
 * stuf-zkn-bg-adapter is not installed its schema config key is absent and StUF
 * is treated as "no write path" (no conflict) (REQ-ZGW-008).
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md#REQ-ZGW-008
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Exception\DoubleWritePathException;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Validates StUF/ZGW write-path coexistence for a gemeente.
 *
 * @spec openspec/changes/zgw-api-bridge/tasks.md#4.1
 */
class ZgwCoexistenceValidator
{
    /**
     * Constructor.
     *
     * @param ZgwObjectRepository $repository The ZGW object persistence helper.
     * @param IAppConfig          $appConfig  The app config (StUF schema discovery).
     * @param ContainerInterface  $container  The container (OpenRegister ObjectService).
     * @param LoggerInterface     $logger     The logger.
     */
    public function __construct(
        private ZgwObjectRepository $repository,
        private IAppConfig $appConfig,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Assert a single active write path for the gemeente, or raise.
     *
     * @param string $gemeenteCode The 4-digit gemeente code.
     *
     * @return void
     *
     * @throws DoubleWritePathException When both StUF and ZGW have an active write endpoint.
     */
    public function validateWritePath(string $gemeenteCode): void
    {
        $zgwWriteEndpoints  = $this->activeZgwWriteEndpoints(gemeenteCode: $gemeenteCode);
        $stufWriteEndpoints = $this->activeStufWriteEndpoints(gemeenteCode: $gemeenteCode);

        if (count($zgwWriteEndpoints) === 0 || count($stufWriteEndpoints) === 0) {
            return;
        }

        $conflicting = array_merge($zgwWriteEndpoints, $stufWriteEndpoints);

        throw new DoubleWritePathException(
            message: sprintf(
                'Dubbele schrijf-koppeling voor gemeente %s: zowel een StUF- als een ZGW-endpoint '
                .'staat actief (write="on"). Schakel precies één schrijf-pad uit in '
                .'Beheer → Integraties voordat een nieuwe zaak wordt geregistreerd. '
                .'Conflicterende endpoints: %s.',
                $gemeenteCode,
                implode(', ', $conflicting)
            ),
            gemeenteCode: $gemeenteCode,
            conflictingEndpoints: $conflicting
        );
    }//end validateWritePath()

    /**
     * List the ids of active, writable ZGW endpoints for a gemeente.
     *
     * @param string $gemeenteCode The gemeente code.
     *
     * @return array<int, string> The endpoint ids.
     */
    private function activeZgwWriteEndpoints(string $gemeenteCode): array
    {
        $endpoints = $this->repository->findBy(
            entity: 'zgwEndpoint',
            filters: ['gemeenteCode' => $gemeenteCode]
        );

        $ids = [];
        foreach ($endpoints as $endpoint) {
            $actief   = (bool) ($endpoint['actief'] ?? false);
            $readOnly = (bool) ($endpoint['readOnly'] ?? false);
            if ($actief === true && $readOnly === false) {
                $ids[] = 'zgw:'.((string) ($endpoint['id'] ?? ''));
            }
        }

        return $ids;
    }//end activeZgwWriteEndpoints()

    /**
     * List the ids of active StUF endpoints for a gemeente (soft dependency).
     *
     * Returns an empty list when the stuf-zkn-bg-adapter is not installed (its
     * schema config key is absent), so a ZGW-only deployment never conflicts.
     *
     * @param string $gemeenteCode The gemeente code.
     *
     * @return array<int, string> The endpoint ids.
     */
    private function activeStufWriteEndpoints(string $gemeenteCode): array
    {
        $register   = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $stufSchema = $this->appConfig->getValueString(Application::APP_ID, 'stufEndpoint_schema', '');

        if ($register === '' || $stufSchema === '') {
            // StUF adapter not installed: no StUF write path can exist.
            return [];
        }

        try {
            $results = $this->container->get('OCA\OpenRegister\Service\ObjectService')->findAll(
                [
                    'filters' => [
                        'register'     => $register,
                        'schema'       => $stufSchema,
                        'gemeenteCode' => $gemeenteCode,
                        'actief'       => true,
                    ],
                    'limit'   => 999,
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'ZgwCoexistenceValidator: StUF endpoint lookup failed',
                ['exception' => $e->getMessage()]
            );
            return [];
        }

        $ids = [];
        foreach ($results as $result) {
            $endpoint = $this->repository->toArray(object: $result);
            $ids[]    = 'stuf:'.((string) ($endpoint['id'] ?? ''));
        }

        return $ids;
    }//end activeStufWriteEndpoints()
}//end class
