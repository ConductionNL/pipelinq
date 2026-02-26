<?php

/**
 * Pipelinq ContactVcardService.
 *
 * Service for syncing Pipelinq objects to Nextcloud Contacts as vCards.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\Contacts\IManager as IContactsManager;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for syncing Pipelinq objects to Nextcloud Contacts as vCards.
 */
class ContactVcardService
{
    /**
     * Constructor.
     *
     * @param IContactsManager            $contactsManager The contacts manager.
     * @param IAppConfig                  $appConfig       The app config.
     * @param ContainerInterface          $container       The container.
     * @param ContactVcardWriterService   $writerService   The vCard writer.
     * @param ContactVcardPropertyBuilder $propBuilder     The property builder.
     * @param LoggerInterface             $logger          The logger.
     */
    public function __construct(
        private IContactsManager $contactsManager,
        private IAppConfig $appConfig,
        private ContainerInterface $container,
        private ContactVcardWriterService $writerService,
        private ContactVcardPropertyBuilder $propBuilder,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Sync a Pipelinq client or contact to Nextcloud Contacts.
     *
     * @param string $objectType The object type (client or contact).
     * @param string $objectId   The object ID.
     *
     * @return ?string The contacts UID or null.
     */
    public function syncToContacts(string $objectType, string $objectId): ?string
    {
        if ($this->contactsManager->isEnabled() === false) {
            $this->logger->debug('Pipelinq: Contacts sync skipped -- IManager not available');
            return null;
        }

        $objData = $this->fetchPipelinqObject(
            objectType: $objectType,
            objectId: $objectId
        );

        if ($objData === null) {
            return null;
        }

        $properties = $this->propBuilder->buildProperties(
            objData: $objData,
            objectType: $objectType
        );

        return $this->writerService->writeToAddressBook(
            properties: $properties,
            objData: $objData,
            objectType: $objectType
        );
    }//end syncToContacts()

    /**
     * Fetch a Pipelinq object by type and ID.
     *
     * @param string $objectType The object type (client or contact).
     * @param string $objectId   The object ID.
     *
     * @return ?array The serialized object data or null on failure.
     */
    private function fetchPipelinqObject(string $objectType, string $objectId): ?array
    {
        $objectService = $this->getObjectService();
        $registerId    = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schemaId      = $this->appConfig->getValueString(Application::APP_ID, "{$objectType}_schema", '');

        if ($registerId === '' || $schemaId === '') {
            $this->logger->warning('Pipelinq: Cannot sync -- register or schema not configured');
            return null;
        }

        try {
            $object = $objectService->findObject(
                $objectId,
                    $registerId,
                    $schemaId,
                    _rbac: false,
                    _multitenancy: false
            );
        } catch (\Exception $e) {
            $this->logger->error('Pipelinq: Failed to fetch object for sync', ['exception' => $e->getMessage()]);
            return null;
        }

        return $this->serializeResult(result: $object);
    }//end fetchPipelinqObject()

    /**
     * Serialize an object or array result to an array.
     *
     * @param mixed $result The result to serialize.
     *
     * @return array The serialized result.
     */
    private function serializeResult(mixed $result): array
    {
        if (is_object($result) === true && method_exists($result, 'jsonSerialize') === true) {
            return $result->jsonSerialize();
        }

        if (is_array($result) === true) {
            return $result;
        }

        return [];
    }//end serializeResult()

    /**
     * Get the OpenRegister ObjectService via the container.
     *
     * @return object The object service.
     */
    private function getObjectService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');
    }//end getObjectService()
}//end class
