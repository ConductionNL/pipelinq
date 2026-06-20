<?php

/**
 * Pipelinq StufRequestIntegrationService.
 *
 * Thin integration helper called from Request / Contact workflows. Wraps
 * StufAdapterService so the calling sites stay free of SOAP detail: they
 * pass a Request or Contact entity (as a plain array) plus an endpoint id
 * and receive a structured outcome (success, mapping id, fout).
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Stuf
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#REQ-STUF-004
 * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#REQ-STUF-010
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Stuf;

use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Bridges pipelinq Request / Contact entities to the StUF adapter.
 */
class StufRequestIntegrationService
{
    /**
     * Constructor.
     *
     * @param StufAdapterService      $adapter       The StUF adapter.
     * @param StufRegisterAccess      $register      The register access helper.
     * @param ContactBetrokkeneMapper $contactMapper The contact mapper.
     * @param LoggerInterface         $logger        The logger.
     */
    public function __construct(
        private StufAdapterService $adapter,
        private StufRegisterAccess $register,
        private ContactBetrokkeneMapper $contactMapper,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Register a pipelinq Request as a zaak in the configured zaaksysteem.
     *
     * @param array  $request    The Request array (id, type, omschrijving, startdatum, betrokkenen, documenten).
     * @param string $endpointId The StufEndpoint id.
     * @param array  $opts       Options passed through to adapter.creeerZaak.
     *
     * @return array{success:bool,referentienummer:string,stufMessageId:string,zaakIdentificatie:?string,mappingId:?string,fout:?array<string,mixed>}
     *
     * @throws RuntimeException When the endpoint is not configured.
     *
     * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#REQ-STUF-004
     */
    public function registerZaak(array $request, string $endpointId, array $opts=[]): array
    {
        $endpoint = $this->register->findOne(
            schema: StufRegisterAccess::SCHEMA_ENDPOINT,
            filters: ['id' => $endpointId]
        );
        if ($endpoint === null) {
            throw new RuntimeException(message: 'StufEndpoint not found: '.$endpointId);
        }

        $result = $this->adapter->creeerZaak(request: $request, endpoint: $endpoint, opts: $opts);
        if ($result['success'] === false) {
            $this->logger->warning(
                message: 'StUF registerZaak failed for request {id}: {fout}',
                context: ['id' => ($request['id'] ?? ''), 'fout' => json_encode(value: ($result['fout'] ?? []))]
            );
        }

        return $result;
    }//end registerZaak()

    /**
     * Synchronise a pipelinq Contact to its zaaksysteem betrokkene.
     *
     * Calls the mapper's `findOrCreateBetrokkene` with a lookup callable
     * that queries the zaaksysteem (Lv01 geefBetrokkene) via the adapter's
     * vrijBericht-equivalent flow.
     *
     * @param array  $contact    The Contact array (must carry a BSN for natural-person lookup).
     * @param string $endpointId The StufEndpoint id.
     *
     * @return string The betrokkene identificatie (empty when none could be resolved/created).
     *
     * @throws RuntimeException When the endpoint is not configured.
     *
     * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#REQ-STUF-010
     */
    public function syncContactToBetrokkene(array $contact, string $endpointId): string
    {
        $endpoint = $this->register->findOne(
            schema: StufRegisterAccess::SCHEMA_ENDPOINT,
            filters: ['id' => $endpointId]
        );
        if ($endpoint === null) {
            throw new RuntimeException(message: 'StufEndpoint not found: '.$endpointId);
        }

        return $this->contactMapper->findOrCreateBetrokkene(
            contact: $contact,
            endpoint: $endpoint,
            lookupCallable: function (string $bsn, array $endpoint): ?string {
                try {
                    $result = $this->adapter->vrijBericht(
                        name: 'geefBetrokkene',
                        payload: ['inp.bsn' => $bsn],
                        endpoint: $endpoint
                    );
                    if ($result['success'] === true && isset($result['betrokkeneId']) === true) {
                        return (string) $result['betrokkeneId'];
                    }
                } catch (VrijBerichtNotRegisteredException $e) {
                    // Endpoint does not register geefBetrokkene — caller will embed full NPS in Lk01.
                    $this->logger->info(
                        message: 'StUF endpoint {id} has no geefBetrokkene template; embedding full NPS instead',
                        context: ['id' => ($endpoint['id'] ?? '')]
                    );
                } catch (StufException $e) {
                    $this->logger->warning(
                        message: 'StUF geefBetrokkene lookup failed: {error}',
                        context: ['error' => $e->getMessage()]
                    );
                }//end try

                return null;
            }
        );
    }//end syncContactToBetrokkene()
}//end class
