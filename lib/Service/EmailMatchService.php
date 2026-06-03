<?php

/**
 * Pipelinq EmailMatchService.
 *
 * CRM-specific rule that resolves an email sender/recipient address to a
 * Pipelinq CRM entity (contact or client). The resolved entity reference is
 * handed to the OpenRegister `email` integration leaf, which owns the actual
 * link record (`openregister_email_links`). This service therefore contains
 * ONLY the pipelinq-owned matching rule plus the per-user matching-job
 * settings; it never writes a pipelinq-local link table (ADR-022).
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
 * @spec openspec/changes/email-calendar-sync/specs/email-calendar-sync/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTime;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolve email addresses to CRM entities and store per-user matching settings.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 *
 * @spec openspec/changes/email-calendar-sync/tasks.md#task-2.1
 */
class EmailMatchService
{

    /**
     * Public email providers excluded from domain-to-organization matching.
     *
     * @var array<int, string>
     */
    private const PUBLIC_DOMAINS = [
        'gmail.com',
        'googlemail.com',
        'outlook.com',
        'hotmail.com',
        'live.com',
        'yahoo.com',
        'icloud.com',
        'me.com',
        'protonmail.com',
        'proton.me',
        'aol.com',
        'gmx.net',
        'gmx.com',
        'ziggo.nl',
        'kpnmail.nl',
        'hetnet.nl',
        'home.nl',
    ];

    /**
     * Constructor.
     *
     * @param ContainerInterface $container The DI container (lazily resolves the OR ObjectService).
     * @param IAppConfig         $appConfig The app config (register/schema slug map).
     * @param IConfig            $config    The per-user config store.
     * @param LoggerInterface    $logger    The logger.
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private IConfig $config,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Extract the lower-cased domain part from an email address.
     *
     * @param string $email The email address.
     *
     * @return string|null The domain, or null when the address is malformed.
     *
     * @spec openspec/changes/email-calendar-sync/specs/email-calendar-sync/spec.md
     */
    public function extractDomain(string $email): ?string
    {
        $parts = explode('@', trim($email));
        if (count($parts) !== 2 || $parts[1] === '') {
            return null;
        }

        return strtolower(trim($parts[1]));
    }//end extractDomain()

    /**
     * Determine whether a domain is a public email provider.
     *
     * Public-provider domains (gmail, outlook, ...) MUST NOT be used for
     * domain-to-organization matching, since many unrelated people share them.
     *
     * @param string $domain The domain to test.
     *
     * @return bool True when the domain is a public provider.
     *
     * @spec openspec/changes/email-calendar-sync/specs/email-calendar-sync/spec.md
     */
    public function isPublicDomain(string $domain): bool
    {
        return in_array(strtolower(trim($domain)), self::PUBLIC_DOMAINS, true);
    }//end isPublicDomain()

    /**
     * Match an email address to CRM entities by exact `email` field.
     *
     * Queries the `contact` and `client` schemas for an exact (case-insensitive)
     * match on their `email` property and returns every matched entity reference.
     *
     * @param string $address The sender/recipient email address.
     *
     * @return array<int, array{type: string, uuid: string}> Matched entity references.
     *
     * @spec openspec/changes/email-calendar-sync/specs/email-calendar-sync/spec.md
     */
    public function matchEmailToEntities(string $address): array
    {
        $normalised = strtolower(trim($address));
        if ($normalised === '' || str_contains($normalised, '@') === false) {
            return [];
        }

        $matches = [];
        foreach (['contact', 'client'] as $entityType) {
            foreach ($this->queryByEmail(entityType: $entityType, email: $normalised) as $object) {
                $uuid = $this->extractUuid(object: $object);
                if ($uuid !== null) {
                    $matches[] = ['type' => $entityType, 'uuid' => $uuid];
                }
            }
        }

        return $matches;
    }//end matchEmailToEntities()

    /**
     * Match an email address's domain to a client organization.
     *
     * Only attempted when no exact address match was found and the domain is not
     * a public provider. Returns the first matching organization-type client.
     *
     * @param string $domain The sender domain.
     *
     * @return array{type: string, uuid: string}|null The matched organization, or null.
     *
     * @spec openspec/changes/email-calendar-sync/specs/email-calendar-sync/spec.md
     */
    public function matchDomainToOrganization(string $domain): ?array
    {
        $normalised = strtolower(trim($domain));
        if ($normalised === '' || $this->isPublicDomain(domain: $normalised) === true) {
            return null;
        }

        foreach ($this->queryByType(entityType: 'client', type: 'organization') as $object) {
            $email       = strtolower((string) ($object['email'] ?? ''));
            $emailDomain = $this->extractDomain(email: $email);
            if ($emailDomain !== $normalised) {
                continue;
            }

            $uuid = $this->extractUuid(object: $object);
            if ($uuid !== null) {
                return ['type' => 'client', 'uuid' => $uuid];
            }
        }

        return null;
    }//end matchDomainToOrganization()

