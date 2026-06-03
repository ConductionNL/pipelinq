<?php

/**
 * Pipelinq BrpLookupService.
 *
 * Orchestrates a doelbinding-gated BRP lookup: authorise the actor, require a
 * verzoekreden + doelbinding, serve from cache or query the BRP client, mirror
 * any secrecy flag, persist the lookup-verzoek, update the linked contact, and
 * ALWAYS write an immutable audit record — success, miss, error or refusal.
 *
 * The raw BSN never leaves this service except into the BRP client; everything
 * persisted or returned uses the masked form (ADR-005). The external lookup is
 * behind {@see BrpClientInterface} so no live RvIG credential is needed to test.
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
 * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#3.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Bsn\BrpClientInterface;
use OCA\Pipelinq\Service\Bsn\BsnMasker;
use OCA\Pipelinq\Service\Bsn\BsnObjectStoreTrait;
use OCA\Pipelinq\Service\Bsn\HaalCentraalException;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IAppConfig;
use OCP\IGroupManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * BRP lookup orchestration with doelbinding + audit (REQ-BSN-002..006).
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The lookup flow legitimately
 *  coordinates validation, the BRP client, cache, audit, opt-out and contact
 *  update; each collaborator is single-purpose and the orchestration is the
 *  point of this class.
 *
 * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#3.2
 */
class BrpLookupService
{
    use BsnObjectStoreTrait;

    /**
     * App-config keys for the authorised groups (sane gemeente defaults).
     *
     * @var array<string, string>
     */
    private const ROLE_GROUP_KEYS = [
        'brp.role_group_burgerzaken' => 'behandelaar-burgerzaken',
        'brp.role_group_avg'         => 'behandelaar-avg',
    ];

    /**
     * Constructor.
     *
     * @param ContainerInterface   $container    The DI container (OR ObjectService).
     * @param IAppConfig           $appConfig    The app config.
     * @param IGroupManager        $groupManager The group manager (RBAC).
     * @param BsnValidationService $validation   The 11-proef validator.
     * @param BrpClientInterface   $brpClient    The BRP client (mockable).
     * @param BrpCacheService      $cache        The response cache.
     * @param BsnAuditService      $audit        The immutable audit trail.
     * @param OptOutService        $optOut       The opt-out / secrecy service.
     * @param LoggerInterface      $logger       The logger (BSN-masked only).
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private IGroupManager $groupManager,
        private BsnValidationService $validation,
        private BrpClientInterface $brpClient,
        private BrpCacheService $cache,
        private BsnAuditService $audit,
        private OptOutService $optOut,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Whether a user may perform BRP lookups (burgerzaken/avg role or admin).
     *
     * @param string $userId The acting user UID.
     *
     * @return bool True when authorised.
     *
     * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#3.2
     */
    public function isAuthorised(string $userId): bool
    {
        if ($this->groupManager->isAdmin($userId) === true) {
            return true;
        }

        foreach (self::ROLE_GROUP_KEYS as $configKey => $default) {
            $group = $this->appConfig->getValueString(Application::APP_ID, $configKey, $default);
            if ($group !== '' && $this->groupManager->isInGroup($userId, $group) === true) {
                return true;
            }
        }

        return false;
    }//end isAuthorised()

