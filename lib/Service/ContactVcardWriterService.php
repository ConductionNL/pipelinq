<?php

/**
 * Pipelinq ContactVcardWriterService.
 *
 * Service for writing vCard data to Nextcloud addressbooks.
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
 * Service for writing vCard data to Nextcloud addressbooks.
 */
class ContactVcardWriterService
{
    /**
     * Constructor.
     *
     * @param IContactsManager   $contactsManager The contacts manager.
     * @param IAppConfig         $appConfig       The app config.
     * @param ContainerInterface $container       The container.
     * @param LoggerInterface    $logger          The logger.
     */
    public function __construct(
        private IContactsManager $contactsManager,
        private IAppConfig $appConfig,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Write vCard properties to the user's default addressbook.
     *
     * @param array  $properties The vCard properties.
     * @param array  $objData    The Pipelinq object data.
     * @param string $objectType The object type (client or contact).
     *
     * @return ?string The contacts UID or null.
     */
    public function writeToAddressBook(array $properties, array $objData, string $objectType): ?string
    {
        $addressBooks = $this->contactsManager->getUserAddressBooks();
        if (empty($addressBooks) === true) {
            $this->logger->debug('Pipelinq: No addressbooks available for sync');
            return null;
        }

        $addressBook = reset($addressBooks);

        $existingUid = $objData['contactsUid'] ?? null;
        if ($existingUid !== null && $existingUid !== '') {
            $properties['UID'] = $existingUid;
        }

        try {
            $result = $addressBook->createOrUpdate($properties);
        } catch (\Exception $e) {
            $this->logger->error(
                'Pipelinq: Failed to sync contact to addressbook',
                ['exception' => $e->getMessage()]
            );
            return null;
        }

        $contactsUid = $this->extractContactsUid(
            result: $result,
            existingUid: $existingUid
        );

        if ($contactsUid !== null && ($existingUid === null || $existingUid === '')) {
            $this->storeContactsUidOnObject(
                objData: $objData,
                contactsUid: $contactsUid,
                objectType: $objectType
            );
        }

        return $contactsUid;
    }//end writeToAddressBook()

    /**
     * Extract the contacts UID from an addressbook create/update result.
     *
     * @param mixed   $result      The result from createOrUpdate.
     * @param ?string $existingUid The existing UID if any.
     *
     * @return ?string The extracted contacts UID or null.
     */
    private function extractContactsUid(mixed $result, ?string $existingUid): ?string
    {
        if (is_array($result) === true && isset($result['UID']) === true) {
            return $result['UID'];
        }

        if (is_string($result) === true) {
            return $result;
        }

        if ($existingUid !== null && $existingUid !== '') {
            return $existingUid;
        }

        return null;
    }//end extractContactsUid()

    /**
     * Store the contactsUid back on the Pipelinq object.
     *
     * @param array  $objData     The object data.
     * @param string $contactsUid The contacts UID to store.
     * @param string $objectType  The object type (client or contact).
     *
     * @return void
     */
    private function storeContactsUidOnObject(array $objData, string $contactsUid, string $objectType): void
    {
        try {
            $objectService = $this->getObjectService();
            $registerId    = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
            $schemaId      = $this->appConfig->getValueString(Application::APP_ID, "{$objectType}_schema", '');

            $updateData = $objData;
            $updateData['contactsUid'] = $contactsUid;
            $objectService->saveObject(
                $updateData,
                    [],
                    $registerId,
                    $schemaId,
                    null,
                    _rbac: false,
                    _multitenancy: false
            );
        } catch (\Exception $e) {
            $this->logger->warning(
                'Pipelinq: Failed to store contactsUid back on object',
                ['exception' => $e->getMessage()]
            );
        }//end try
    }//end storeContactsUidOnObject()

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