    /**
     * Resolve an address to entities: exact match first, domain fallback second.
     *
     * @param string $address The sender/recipient address.
     *
     * @return array<int, array{type: string, uuid: string}> Matched entity references.
     *
     * @spec openspec/changes/email-calendar-sync/specs/email-calendar-sync/spec.md
     */
    public function resolveAddress(string $address): array
    {
        $exact = $this->matchEmailToEntities(address: $address);
        if ($exact !== []) {
            return $exact;
        }

        $domain = $this->extractDomain(email: $address);
        if ($domain === null) {
            return [];
        }

        $organization = $this->matchDomainToOrganization(domain: $domain);
        if ($organization === null) {
            return [];
        }

        return [$organization];
    }//end resolveAddress()

    /**
     * Whether the user has enabled the email matching job.
     *
     * @param string $userId The user id.
     *
     * @return bool True when matching is enabled for the user.
     *
     * @spec openspec/changes/email-calendar-sync/specs/email-calendar-sync/spec.md
     */
    public function isSyncEnabled(string $userId): bool
    {
        return $this->config->getUserValue($userId, Application::APP_ID, 'email_sync_enabled', 'false') === 'true';
    }//end isSyncEnabled()

    /**
     * Persist the sync-enabled flag for a user.
     *
     * @param string $userId  The user id.
     * @param bool   $enabled Whether matching is enabled.
     *
     * @return void
     *
     * @spec openspec/changes/email-calendar-sync/specs/email-calendar-sync/spec.md
     */
    public function setSyncEnabled(string $userId, bool $enabled): void
    {
        $value = 'false';
        if ($enabled === true) {
            $value = 'true';
        }

        $this->config->setUserValue(
            $userId,
            Application::APP_ID,
            'email_sync_enabled',
            $value
        );
    }//end setSyncEnabled()

    /**
     * Get the mail account id the user wants the matching job to index.
     *
     * @param string $userId The user id.
     *
     * @return int|null The mail account id, or null when unset.
     *
     * @spec openspec/changes/email-calendar-sync/specs/email-calendar-sync/spec.md
     */
    public function getSyncAccount(string $userId): ?int
    {
        $value = $this->config->getUserValue($userId, Application::APP_ID, 'email_sync_account', '');
        if ($value === '' || ctype_digit($value) === false) {
            return null;
        }

        return (int) $value;
    }//end getSyncAccount()

    /**
     * Persist the mail account id for the matching job.
     *
     * @param string   $userId    The user id.
     * @param int|null $accountId The mail account id, or null to clear.
     *
     * @return void
     *
     * @spec openspec/changes/email-calendar-sync/specs/email-calendar-sync/spec.md
     */
    public function setSyncAccount(string $userId, ?int $accountId): void
    {
        $value = '';
        if ($accountId !== null) {
            $value = (string) $accountId;
        }

        $this->config->setUserValue(
            $userId,
            Application::APP_ID,
            'email_sync_account',
            $value
        );
    }//end setSyncAccount()

    /**
     * Get the list of addresses excluded from matching for a user.
     *
     * @param string $userId The user id.
     *
     * @return array<int, string> Lower-cased excluded addresses.
     *
     * @spec openspec/changes/email-calendar-sync/specs/email-calendar-sync/spec.md
     */
    public function getExcludedAddresses(string $userId): array
    {
        $raw     = $this->config->getUserValue($userId, Application::APP_ID, 'email_sync_excluded', '[]');
        $decoded = json_decode($raw, true);
        if (is_array($decoded) === false) {
            return [];
        }

        $clean = [];
        foreach ($decoded as $value) {
            if (is_string($value) === true && trim($value) !== '') {
                $clean[] = strtolower(trim($value));
            }
        }

        return array_values(array_unique($clean));
    }//end getExcludedAddresses()

    /**
     * Persist the excluded-addresses list for a user.
     *
     * @param string             $userId    The user id.
     * @param array<int, string> $addresses The addresses to exclude.
     *
     * @return void
     *
     * @spec openspec/changes/email-calendar-sync/specs/email-calendar-sync/spec.md
     */
    public function setExcludedAddresses(string $userId, array $addresses): void
    {
        $clean = [];
        foreach ($addresses as $value) {
            if (is_string($value) === true && trim($value) !== '') {
                $clean[] = strtolower(trim($value));
            }
        }

        $this->config->setUserValue(
            $userId,
            Application::APP_ID,
            'email_sync_excluded',
            json_encode(array_values(array_unique($clean)))
        );
    }//end setExcludedAddresses()

    /**
     * Whether an address is on the user's exclude list.
     *
     * @param string $userId  The user id.
     * @param string $address The address to test.
     *
     * @return bool True when excluded.
     *
     * @spec openspec/changes/email-calendar-sync/specs/email-calendar-sync/spec.md
     */
    public function isExcluded(string $userId, string $address): bool
    {
        return in_array(strtolower(trim($address)), $this->getExcludedAddresses(userId: $userId), true);
    }//end isExcluded()

