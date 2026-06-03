<?php

/**
 * Pipelinq BerichtenboxStatsService.
 *
 * Read-side aggregation and operator actions for the Berichtenbox bridge:
 * delivery-status counts for the admin dashboard and a guarded re-queue of a
 * failed message. No BSN material is read or returned.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#REQ-OPERATIONS-010
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Aggregates delivery stats and performs operator re-queues.
 *
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#REQ-OPERATIONS-010
 */
class BerichtenboxStatsService
{
    /**
     * Constructor.
     *
     * @param ContainerInterface $container The DI container.
     * @param IAppConfig         $appConfig The app config.
     * @param LoggerInterface    $logger    The logger.
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Return delivery-status aggregate counts.
     *
     * @return array<string, int> The counts keyed by status, plus queue depth.
     */
    public function stats(): array
    {
        $all     = $this->allMessages();
        $buckets = [
            'queued'           => 0,
            'sent'             => 0,
            'read'             => 0,
            'fallback-emailed' => 0,
            'failed'           => 0,
            'opted-out'        => 0,
            'unread'           => 0,
        ];

        foreach ($all as $message) {
            $status = (string) ($message['deliveryStatus'] ?? '');
            if (isset($buckets[$status]) === true) {
                $buckets[$status]++;
            }

            if ($status === 'sent' && ($message['readAt'] ?? '') === '') {
                $buckets['unread']++;
            }
        }

        $buckets['total'] = count($all);

        return $buckets;
    }//end stats()

    /**
     * Re-queue a failed message for another dispatch attempt.
     *
     * @param string $messageId The message ID.
     *
     * @return bool True when re-queued; false when not found or not retryable.
     */
    public function requeue(string $messageId): bool
    {
        [$register, $schema] = $this->config();

        try {
            $object = $this->getObjectService()->find(id: $messageId, register: $register, schema: $schema);
        } catch (Throwable $e) {
            return false;
        }

        if ($object === null) {
            return false;
        }

        $message = $this->toArray(object: $object);
        if ((string) ($message['deliveryStatus'] ?? '') !== 'failed') {
            return false;
        }

        unset($message['@self']);
        $message['deliveryStatus'] = 'queued';
        $message['retryCount']     = 0;
        $message['nextRetryAt']    = '';
        $message['failureReason']  = '';

        try {
            $this->getObjectService()->saveObject(
                object: $message,
                extend: [],
                register: $register,
                schema: $schema,
                uuid: $messageId
            );
        } catch (Throwable $e) {
            $this->logger->error('Berichtenbox: requeue save failed', ['exception' => $e->getMessage()]);
            return false;
        }

        return true;
    }//end requeue()

    /**
     * Fetch all messages.
     *
     * @return array<int, array<string, mixed>> The messages.
     */
    private function allMessages(): array
    {
        [$register, $schema] = $this->config();

        try {
            $results = $this->getObjectService()->findAll(
                config: ['filters' => ['register' => $register, 'schema' => $schema]]
            );
        } catch (Throwable $e) {
            $this->logger->warning('Berichtenbox: stats query failed', ['exception' => $e->getMessage()]);
            return [];
        }

        $out = [];
        foreach (($results ?? []) as $result) {
            $out[] = $this->toArray(object: $result);
        }

        return $out;
    }//end allMessages()

    /**
     * Resolve the register + berichtenboxMessage schema IDs.
     *
     * @return array{0: string, 1: string} The [register, schema] tuple.
     *
     * @throws RuntimeException When not configured.
     */
    private function config(): array
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, 'berichtenboxMessage_schema', '');
        if ($register === '' || $schema === '') {
            throw new RuntimeException('Berichtenbox message register or schema not configured.');
        }

        return [$register, $schema];
    }//end config()

    /**
     * Get the OpenRegister ObjectService.
     *
     * @return object The ObjectService.
     *
     * @throws RuntimeException When OpenRegister is unavailable.
     */
    private function getObjectService(): object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (Throwable $e) {
            throw new RuntimeException('OpenRegister service is not available.');
        }
    }//end getObjectService()

    /**
     * Normalise an OR object into a plain array.
     *
     * @param mixed $object The OR object.
     *
     * @return array<string, mixed> The object as an array.
     */
    private function toArray(mixed $object): array
    {
        if (is_array($object) === true) {
            return $object;
        }

        if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
            $serialized = $object->jsonSerialize();
            if (is_array($serialized) === true) {
                return $serialized;
            }
        }

        return (array) $object;
    }//end toArray()
}//end class
