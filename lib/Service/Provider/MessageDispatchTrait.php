<?php

/**
 * Pipelinq MessageDispatchTrait.
 *
 * Shared transport leg for the outbound messaging clients (Twilio,
 * MessageBird, CM.com, WhatsApp). Routes a vendor-shaped payload through
 * OpenRegister's `MessageDispatchProvider` leaf (per ADR-022 — the
 * connection + credentials live in OpenConnector, addressed by a source
 * slug) and maps the leaf's degrade-don't-throw result back onto the
 * Transient/Permanent provider-exception contract the adapters rely on.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Provider
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/pipelinq-messaging-via-or-leaf/tasks.md#3.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Provider;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Routes a vendor-shaped payload through OpenRegister's MessageDispatchProvider.
 *
 * Consuming clients MUST expose a `ContainerInterface $container` and a
 * `LoggerInterface $logger` property (the SMS clients and WhatsAppProviderClient
 * already do).
 *
 * @spec openspec/changes/pipelinq-messaging-via-or-leaf/tasks.md#3.1
 */
trait MessageDispatchTrait
{

    /**
     * Fully-qualified class name of the OpenRegister dispatch leaf.
     *
     * @var string
     */
    private static string $dispatchProviderClass = 'OCA\\OpenRegister\\Service\\Integration\\Providers\\MessageDispatchProvider';

    /**
     * Dispatch a vendor-shaped payload through the OpenRegister leaf.
     *
     * Resolves `MessageDispatchProvider` from the container (guarded by
     * `class_exists` + container-miss → a {@see PermanentSmsProviderException}
     * mirroring today's missing-transport guard, so the failover loop is
     * unchanged on an OR-leaf-absent instance), calls
     * `dispatch(source, body, path, headers)`, and returns the raw provider
     * `response`. A degraded `{ unavailable, cause }` leaf result is mapped to
     * the matching provider-exception type so `SmsAdapter` / `WhatsAppAdapter`
     * failover behaves identically to before the re-point.
     *
     * @param string                $source  OpenConnector source slug (e.g. `twilio-sms`).
     * @param array<string, mixed>  $body    Vendor-shaped request payload.
     * @param string                $path    Vendor send path, relative to the source base URL.
     * @param array<string, string> $headers Optional extra request headers.
     *
     * @return array<string, mixed> The raw upstream provider response.
     *
     * @throws TransientSmsProviderException On a transient degrade (failover-eligible).
     * @throws PermanentSmsProviderException On a permanent degrade / missing leaf (no failover).
     *
     * @spec openspec/changes/pipelinq-messaging-via-or-leaf/tasks.md#3.1
     */
    private function dispatchViaLeaf(string $source, array $body, string $path, array $headers=[]): array
    {
        if ($source === '') {
            throw new PermanentSmsProviderException('messaging source slug not configured');
        }

        $provider = $this->resolveDispatchProvider();

        try {
            $result = $provider->dispatch(source: $source, body: $body, path: $path, headers: $headers);
        } catch (TransientSmsProviderException | PermanentSmsProviderException $e) {
            throw $e;
        } catch (Throwable $e) {
            // The leaf is documented never to throw (AD-23); treat any leak as
            // transient so the adapter can still fail over rather than fatal.
            $this->logger->warning(
                'MessageDispatchTrait.dispatchViaLeaf: leaf threw unexpectedly',
                ['message' => $e->getMessage()]
            );
            throw new TransientSmsProviderException($e->getMessage(), 0, $e);
        }

        if (is_array($result) === false) {
            return [];
        }

        if (($result['unavailable'] ?? false) === true) {
            $this->throwForCause(cause: (string) ($result['cause'] ?? ''));
        }

        $response = $result['response'] ?? [];
        if (is_array($response) === true) {
            return $response;
        }

        return [];
    }//end dispatchViaLeaf()

    /**
     * Resolve the OpenRegister MessageDispatchProvider from the container.
     *
     * @return object The dispatch provider (exposes `dispatch()`).
     *
     * @throws PermanentSmsProviderException When the leaf class / service is unavailable.
     *
     * @spec openspec/changes/pipelinq-messaging-via-or-leaf/tasks.md#3.1
     */
    private function resolveDispatchProvider(): object
    {
        if (class_exists(self::$dispatchProviderClass) === false) {
            throw new PermanentSmsProviderException('OpenRegister MessageDispatchProvider not available');
        }

        try {
            $provider = $this->container->get(self::$dispatchProviderClass);
        } catch (Throwable $e) {
            throw new PermanentSmsProviderException('OpenRegister MessageDispatchProvider not available: '.$e->getMessage());
        }

        if (is_object($provider) === false || method_exists($provider, 'dispatch') === false) {
            throw new PermanentSmsProviderException('OpenRegister MessageDispatchProvider lacks dispatch');
        }

        return $provider;
    }//end resolveDispatchProvider()

    /**
     * Map a leaf degrade `cause` onto the adapter's exception contract.
     *
     * `openconnector-source-missing` / `provider-auth` → permanent (no
     * failover, mirroring today's "source not configured" / 4xx). Everything
     * else (`openconnector-down`, `upstream-service-down`, unknown) → transient
     * (failover-eligible, mirroring today's "openconnector unavailable" / 5xx).
     *
     * @param string $cause The ProviderUnavailableException CAUSE_* value.
     *
     * @return never
     *
     * @throws TransientSmsProviderException On a transient cause.
     * @throws PermanentSmsProviderException On a permanent cause.
     *
     * @spec openspec/changes/pipelinq-messaging-via-or-leaf/tasks.md#3.1
     */
    private function throwForCause(string $cause): never
    {
        $this->logger->warning(
            'MessageDispatchTrait: dispatch degraded',
            ['cause' => $cause]
        );

        if ($cause === 'openconnector-source-missing' || $cause === 'provider-auth') {
            throw new PermanentSmsProviderException('messaging source unavailable: '.$cause);
        }

        throw new TransientSmsProviderException('messaging source unavailable: '.$cause);
    }//end throwForCause()
}//end trait
