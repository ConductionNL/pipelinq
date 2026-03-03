<?php

/**
 * Pipelinq ContactVcardPropertyBuilder.
 *
 * Service for building vCard properties from Pipelinq object data.
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
 * Service for building vCard properties from Pipelinq object data.
 */
class ContactVcardPropertyBuilder
{
    /**
     * Constructor.
     *
     * @param IAppConfig         $appConfig The app config.
     * @param ContainerInterface $container The container.
     */
    public function __construct(
        private IAppConfig $appConfig,
        private ContainerInterface $container,
    ) {
    }//end __construct()

    /**
     * Build vCard properties from Pipelinq object data.
     *
     * @param array  $objData    The object data.
     * @param string $objectType The object type (client or contact).
     *
     * @return array The vCard properties.
     */
    public function buildProperties(array $objData, string $objectType): array
    {
        $name       = $objData['name'] ?? 'Unknown';
        $properties = ['FN' => $name];

        if (empty($objData['email']) === false) {
            $properties['EMAIL'] = $objData['email'];
        }

        if (empty($objData['phone']) === false) {
            $properties['TEL'] = $objData['phone'];
        }

        if ($objectType === 'client') {
            $properties = $this->addClientProperties(
                properties: $properties,
                objData: $objData,
                name: $name
            );
        }

        if ($objectType === 'contact') {
            $properties = $this->addContactProperties(
                properties: $properties,
                objData: $objData
            );
        }

        return $properties;
    }//end buildProperties()

    /**
     * Add client-specific vCard properties.
     *
     * @param array  $properties The existing properties.
     * @param array  $objData    The object data.
     * @param string $name       The client name.
     *
     * @return array The updated properties.
     */
    private function addClientProperties(array $properties, array $objData, string $name): array
    {
        if (($objData['type'] ?? '') === 'organization') {
            $properties['ORG'] = $name;
        }

        if (empty($objData['website']) === false) {
            $properties['URL'] = $objData['website'];
        }

        if (empty($objData['address']) === false) {
            $properties['ADR'] = $objData['address'];
        }

        if (empty($objData['notes']) === false) {
            $properties['NOTE'] = $objData['notes'];
        }

        return $properties;
    }//end addClientProperties()

    /**
     * Add contact-specific vCard properties.
     *
     * @param array $properties The existing properties.
     * @param array $objData    The object data.
     *
     * @return array The updated properties.
     */
    private function addContactProperties(array $properties, array $objData): array
    {
        if (empty($objData['role']) === false) {
            $properties['ROLE'] = $objData['role'];
        }

        if (empty($objData['client']) === false) {
            $orgName = $this->resolveClientName(clientId: $objData['client']);
            if ($orgName !== null) {
                $properties['ORG'] = $orgName;
            }
        }

        return $properties;
    }//end addContactProperties()

    /**
     * Resolve a client UUID to its name.
     *
     * @param string $clientId The client ID to resolve.
     *
     * @return ?string The client name or null.
     */
    private function resolveClientName(string $clientId): ?string
    {
        $objectService = $this->getObjectService();
        $registerId    = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schemaId      = $this->appConfig->getValueString(Application::APP_ID, 'client_schema', '');

        if ($registerId === '' || $schemaId === '') {
            return null;
        }

        try {
            $client = $objectService->findObject(
                $clientId,
                    $registerId,
                    $schemaId,
                    _rbac: false,
                    _multitenancy: false
            );
            $data   = $this->serializeResult(result: $client);
            return $data['name'] ?? null;
        } catch (\Exception $e) {
            return null;
        }
    }//end resolveClientName()

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
