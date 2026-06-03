<?php

/**
 * Pipelinq CtiContactMatcher.
 *
 * Resolves an inbound caller's phone number to existing contacts.
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
 *
 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-2.3
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Matches a caller's E.164 number against stored contacts.
 *
 * Stored numbers may be in any format, so matching normalises each stored
 * `phone` value to E.164 before comparing (REQ-CTI-001/002). The matcher
 * reuses the existing contact register/schema configuration and the real
 * OpenRegister `findAll` query API (ADR-022).
 */
class CtiContactMatcher
{
    /**
     * Maximum number of matches returned for the chooser modal.
     *
     * @var int
     */
    private const MAX_MATCHES = 3;

    /**
     * Constructor.
     *
     * @param ContainerInterface $container  The DI container (resolves OpenRegister).
     * @param IAppConfig         $appConfig  The app config.
     * @param PhoneNormaliser    $normaliser The phone-number normaliser.
     * @param LoggerInterface    $logger     The logger.
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private PhoneNormaliser $normaliser,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Find contacts whose stored phone number matches the given E.164 number.
     *
     * @param string $e164Number The normalised caller number to match.
     *
     * @return array<int, array<string, mixed>> The matched contacts (0..MAX_MATCHES),
     *                                          most-recently-updated first.
     *
     * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-2.3
     */
    public function findByPhoneNumber(string $e164Number): array
    {
        if ($e164Number === '') {
            return [];
        }

        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, 'contact_schema', '');
        if ($register === '' || $schema === '') {
            return [];
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $results       = $objectService->findAll(
                ['filters' => ['register' => $register, 'schema' => $schema], 'limit' => 1000]
            );
        } catch (\Exception $e) {
            $this->logger->warning('CTI contact match query failed', ['exception' => $e->getMessage()]);
            return [];
        }

        $matches = [];
        foreach ($results as $result) {
            $contact = $this->serialize(result: $result);
            $phone   = (string) ($contact['phone'] ?? '');
            if ($phone === '') {
                continue;
            }

            $normalised = $this->normaliser->normalise($phone);
            if ($normalised['e164'] !== null && $normalised['e164'] === $e164Number) {
                $matches[] = $contact;
            }
        }

        usort(
            $matches,
            static function (array $a, array $b): int {
                return self::updatedAt(contact: $b) <=> self::updatedAt(contact: $a);
            }
        );

        return array_slice($matches, 0, self::MAX_MATCHES);
    }//end findByPhoneNumber()

    /**
     * Derive the updated timestamp from an OpenRegister object's @self block.
     *
     * @param array<string, mixed> $contact The serialised contact.
     *
     * @return string The ISO timestamp, or empty string when absent.
     */
    private static function updatedAt(array $contact): string
    {
        $self = ($contact['@self'] ?? []);
        if (is_array($self) === false) {
            return '';
        }

        return (string) ($self['updated'] ?? ($self['created'] ?? ''));
    }//end updatedAt()

    /**
     * Serialise an OpenRegister result (entity or array) to a plain array.
     *
     * @param mixed $result The raw result.
     *
     * @return array<string, mixed> The serialised contact.
     */
    private function serialize(mixed $result): array
    {
        if (is_object($result) === true && method_exists($result, 'jsonSerialize') === true) {
            $serialized = $result->jsonSerialize();
            if (is_array($serialized) === true) {
                return $serialized;
            }

            return [];
        }

        if (is_array($result) === true) {
            return $result;
        }

        return [];
    }//end serialize()
}//end class
