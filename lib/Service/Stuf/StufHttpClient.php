<?php

/**
 * Pipelinq StufHttpClient.
 *
 * SOAP-over-HTTP transport for StUF envelopes: loads mutual-TLS client cert and
 * WSSE credentials from the vault, POSTs the envelope, and returns timing data.
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
 * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#req-stuf-011
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Stuf;

use OCA\Pipelinq\Exception\StufTransportException;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Default {@see StufTransportInterface} implementation over Nextcloud's HTTP client.
 *
 * WSSE injection happens in the envelope header (built upstream); this client is
 * responsible for the SOAP 1.1 HTTP framing, the mutual-TLS client certificate,
 * and the timeout. Server certificate verification is ALWAYS enabled — there is
 * no code path that sets `verify => false` (ADR-005).
 */
class StufHttpClient implements StufTransportInterface
{
    /**
     * Constructor.
     *
     * @param IClientService         $clientService The HTTP client service.
     * @param StufCredentialResolver $credentials   The vault credential resolver.
     * @param LoggerInterface        $logger        The logger.
     */
    public function __construct(
        private IClientService $clientService,
        private StufCredentialResolver $credentials,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * POST a SOAP envelope to the endpoint and return the raw HTTP result.
     *
     * @param array<string, mixed> $endpoint       The resolved StufEndpoint config array.
     * @param string               $envelopeXml    The SOAP envelope to transmit.
     * @param int                  $timeoutSeconds The request timeout in seconds.
     *
     * @return array{httpStatus: int, responseXml: string, durationMs: int} The transport result.
     *
     * @throws StufTransportException If the endpoint URL is missing or the transport fails.
     */
    public function send(array $endpoint, string $envelopeXml, int $timeoutSeconds=30): array
    {
        $url = (string) ($endpoint['endpointUrl'] ?? '');
        if ($url === '') {
            throw new StufTransportException(message: 'StUF endpoint has no endpointUrl configured.');
        }

        $options = $this->buildOptions(endpoint: $endpoint, envelopeXml: $envelopeXml, timeoutSeconds: $timeoutSeconds);

        $this->logger->debug(
            'StUF HTTP POST',
            ['uri' => $url, 'timeout' => $timeoutSeconds, 'bytes' => strlen($envelopeXml)]
        );

        $start = microtime(true);
        try {
            $client   = $this->clientService->newClient();
            $response = $client->post($url, $options);
        } catch (Throwable $e) {
            $durationMs = (int) round(((microtime(true) - $start) * 1000));
            $this->logger->warning(
                'StUF HTTP transport error',
                ['uri' => $url, 'durationMs' => $durationMs, 'exception' => $e]
            );
            throw new StufTransportException(
                message: 'StUF transport failed: '.$e->getMessage(),
                code: (int) $e->getCode(),
                previous: $e
            );
        }

        $durationMs  = (int) round(((microtime(true) - $start) * 1000));
        $body        = $response->getBody();
        $responseXml = '';
        if (is_string($body) === true) {
            $responseXml = $body;
        }

        $this->logger->debug(
            'StUF HTTP response',
            ['uri' => $url, 'status' => $response->getStatusCode(), 'durationMs' => $durationMs]
        );

        return [
            'httpStatus'  => $response->getStatusCode(),
            'responseXml' => $responseXml,
            'durationMs'  => $durationMs,
        ];
    }//end send()

    /**
     * Build the HTTP client options including SOAP headers and mutual-TLS cert.
     *
     * @param array<string, mixed> $endpoint       The resolved endpoint config.
     * @param string               $envelopeXml    The SOAP envelope body.
     * @param int                  $timeoutSeconds The request timeout.
     *
     * @return array<string, mixed> The IClient post options.
     *
     * @throws StufTransportException If a configured TLS cert cannot be resolved.
     */
    private function buildOptions(array $endpoint, string $envelopeXml, int $timeoutSeconds): array
    {
        $soapVersion = (string) ($endpoint['soapVersion'] ?? '1.1');
        $contentType = 'text/xml; charset=utf-8';
        if ($soapVersion === '1.2') {
            $contentType = 'application/soap+xml; charset=utf-8';
        }

        $options = [
            'body'    => $envelopeXml,
            'headers' => [
                'Content-Type' => $contentType,
                'SOAPAction'   => '""',
            ],
            'timeout' => $timeoutSeconds,
            // Server certificate verification is mandatory and never disabled.
            'verify'  => true,
        ];

        $certRef = (string) ($endpoint['tlsClientCertRef'] ?? '');
        if ($certRef !== '') {
            $options['cert'] = $this->materialiseClientCert(certRef: $certRef);
        }

        return $options;
    }//end buildOptions()

    /**
     * Materialise the vault-stored client certificate to a temp file for cURL.
     *
     * @param string $certRef The vault reference to the PEM certificate.
     *
     * @return string The temp file path holding the PEM certificate.
     *
     * @throws StufTransportException If the cert cannot be resolved or written.
     */
    private function materialiseClientCert(string $certRef): string
    {
        $pem = $this->credentials->resolve(reference: $certRef);
        if ($pem === null) {
            $this->logger->error(
                'StUF mutual-TLS certificate reference set but no certificate found in vault',
                ['certRef' => $certRef]
            );
            throw new StufTransportException(message: 'Mutual-TLS certificate could not be loaded from vault.');
        }

        $path = tempnam(sys_get_temp_dir(), 'stuf-cert-');
        if ($path === false || file_put_contents($path, $pem) === false) {
            throw new StufTransportException(message: 'Could not materialise mutual-TLS certificate.');
        }

        chmod($path, 0600);

        return $path;
    }//end materialiseClientCert()
}//end class
