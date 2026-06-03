<?php

/**
 * Pipelinq StufTransportInterface.
 *
 * Abstraction over the external StUF SOAP endpoint so the wire transport can be
 * mocked in tests; no live zaaksysteem is ever required to exercise the adapter.
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
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/stuf-zkn-bg-adapter/tasks.md#2.3
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Stuf;

/**
 * Contract for sending a SOAP envelope to an external StUF endpoint.
 *
 * The single seam between the adapter and the network: production wires this to
 * {@see StufHttpClient}; tests supply an in-memory fake returning canned XML.
 */
interface StufTransportInterface
{
    /**
     * POST a SOAP envelope to the endpoint and return the raw HTTP result.
     *
     * @param array<string, mixed> $endpoint       The resolved StufEndpoint config array.
     * @param string               $envelopeXml    The SOAP envelope to transmit.
     * @param int                  $timeoutSeconds The request timeout in seconds.
     *
     * @return array{httpStatus: int, responseXml: string, durationMs: int} The transport result.
     */
    public function send(array $endpoint, string $envelopeXml, int $timeoutSeconds=30): array;
}//end interface
