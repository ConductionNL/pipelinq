<?php

/**
 * Pipelinq ContactImportService.
 *
 * Service for importing Nextcloud contacts into Pipelinq as client or contact objects.
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
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;

/**
 * Service for importing Nextcloud contacts into Pipelinq objects.
 */
class ContactImportService
{
    /**
     * Constructor.
     *
     * @param IAppConfig         $appConfig   The app config.
     * @param ContainerInterface $container   The container.
     * @param ContactDataBuilder $dataBuilder The data builder.
     */
    public function __construct(
        private IAppConfig $appConfig,
        private ContainerInterface $container,
        private ContactDataBuilder $dataBuilder,
    ) {
    }//end __construct()

    /**
     * Import a Nextcloud contact as a Pipelinq client.
     *
     * @param array  $ncContact The Nextcloud contact data.
     * @param string $uid       The contact UID.
     *
     * @return array The created client object data.
     */
    public function importAsClient(array $ncContact, string $uid): array
    {
        $data     = $this->dataBuilder->buildClientImportData(
            ncContact: $ncContact,
            uid: $uid
        );
        $schemaId = $this->appConfig->getValueString(Application::APP_ID, 'client_schema', '');

        return $this->saveAndSerialize(data: $data, schemaId: $schemaId);
    }//end importAsClient()

    /**
     * Import a Nextcloud contact as a Pipelinq contact person.
     *
     * @param array   $ncContact The Nextcloud contact data.
     * @param string  $uid       The contact UID.
     * @param ?string $clientId  The optional client ID.
     *
     * @return array The created contact object data.
     */
    public function importAsContact(array $ncContact, string $uid, ?string $clientId): array
    {
        $data     = $this->dataBuilder->buildContactImportData(
            ncContact: $ncContact,
            uid: $uid,
            clientId: $clientId
        );
        $schemaId = $this->appConfig->getValueString(Application::APP_ID, 'contact_schema', '');

        return $this->saveAndSerialize(data: $data, schemaId: $schemaId);
    }//end importAsContact()

    /**
     * Save object data and return the serialized result.
     *
     * @param array  $data     The object data to save.
     * @param string $schemaId The schema ID.
     *
     * @return array The serialized result.
     */
    private function saveAndSerialize(array $data, string $schemaId): array
    {
        $objectService = $this->getObjectService();
        $registerId    = $this->appConfig->getValueString(Application::APP_ID, 'register', '');

        $created = $objectService->saveObject(
            $data,
                [],
                $registerId,
                $schemaId,
                null,
                _rbac: false,
                _multitenancy: false
        );

        return $this->serializeResult(result: $created);
    }//end saveAndSerialize()

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