    /**
     * Perform a doelbinding-gated BRP lookup.
     *
     * @param array<string, mixed> $params The request params. Keys: bsn (raw),
     *                                     verzoekreden, doelbinding, grondslag,
     *                                     contactId, verzoekId, actorRol,
     *                                     vogScreening, ipAdres, userAgent.
     * @param string               $userId The acting user UID.
     *
     * @return array<string, mixed> The lookup result for the controller.
     *
     * @throws OCSForbiddenException  When the actor is unauthorised.
     * @throws OCSBadRequestException When validation or doelbinding is missing.
     *
     * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#3.2
     */
    public function lookup(array $params, string $userId): array
    {
        $bsn          = (string) ($params['bsn'] ?? '');
        $verzoekreden = trim((string) ($params['verzoekreden'] ?? ''));
        $doelbinding  = trim((string) ($params['doelbinding'] ?? ''));
        $grondslag    = trim((string) ($params['grondslag'] ?? ''));
        $contactId    = (string) ($params['contactId'] ?? '');
        $vog          = (bool) ($params['vogScreening'] ?? false);

        // RBAC first: an unauthorised attempt is audited and refused (REQ-BSN-005-03).
        if ($this->isAuthorised(userId: $userId) === false) {
            $this->audit->recordLookup(
                $this->auditContext(
                    params: $params,
                    userId: $userId,
                    actie: 'brp-lookup-geweigerd',
                    status: 'geweigerd-onbevoegd'
                )
            );
            throw new OCSForbiddenException('U bent niet bevoegd voor deze lookup.');
        }

        // Doelbinding + verzoekreden are mandatory (REQ-BSN-002).
        if ($verzoekreden === '' || $doelbinding === '') {
            throw new OCSBadRequestException('Verzoekreden en doelbinding zijn verplicht.');
        }

        // Defence-in-depth: re-run the formal 11-proef server-side.
        $result = $this->validation->validate($bsn);
        if ($result->isFormeelGeldig === false) {
            throw new OCSBadRequestException('Het opgegeven BSN is niet formeel geldig.');
        }

        $startedAt = microtime(true);
        $cached    = $this->cache->get($bsn);
        $fromCache = ($cached !== null);
        $status    = 'geslaagd';
        $code      = 200;
        $persoon   = null;

        try {
            if ($fromCache === true) {
                $record = $cached;
            } else {
                $persoon = $this->brpClient->lookupPersoon($bsn);
                if ($persoon === null) {
                    $status = 'niet-gevonden';
                    $code   = 404;
                    $record = null;
                } else {
                    $verzoek = $this->persistVerzoek(
                        params: $params,
                        userId: $userId,
                        status: $status,
                        startedAt: $startedAt,
                        fromCache: false,
                        grondslag: $grondslag
                    );
                    $record  = $this->cache->set($persoon, $bsn, (string) ($verzoek['id'] ?? ''), $contactId);
                    $this->optOut->recordFromBrpResponse($persoon, $bsn);
                }
            }//end if
        } catch (HaalCentraalException $e) {
            $status = 'fout';
            $code   = 503;
            if ($e->getStatusCode() > 0) {
                $code = $e->getStatusCode();
            }

            $duration = (int) round((microtime(true) - $startedAt) * 1000);
            $this->writeAudit(
                params: $params,
                userId: $userId,
                status: $status,
                code: $code,
                fromCache: false,
                duration: $duration
            );
            throw new OCSBadRequestException('De BRP is momenteel niet bereikbaar — probeer over enkele minuten opnieuw.');
        }//end try

        $duration      = (int) round((microtime(true) - $startedAt) * 1000);
        $geheimhouding = $this->optOut->hasOptOut($bsn);

        if ($status === 'niet-gevonden') {
            $this->persistVerzoek(
                params: $params,
                userId: $userId,
                status: $status,
                startedAt: $startedAt,
                fromCache: false,
                grondslag: $grondslag
            );
            $this->writeAudit(
                params: $params,
                userId: $userId,
                status: $status,
                code: $code,
                fromCache: false,
                duration: $duration
            );
            throw new OCSBadRequestException('BSN niet aangetroffen in BRP — controleer invoer.');
        }

        if ($fromCache === true) {
            $this->persistVerzoek(
                params: $params,
                userId: $userId,
                status: $status,
                startedAt: $startedAt,
                fromCache: true,
                grondslag: $grondslag
            );
        }

        $this->updateContact(
            contactId: $contactId,
            brpPersoonId: (string) ($record['id'] ?? $record['uuid'] ?? ''),
            geheimhouding: $geheimhouding
        );
        $this->writeAudit(
            params: $params,
            userId: $userId,
            status: $status,
            code: $code,
            fromCache: $fromCache,
            duration: $duration
        );

        return [
            'persoon'         => $this->presentPerson(record: $record, geheimhouding: $geheimhouding),
            'responseInCache' => $fromCache,
            'geheimhouding'   => $geheimhouding,
            'verzoekreden'    => $verzoekreden,
            'vogScreening'    => $vog,
        ];
    }//end lookup()

    /**
     * Present a person record for the client, hiding the address under secrecy.
     *
     * @param array<string, mixed> $record        The brpPersoon record.
     * @param bool                 $geheimhouding Whether secrecy applies.
     *
     * @return array<string, mixed> The presentable person (no raw BSN).
     */
    private function presentPerson(array $record, bool $geheimhouding): array
    {
        unset($record['@self']);
        if ($geheimhouding === true) {
            $record['verblijfplaats']   = null;
            $record['adresAfgeschermd'] = true;
        }

        return $record;
    }//end presentPerson()

