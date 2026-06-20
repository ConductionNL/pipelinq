<?php

/**
 * Pipelinq LoyaltyProgrammeService.
 *
 * Owns LoyaltyProgramme lifecycle. Activation transitions from concept to actief
 * trigger validation of rules and redemption options (REQ-LOY-001).
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
 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Programme activation + validation service.
 *
 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-001
 */
class LoyaltyProgrammeService
{
    /**
     * Constructor.
     *
     * @param ContainerInterface $container The DI container.
     * @param IAppConfig         $appConfig The app configuration.
     * @param LoggerInterface    $logger    The logger.
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Validate a programme for activation.
     *
     * @param string $programmeId The programme UUID.
     *
     * @return array<int, string> List of validation errors (empty when valid).
     *
     * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-001-01
     */
    public function validateForActivation(string $programmeId): array
    {
        $errors    = [];
        $programme = $this->getProgramme(programmeId: $programmeId);
        if ($programme === null) {
            return ['Programme not found.'];
        }

        // Date range.
        $start = (string) ($programme['startdatum'] ?? '');
        $end   = (string) ($programme['einddatum'] ?? '');
        if ($start !== '' && $end !== '' && $start > $end) {
            $errors[] = 'Cannot activate: einddatum must be after startdatum';
        }

        // At least one rule.
        $rules = $this->countByProgramme(schemaKey: 'pointsRule_schema', programmeId: $programmeId);
        if ($rules === 0) {
            $errors[] = 'Cannot activate: no points rules configured';
        }

        // At least one redemption option.
        $options = $this->countByProgramme(schemaKey: 'redemptionOption_schema', programmeId: $programmeId);
        if ($options === 0) {
            $errors[] = 'Cannot activate: no redemption options configured';
        }

        return $errors;
    }//end validateForActivation()

    /**
     * Activate a programme (transition concept -> actief).
     *
     * @param string $programmeId The programme UUID.
     * @param string $activatedBy The user id who triggered activation.
     *
     * @return array<string, mixed> The activated programme.
     *
     * @throws RuntimeException When validation fails.
     */
    public function activate(string $programmeId, string $activatedBy): array
    {
        $errors = $this->validateForActivation(programmeId: $programmeId);
        if ($errors !== []) {
            throw new RuntimeException(implode('; ', $errors));
        }

        $programme = $this->getProgramme(programmeId: $programmeId);
        if ($programme === null) {
            throw new RuntimeException('Programme not found.');
        }

        $programme['status'] = 'actief';
        $this->logger->info(
            'Pipelinq: loyalty programme activated',
            ['programmeId' => $programmeId, 'activatedBy' => $activatedBy]
        );

        return $this->persist(payload: $programme, uuid: $programmeId);
    }//end activate()

    /**
     * Get a programme by UUID.
     *
     * @param string $programmeId The programme UUID.
     *
     * @return array<string, mixed>|null
     */
    public function getProgramme(string $programmeId): ?array
    {
        [$register, $schema] = $this->config(schemaKey: 'loyaltyProgramme_schema');
        if ($register === '' || $schema === '' || $programmeId === '') {
            return null;
        }

        try {
            $object = $this->getObjectService()->find(id: $programmeId, register: $register, schema: $schema);
        } catch (\Throwable $e) {
            return null;
        }

        if ($object === null) {
            return null;
        }

        return $this->toArray(object: $object);
    }//end getProgramme()

    /**
     * Count objects in a schema filtered by programmeId.
     *
     * @param string $schemaKey   AppConfig key for the schema id.
     * @param string $programmeId The programme UUID.
     *
     * @return int
     */
    private function countByProgramme(string $schemaKey, string $programmeId): int
    {
        [$register, $schema] = $this->config(schemaKey: $schemaKey);
        if ($register === '' || $schema === '') {
            return 0;
        }

        try {
            $rows = $this->getObjectService()->findAll(
                filters: ['programmeId' => $programmeId],
                register: $register,
                schema: $schema,
                limit: 1000
            );
        } catch (\Throwable $e) {
            return 0;
        }

        if (is_array($rows) === true) {
            return count($rows);
        }

        return 0;
    }//end countByProgramme()

    /**
     * Persist a programme.
     *
     * @param array<string, mixed> $payload The programme data.
     * @param ?string              $uuid    Update target.
     *
     * @return array<string, mixed>
     */
    private function persist(array $payload, ?string $uuid): array
    {
        [$register, $schema] = $this->config(schemaKey: 'loyaltyProgramme_schema');
        if ($register === '' || $schema === '') {
            throw new RuntimeException('LoyaltyProgramme schema is not configured.');
        }

        $saved = $this->getObjectService()->saveObject(
            object: $payload,
            extend: [],
            register: $register,
            schema: $schema,
            uuid: $uuid
        );

        return $this->toArray(object: $saved);
    }//end persist()

    /**
     * Resolve register + schema id.
     *
     * @param string $schemaKey The schema config key.
     *
     * @return array{0: string, 1: string}
     */
    private function config(string $schemaKey): array
    {
        return [
            $this->appConfig->getValueString(Application::APP_ID, 'register', ''),
            $this->appConfig->getValueString(Application::APP_ID, $schemaKey, ''),
        ];
    }//end config()

    /**
     * Normalise OR entity/array to a plain array.
     *
     * @param mixed $object The entity or array.
     *
     * @return array<string, mixed>
     */
    private function toArray(mixed $object): array
    {
        if (is_array($object) === true) {
            return $object;
        }

        if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
            $s = $object->jsonSerialize();
            if (is_array($s) === true) {
                return $s;
            }
        }

        if (is_object($object) === true && method_exists($object, 'getObject') === true) {
            $d = $object->getObject();
            if (is_array($d) === true) {
                return $d;
            }
        }

        return [];
    }//end toArray()

    /**
     * Get the OpenRegister ObjectService.
     *
     * @return object
     */
    private function getObjectService(): object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            throw new RuntimeException('OpenRegister ObjectService is unavailable.', 0, $e);
        }
    }//end getObjectService()
}//end class
