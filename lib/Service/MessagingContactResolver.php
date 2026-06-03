<?php

/**
 * Pipelinq MessagingContactResolver.
 *
 * Resolves the contact for an inbound message, creating a placeholder contact
 * on first contact from an unknown number.
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
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-6.3
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Messaging\OrSerializeTrait;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Find-or-create contact resolution for messaging (REQ-003 / ADR-022).
 *
 * A contact is a Nextcloud entity reused from the existing `contact` schema
 * (NC-addressbook synced). Inbound numbers are matched against stored phone
 * numbers (normalised to E.164); unknown numbers produce a placeholder contact
 * so the conversation can be queued without manual triage.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) — coordinates OR objects + config + normalisation
 * @spec                                           openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-6.3
 */
class MessagingContactResolver
{
    use OrSerializeTrait;

    /**
     * Constructor.
     *
     * @param ContainerInterface $container  The DI container (resolves OpenRegister).
     * @param IAppConfig         $appConfig  The app config (register/schema ids).
     * @param PhoneNormaliser    $normaliser The E.164 normaliser.
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
     * Resolve (or create) the contact id for an inbound sender number.
     *
     * @param string $fromNumber The sender's raw phone number.
     *
     * @return array{contactId: string, created: bool}|null The resolution, or null on failure.
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-6.3
     */
    public function resolveForInbound(string $fromNumber): ?array
    {
        $normalised = $this->normaliser->normalise($fromNumber);
        $e164       = ($normalised['e164'] ?? null);

        $existing = $this->findByPhone(e164: $e164, raw: $fromNumber);
        if ($existing !== null) {
            return ['contactId' => $existing, 'created' => false];
        }

        $created = $this->createPlaceholder(e164: $e164, raw: $fromNumber);
        if ($created === null) {
            return null;
        }

        return ['contactId' => $created, 'created' => true];
    }//end resolveForInbound()

    /**
     * Resolve a contact's phone number by contact id (for outbound sends).
     *
     * @param string $contactId The contact UUID.
     *
     * @return string|null The E.164 phone number, or null when unresolved.
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-2.1
     */
    public function phoneForContact(string $contactId): ?string
    {
        $contact = $this->findById(contactId: $contactId);
        if ($contact === null) {
            return null;
        }

        $phone      = (string) ($contact['phone'] ?? '');
        $normalised = $this->normaliser->normalise($phone);

        return ($normalised['e164'] ?? null);
    }//end phoneForContact()

    /**
     * Find a contact id by phone number (E.164-normalised comparison).
     *
     * @param string|null $e164 The normalised inbound number.
     * @param string      $raw  The raw inbound number (fallback comparison).
     *
     * @return string|null The matching contact id, or null.
     */
    private function findByPhone(?string $e164, string $raw): ?string
    {
        foreach ($this->allContacts() as $contact) {
            $phone = (string) ($contact['phone'] ?? '');
            if ($phone === '') {
                continue;
            }

            $candidate = $this->normaliser->normalise($phone);
            if ($e164 !== null && ($candidate['e164'] ?? null) === $e164) {
                return $this->contactId(contact: $contact);
            }

            if ($e164 === null && $phone === $raw) {
                return $this->contactId(contact: $contact);
            }
        }

        return null;
    }//end findByPhone()

    /**
     * Create a placeholder contact for an unknown inbound number.
     *
     * @param string|null $e164 The normalised number.
     * @param string      $raw  The raw number.
     *
     * @return string|null The created contact id, or null on failure.
     */
    private function createPlaceholder(?string $e164, string $raw): ?string
    {
        [$register, $schema] = $this->registerSchema();
        $objectService       = $this->objectService();
        if ($objectService === null || $register === '' || $schema === '') {
            return null;
        }

        $phone  = ($e164 ?? $raw);
        $object = [
            'name'  => 'Onbekend ('.$phone.')',
            'phone' => $phone,
        ];

        try {
            $saved = $objectService->saveObject($object, [], $register, $schema);
            return $this->contactId(contact: $this->serialize(result: $saved));
        } catch (\Exception $e) {
            $this->logger->error('Placeholder contact creation failed', ['exception' => $e->getMessage()]);
            return null;
        }
    }//end createPlaceholder()

    /**
     * Find a contact object by id.
     *
     * @param string $contactId The contact UUID.
     *
     * @return array<string, mixed>|null The contact, or null.
     */
    private function findById(string $contactId): ?array
    {
        [$register, $schema] = $this->registerSchema();
        $objectService       = $this->objectService();
        if ($objectService === null || $register === '' || $schema === '') {
            return null;
        }

        try {
            $result = $objectService->find($contactId, $register, $schema);
        } catch (\Exception $e) {
            $this->logger->warning('Contact lookup failed', ['exception' => $e->getMessage()]);
            return null;
        }

        if ($result === null) {
            return null;
        }

        return $this->serialize(result: $result);
    }//end findById()

    /**
     * All contact objects.
     *
     * @return array<int, array<string, mixed>> The contacts.
     */
    private function allContacts(): array
    {
        [$register, $schema] = $this->registerSchema();
        $objectService       = $this->objectService();
        if ($objectService === null || $register === '' || $schema === '') {
            return [];
        }

        try {
            $results = $objectService->findAll(
                ['filters' => ['register' => $register, 'schema' => $schema], 'limit' => 5000]
            );
        } catch (\Exception $e) {
            $this->logger->warning('Contact query failed', ['exception' => $e->getMessage()]);
            return [];
        }

        $contacts = [];
        foreach ($results as $result) {
            $contacts[] = $this->serialize(result: $result);
        }

        return $contacts;
    }//end allContacts()

    /**
     * Resolve the configured register + contact schema ids.
     *
     * @return array{0: string, 1: string} The [register, schema] pair.
     */
    private function registerSchema(): array
    {
        return [
            $this->appConfig->getValueString(Application::APP_ID, 'register', ''),
            $this->appConfig->getValueString(Application::APP_ID, 'contact_schema', ''),
        ];
    }//end registerSchema()

    /**
     * Resolve the OpenRegister ObjectService, or null when unavailable.
     *
     * @return object|null The ObjectService, or null.
     */
    private function objectService(): ?object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            $this->logger->warning('OpenRegister ObjectService unavailable', ['exception' => $e->getMessage()]);
            return null;
        }
    }//end objectService()

    /**
     * Derive the id of a contact (uuid, else @self.slug).
     *
     * @param array<string, mixed> $contact The contact object.
     *
     * @return string The contact id.
     */
    private function contactId(array $contact): string
    {
        $self = ($contact['@self'] ?? []);
        if (is_array($self) === true) {
            $id = (string) ($self['id'] ?? ($self['uuid'] ?? ($self['slug'] ?? '')));
            if ($id !== '') {
                return $id;
            }
        }

        return (string) ($contact['id'] ?? '');
    }//end contactId()
}//end class
