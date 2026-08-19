<?php

/**
 * Pipelinq CurlHttpTransport.
 *
 * Production HTTP transport used by the POS payment adapters when no other
 * transport is injected. Wraps the `curl_*` family with a 5-second timeout
 * (REQ-PAY-010 scenario: provider API timeout shows a user-facing connection
 * error). Always returns a result envelope — never throws — so the adapter
 * layer can map a non-2xx to a controller-friendly { status: failed, error }
 * shape without try/catch ceremony.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Payment
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Payment;

use Psr\Log\LoggerInterface;

/**
 * CURL-backed HTTP transport.
 *
 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-001
 */
class CurlHttpTransport implements HttpTransport
{
    /**
     * Request timeout in seconds.
     *
     * @var int
     */
    private const TIMEOUT = 5;

    /**
     * Constructor.
     *
     * @param LoggerInterface $logger The logger.
     */
    public function __construct(private LoggerInterface $logger)
    {
    }//end __construct()

    /**
     * Execute an HTTP request.
     *
     * @param string                $method  The HTTP method.
     * @param string                $url     The URL.
     * @param array<string, string> $headers The headers.
     * @param string|null           $body    The raw body.
     *
     * @return array{status: int, body: array<string, mixed>, raw: string}
     *
     * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-001
     */
    public function request(string $method, string $url, array $headers=[], ?string $body=null): array
    {
        // Tripwire. The adapters are handed AbstractPaymentAdapter::BROKER_MANAGED_SECRET
        // in place of a real PSP key; BrokerHttpTransport strips the resulting auth header
        // and the broker injects the real secret. If that placeholder ever reaches THIS
        // transport, the call has been routed around the broker — sending it would put a
        // meaningless bearer token on the wire and, worse, mean somebody has reintroduced
        // a direct, app-authenticated PSP call. Fail loudly rather than send it.
        foreach ($headers as $value) {
            if (is_string($value) === true
                && str_contains($value, AbstractPaymentAdapter::BROKER_MANAGED_SECRET) === true
            ) {
                $this->logger->error(
                    'Pipelinq POS payment: refusing to send a PSP request directly — it carries the '
                    .'broker-managed placeholder, which means it bypassed the credential broker.'
                );
                return [
                    'status' => 0,
                    'body'   => [],
                    'raw'    => '',
                ];
            }
        }

        if (function_exists('curl_init') === false) {
            $this->logger->warning('Pipelinq POS payment: cURL not available');
            return [
                'status' => 0,
                'body'   => [],
                'raw'    => '',
            ];
        }

        $handle = curl_init($url);
        if ($handle === false) {
            return [
                'status' => 0,
                'body'   => [],
                'raw'    => '',
            ];
        }

        $headerList = [];
        foreach ($headers as $name => $value) {
            $headerList[] = $name.': '.$value;
        }

        curl_setopt($handle, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_TIMEOUT, self::TIMEOUT);
        curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, self::TIMEOUT);
        curl_setopt($handle, CURLOPT_HTTPHEADER, $headerList);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $raw     = curl_exec($handle);
        $status  = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $rawText = '';
        if (is_string($raw) === true) {
            $rawText = $raw;
        }

        curl_close($handle);

        $decoded = [];
        if ($rawText !== '') {
            $maybe = json_decode($rawText, true);
            if (is_array($maybe) === true) {
                $decoded = $maybe;
            }
        }

        return [
            'status' => $status,
            'body'   => $decoded,
            'raw'    => $rawText,
        ];
    }//end request()
}//end class
