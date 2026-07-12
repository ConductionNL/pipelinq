<?php

/**
 * Pipelinq BrokerHttpTransport.
 *
 * Routes a payment adapter's outbound call through OpenRegister's credential broker
 * instead of making it directly.
 *
 * The PSP API keys used to live here: encrypted at rest with ICrypto, decrypted into
 * memory by PosPaymentService, handed to the adapter, and pasted into an `Authorization`
 * header by the adapter itself. Encryption at rest is worth having, but it is not
 * custody — Pipelinq could read the key, so Pipelinq was the trust boundary. A bug in
 * any adapter, or an exception that stringified the wrong array, exposes a key that can
 * move money.
 *
 * With this transport the adapter never sees the key. It builds the request it wants
 * (method, URL, body) exactly as before; this class hands {method, path, body} plus a
 * credential UUID to the broker, and the broker checks the owner, the allowed-app grant
 * and the immutable allow-rules, then injects the secret server-side.
 *
 * Two consequences worth stating out loud:
 *
 *   - The URL is reduced to a PATH. The host comes from the broker's host-lock, which is
 *     the point: an adapter that can name the host can name a different one. A rewritten
 *     base URL cannot exfiltrate the key, because the key is never on this side.
 *   - Any caller-supplied auth header is DROPPED. The broker discards them anyway, but
 *     dropping them here means a stale `Authorization: Bearer ` line can never look like
 *     it is doing something.
 *
 * `webhookSecret` deliberately does NOT come through here. It verifies an HMAC on an
 * INBOUND webhook — a local verify operation, not an outbound request header — so a
 * constrained HTTP proxy cannot carry it. It stays app-held until the broker grows a
 * sign/verify capability.
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
 * @spec openspec/changes/pos-psp-keys-via-broker/tasks.md#task-1-brokerhttptransport
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Payment;

use OCP\Server;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * HTTP transport that delegates the whole call — and the secret — to the broker.
 *
 * @spec openspec/changes/pos-psp-keys-via-broker/tasks.md#task-1-brokerhttptransport
 */
class BrokerHttpTransport implements HttpTransport
{
    /**
     * OpenRegister's credential broker. Resolved lazily so Pipelinq still boots on an
     * instance without OpenRegister.
     *
     * @var string
     */
    public const BROKER_CLASS = 'OCA\\OpenRegister\\Service\\Credential\\CredentialBrokerService';

    /**
     * The broker `appId` Pipelinq identifies itself with. Must match the credential's
     * `allowedApps` grant or the broker refuses the call.
     *
     * @var string
     */
    public const APP_ID = 'pipelinq';

    /**
     * Headers the broker owns. Whatever an adapter puts here is dropped: the broker
     * injects the real value from the vault and discards caller-supplied ones.
     *
     * @var array<int, string>
     */
    private const BROKER_OWNED_HEADERS = [
        'authorization',
        'x-api-key',
        'apikey',
    ];

    /**
     * Constructor.
     *
     * @param string          $credentialId Broker credential UUID. A reference, not a
     *                                      secret — this process cannot read the key
     *                                      behind it.
     * @param LoggerInterface $logger       The logger.
     * @param string|null     $actingUserId Credential owner. Needed on background/webhook
     *                                      paths, where there is no session for the
     *                                      broker's ownership guard to read.
     */
    public function __construct(
        private string $credentialId,
        private LoggerInterface $logger,
        private ?string $actingUserId=null,
    ) {
    }//end __construct()

    /**
     * Whether OpenRegister's credential broker is installed.
     *
     * @return bool True when the broker class can be resolved.
     *
     * @spec openspec/changes/pos-psp-keys-via-broker/tasks.md#task-1-brokerhttptransport
     */
    public static function isAvailable(): bool
    {
        return class_exists(self::BROKER_CLASS) === true;
    }//end isAvailable()

    /**
     * Execute the request through the broker.
     *
     * @param string                $method  The HTTP method.
     * @param string                $url     The full URL the adapter wants to call. Only
     *                                       its path + query survive; the host is the
     *                                       broker's host-lock.
     * @param array<string, string> $headers Request headers, minus the ones the broker owns.
     * @param string|null           $body    Optional raw request body.
     *
     * @return array{status: int, body: array<string, mixed>, raw: string}
     *
     * @spec openspec/changes/pos-psp-keys-via-broker/tasks.md#task-1-brokerhttptransport
     */
    public function request(string $method, string $url, array $headers=[], ?string $body=null): array
    {
        $empty = [
            'status' => 0,
            'body'   => [],
            'raw'    => '',
        ];

        if (self::isAvailable() === false) {
            // Fail closed. There is no direct-cURL fallback here on purpose: falling back
            // would mean falling back to an app-held key, which no longer exists.
            $this->logger->error(
                'Pipelinq POS payment: the OpenRegister credential broker is not available; refusing to call the PSP.'
            );
            return $empty;
        }

        if ($this->credentialId === '') {
            $this->logger->error('Pipelinq POS payment: no broker credential configured for this provider.');
            return $empty;
        }

        try {
            $broker   = Server::get(self::BROKER_CLASS);
            $response = $broker->request(
                $this->credentialId,
                self::APP_ID,
                $method,
                $this->toPath(url: $url),
                $this->stripBrokerOwnedHeaders(headers: $headers),
                $body,
                $this->actingUserId
            );
        } catch (Throwable $e) {
            // The broker never puts the secret in its exception messages, and neither do
            // we: only the method and path are logged, never the body (which can carry
            // card/customer data) and never the credential.
            $this->logger->warning(
                'Pipelinq POS payment: broker call failed',
                [
                    'method' => $method,
                    'path'   => $this->toPath(url: $url),
                ]
            );
            return $empty;
        }//end try

        $status = (int) ($response['status'] ?? 0);
        $raw    = (string) ($response['body'] ?? '');

        $decoded = json_decode($raw, true);
        if (is_array($decoded) === false) {
            $decoded = [];
        }

        return [
            'status' => $status,
            'body'   => $decoded,
            'raw'    => $raw,
        ];
    }//end request()

    /**
     * Reduce a full URL to the path + query the broker expects.
     *
     * The broker prepends its own host-locked base URL, so anything we send before the
     * path is discarded — passing the whole URL would silently produce a doubled host.
     *
     * @param string $url The full URL.
     *
     * @return string The path, with the query string when present.
     */
    private function toPath(string $url): string
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        if ($path === '') {
            $path = '/';
        }

        $query = parse_url($url, PHP_URL_QUERY);
        if (is_string($query) === true && $query !== '') {
            $path .= '?'.$query;
        }

        return $path;
    }//end toPath()

    /**
     * Drop the headers the broker owns.
     *
     * @param array<string, string> $headers The adapter's headers.
     *
     * @return array<string, string> The headers with any auth header removed.
     */
    private function stripBrokerOwnedHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $value) {
            if (in_array(strtolower((string) $name), self::BROKER_OWNED_HEADERS, true) === true) {
                continue;
            }

            $out[$name] = $value;
        }

        return $out;
    }//end stripBrokerOwnedHeaders()
}//end class