    /**
     * Read the last matching-job run status for a user.
     *
     * @param string $userId The user id.
     *
     * @return array{lastRun: ?string, linked: int, error: ?string} The status payload.
     *
     * @spec openspec/changes/email-calendar-sync/specs/email-calendar-sync/spec.md
     */
    public function getStatus(string $userId): array
    {
        $lastRun = $this->config->getUserValue($userId, Application::APP_ID, 'email_sync_last_run', '');
        $error   = $this->config->getUserValue($userId, Application::APP_ID, 'email_sync_last_error', '');

        $lastRunValue = null;
        if ($lastRun !== '') {
            $lastRunValue = $lastRun;
        }

        $errorValue = null;
        if ($error !== '') {
            $errorValue = $error;
        }

        return [
            'lastRun' => $lastRunValue,
            'linked'  => (int) $this->config->getUserValue($userId, Application::APP_ID, 'email_sync_linked', '0'),
            'error'   => $errorValue,
        ];
    }//end getStatus()

    /**
     * Record a completed matching-job run for a user.
     *
     * @param string      $userId The user id.
     * @param int         $linked The number of links created this run.
     * @param string|null $error  A static error message, or null on success.
     *
     * @return void
     *
     * @spec openspec/changes/email-calendar-sync/specs/email-calendar-sync/spec.md
     */
    public function recordRun(string $userId, int $linked, ?string $error=null): void
    {
        $this->config->setUserValue($userId, Application::APP_ID, 'email_sync_last_run', (new DateTime())->format(DateTime::ATOM));
        $this->config->setUserValue($userId, Application::APP_ID, 'email_sync_linked', (string) $linked);
        $this->config->setUserValue($userId, Application::APP_ID, 'email_sync_last_error', ($error ?? ''));
    }//end recordRun()

    /**
     * Query a schema for objects whose `email` field equals the given address.
     *
     * @param string $entityType The schema slug config key prefix (contact|client).
     * @param string $email      The lower-cased email address.
     *
     * @return array<int, array<string, mixed>> Matched object arrays.
     */
    private function queryByEmail(string $entityType, string $email): array
    {
        return $this->query(entityType: $entityType, filters: ['email' => $email]);
    }//end queryByEmail()

    /**
     * Query a schema for objects whose `type` field equals the given value.
     *
     * @param string $entityType The schema slug config key prefix.
     * @param string $type       The `type` field value to match.
     *
     * @return array<int, array<string, mixed>> Matched object arrays.
     */
    private function queryByType(string $entityType, string $type): array
    {
        return $this->query(entityType: $entityType, filters: ['type' => $type]);
    }//end queryByType()

    /**
     * Run an OpenRegister findAll against a CRM schema with equality filters.
     *
     * Resolves the register/schema slugs from app config and delegates to the
     * OR ObjectService (real API: `findAll`). Returns plain object arrays.
     *
     * @param string              $entityType The schema config-key prefix (contact|client).
     * @param array<string,mixed> $filters    Field equality filters.
     *
     * @return array<int, array<string, mixed>> Matched object arrays.
     */
    private function query(string $entityType, array $filters): array
    {
        $registerSlug = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schemaSlug   = $this->appConfig->getValueString(Application::APP_ID, $entityType.'_schema', '');
        if ($registerSlug === '' || $schemaSlug === '') {
            return [];
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $results       = $objectService->findAll(
                [
                    'filters' => array_merge(
                        ['register' => $registerSlug, 'schema' => $schemaSlug],
                        $filters
                    ),
                    'limit'   => 50,
                ]
            );

            return $this->normalise(results: $results);
        } catch (Throwable $e) {
            $this->logger->error(
                'EmailMatchService: schema query failed',
                ['entityType' => $entityType, 'exception' => $e->getMessage()]
            );
            return [];
        }//end try
    }//end query()

    /**
     * Normalise an OpenRegister findAll result into plain arrays.
     *
     * @param mixed $results The raw findAll return value.
     *
     * @return array<int, array<string, mixed>> The normalised object arrays.
     */
    private function normalise(mixed $results): array
    {
        if (is_array($results) === false) {
            return [];
        }

        $output = [];
        foreach ($results as $item) {
            if (is_array($item) === true) {
                $output[] = $item;
                continue;
            }

            if (is_object($item) === true && method_exists($item, 'jsonSerialize') === true) {
                $serialised = $item->jsonSerialize();
                if (is_array($serialised) === true) {
                    $output[] = $serialised;
                }
            }
        }

        return $output;
    }//end normalise()

    /**
     * Extract the stable uuid from an OpenRegister object array.
     *
     * @param array<string, mixed> $object The object array.
     *
     * @return string|null The uuid, or null when absent.
     */
    private function extractUuid(array $object): ?string
    {
        $self = ($object['@self'] ?? []);
        if (is_array($self) === true && isset($self['uuid']) === true && is_string($self['uuid']) === true) {
            return $self['uuid'];
        }

        if (isset($object['uuid']) === true && is_string($object['uuid']) === true) {
            return $object['uuid'];
        }

        if (isset($object['id']) === true && (is_string($object['id']) === true || is_int($object['id']) === true)) {
            return (string) $object['id'];
        }

        return null;
    }//end extractUuid()
}//end class