    /**
     * Persist a BrpLookupVerzoek record for the audit trail.
     *
     * @param array<string, mixed> $params    The request params.
     * @param string               $userId    The actor UID.
     * @param string               $status    The response status.
     * @param float                $startedAt The microtime start.
     * @param bool                 $fromCache Whether the response came from cache.
     * @param string               $grondslag The legal basis.
     *
     * @return array<string, mixed> The saved verzoek.
     */
    private function persistVerzoek(
        array $params,
        string $userId,
        string $status,
        float $startedAt,
        bool $fromCache,
        string $grondslag
    ): array {
        $effectiveGrondslag = (string) ($params['doelbinding'] ?? '');
        if ($grondslag !== '') {
            $effectiveGrondslag = $grondslag;
        }

        $record = [
            'bsnGemaskeerd'     => BsnMasker::mask((string) ($params['bsn'] ?? '')),
            'verzoekreden'      => (string) ($params['verzoekreden'] ?? ''),
            'doelbinding'       => (string) ($params['doelbinding'] ?? ''),
            'grondslag'         => $effectiveGrondslag,
            'aangevraagdDoor'   => $userId,
            'aangevraagdNamens' => (string) ($params['actorRol'] ?? ''),
            'verzoekTijdstip'   => $this->now(),
            'gekoppeldVerzoek'  => (string) ($params['verzoekId'] ?? ''),
            'gekoppeldContact'  => (string) ($params['contactId'] ?? ''),
            'responseStatus'    => $status,
            'responseTijdstip'  => $this->now(),
            'responseDuurMs'    => (int) round((microtime(true) - $startedAt) * 1000),
            'responseInCache'   => $fromCache,
        ];

        try {
            return $this->save(schemaKey: 'brpLookupVerzoek_schema', object: $record);
        } catch (\Throwable $e) {
            $this->logger->warning('Pipelinq BRP: failed to persist lookup-verzoek', ['exception' => $e->getMessage()]);
            return [];
        }
    }//end persistVerzoek()

    /**
     * Update the linked contact's verification + secrecy flags.
     *
     * @param string $contactId     The contact UUID.
     * @param string $brpPersoonId  The cached person id.
     * @param bool   $geheimhouding Whether secrecy applies.
     *
     * @return void
     */
    private function updateContact(string $contactId, string $brpPersoonId, bool $geheimhouding): void
    {
        if ($contactId === '') {
            return;
        }

        try {
            [$register, $schema] = $this->resolveContact();
            $contact = $this->objectService()->find(id: $contactId, register: $register, schema: $schema);
            $data    = $this->asArray(object: $contact);
            $data['verifiedBSN']   = true;
            $data['brpPersoonId']  = $brpPersoonId;
            $data['geheimhouding'] = $geheimhouding;
            unset($data['@self']);
            $this->objectService()->saveObject(
                object: $data,
                extend: [],
                register: $register,
                schema: $schema,
                uuid: $contactId
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Pipelinq BRP: failed to update contact flags', ['exception' => $e->getMessage()]);
        }//end try
    }//end updateContact()

    /**
     * Resolve the contact register + schema IDs.
     *
     * @return array{0: string, 1: string} The [register, schema] IDs.
     */
    private function resolveContact(): array
    {
        return $this->resolve(schemaKey: 'contact_schema');
    }//end resolveContact()

    /**
     * Build the audit context and write an immutable record.
     *
     * @param array<string, mixed> $params    The request params.
     * @param string               $userId    The actor UID.
     * @param string               $status    The outcome.
     * @param int                  $code      The response code.
     * @param bool                 $fromCache Whether the response came from cache.
     * @param int                  $duration  The response duration in ms.
     *
     * @return void
     */
    private function writeAudit(
        array $params,
        string $userId,
        string $status,
        int $code,
        bool $fromCache,
        int $duration
    ): void {
        $actie = 'brp-lookup-mislukt';
        if ($status === 'geslaagd') {
            $actie = 'brp-lookup-uitgevoerd';
        }

        $context = $this->auditContext(
            params: $params,
            userId: $userId,
            actie: $actie,
            status: $status,
            code: $code
        );
        $context['responseInCache'] = $fromCache;
        $context['responseDuurMs']  = $duration;
        $this->audit->recordLookup($context);
    }//end writeAudit()

    /**
     * Assemble the audit context from request params (raw BSN masked downstream).
     *
     * @param array<string, mixed> $params $params The request params.
     * @param string               $userId The actor UID.
     * @param string               $actie  The audit action.
     * @param string               $status The outcome.
     * @param int|null             $code   The response code.
     *
     * @return array<string, mixed> The audit context.
     */
    private function auditContext(array $params, string $userId, string $actie, string $status, ?int $code=null): array
    {
        return [
            'actie'            => $actie,
            'bsn'              => (string) ($params['bsn'] ?? ''),
            'actor'            => $userId,
            'actorRol'         => (string) ($params['actorRol'] ?? ''),
            'verzoekreden'     => (string) ($params['verzoekreden'] ?? ''),
            'doelbinding'      => (string) ($params['doelbinding'] ?? ''),
            'uitkomst'         => $status,
            'responseCode'     => $code,
            'ipAdres'          => (string) ($params['ipAdres'] ?? ''),
            'userAgent'        => (string) ($params['userAgent'] ?? ''),
            'gekoppeldVerzoek' => (string) ($params['verzoekId'] ?? ''),
            'vogScreening'     => (bool) ($params['vogScreening'] ?? false),
        ];
    }//end auditContext()

    /**
     * Current time as an ISO 8601 string.
     *
     * @return string The timestamp.
     */
    private function now(): string
    {
        return (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
    }//end now()
}//end class
