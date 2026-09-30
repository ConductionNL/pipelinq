<?php

/**
 * CallerObjectReader: read pipelinq objects through OpenRegister, failing closed.
 *
 * One object by id or a filtered list, from this app's register and the schema
 * an app-config key names. Missing configuration, an unknown id or an
 * OpenRegister error all read as "nothing", so a guard built on it denies
 * rather than guesses. Existence is not authorization: OpenRegister answers a
 * read whatever `_rbac` says, so the caller still decides who may act (see
 * ObjectOwnerAccessPolicy).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/client-letters/spec.md#requirement-a-user-makes-a-letter-for-a-client-from-a-filinq-template-req-wlt-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads objects of this app's register, failing closed.
 *
 * @spec openspec/specs/client-letters/spec.md#requirement-a-user-makes-a-letter-for-a-client-from-a-filinq-template-req-wlt-001
 */
class CallerObjectReader
{


    /**
     * Constructor.
     *
     * @param ContainerInterface $container OpenRegister's ObjectService.
     * @param IAppConfig         $appConfig The register and schema ids.
     * @param LoggerInterface    $logger    Logger.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
    ) {

    }//end __construct()


    /**
     * This app's register id, or '' when unconfigured.
     *
     * @return string The register id.
     *
     * @spec openspec/specs/client-letters/spec.md#requirement-a-user-makes-a-letter-for-a-client-from-a-filinq-template-req-wlt-001
     */
    public function register(): string
    {
        return $this->appConfig->getValueString(Application::APP_ID, 'register', '');

    }//end register()


    /**
     * One object's data, or null.
     *
     * @param string $id        The object id.
     * @param string $schemaKey The app config key holding the schema id, e.g. `client_schema`.
     *
     * @return array<string, mixed>|null The object's data.
     *
     * @spec openspec/specs/client-letters/spec.md#requirement-a-user-makes-a-letter-for-a-client-from-a-filinq-template-req-wlt-001
     */
    public function read(string $id, string $schemaKey): ?array
    {
        $register = $this->register();
        $schema   = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');
        if ($register === '' || $schema === '' || $id === '') {
            return null;
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $object        = $objectService->find(id: $id, register: $register, schema: $schema, _rbac: true);
        } catch (Throwable $e) {
            $this->logger->warning(
                'Pipelinq: a read-guard refused an object',
                ['app' => Application::APP_ID, 'id' => $id, 'exception' => $e->getMessage()]
            );
            return null;
        }

        if ($object === null) {
            return null;
        }

        return self::data(object: $object);

    }//end read()


    /**
     * The data of an OpenRegister object, whether it came back as an entity or an array.
     *
     * @param mixed $object The object.
     *
     * @return array<string, mixed> Its data.
     */
    private static function data(mixed $object): array
    {
        if (is_array($object) === true) {
            return $object;
        }

        if (is_object($object) === true && method_exists($object, 'getObject') === true) {
            return (array) $object->getObject();
        }

        if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
            return (array) $object->jsonSerialize();
        }

        return [];

    }//end data()


}//end class
