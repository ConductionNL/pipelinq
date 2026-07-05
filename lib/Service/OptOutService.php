<?php

/**
 * Pipelinq OptOutService.
 *
 * Manages geheimhouding / opt-out flags per BSN. Sources: BRP indicatieGeheim
 * (automatic from HaalCentraal response) or a medewerker (handmatige lokale-contact-opt-out).
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
 * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#2.5
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Opt-out lookup + recording service.
 *
 * @spec openspec/changes/bsn-validatie-en-brp-lookup/specs.md#REQ-BSN-006
 *
 * @SuppressWarnings(PHPMD.StaticAccess) BsnValidationService::hash() is a pure,
 *  side-effect-free hashing utility shared across BSN-handling services; injecting
 *  it as a collaborator would not change behaviour.
 */
class OptOutService
{
    /**
     * Constructor.
     *
     * @param ContainerInterface $container DI container (OR lookup lazy).
     * @param IAppConfig         $appConfig App config.
     * @param LoggerInterface    $logger    Logger.
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Return true if an active OptOutVlag exists for the given raw BSN.
     *
     * @param string $rawBsn Raw BSN.
     *
     * @return bool True when an unexpired OptOutVlag exists.
     *
     * @spec openspec/changes/bsn-validatie-en-brp-lookup/specs.md#REQ-BSN-006-01
     */
    public function hasOptOut(string $rawBsn): bool
    {
        return $this->getOptOut(rawBsn: $rawBsn) !== null;
    }//end hasOptOut()

    /**
     * Fetch the OptOutVlag for a raw BSN (or null when none / all expired).
     *
     * @param string $rawBsn Raw BSN.
     *
     * @return array<string,mixed>|null Returns the array representation when active, else null.
     *
     * @spec openspec/changes/bsn-validatie-en-brp-lookup/specs.md#REQ-BSN-006-01
     */
    public function getOptOut(string $rawBsn): ?array
    {
        try {
            [$register, $schema] = $this->config();
            $hash    = BsnValidationService::hash($rawBsn);
            $results = $this->getObjectService()->findAll(
                filters: ['bsnHash' => $hash],
                register: $register,
                schema: $schema,
            );

            $today = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            foreach (($results ?? []) as $object) {
                $arr = self::toArray(object: $object);
                if ($this->isActive(arr: $arr, today: $today) === true) {
                    return $arr;
                }
            }
        } catch (Throwable $e) {
            $this->logger->error(
                'OptOut lookup failed',
                ['error' => $e->getMessage()]
            );
        }//end try

        return null;
    }//end getOptOut()

    /**
     * Record a geheimhouding flag derived from a BRP response (indicatieGeheim).
     *
     * Creates the flag at most once: re-firing only updates `notitie`.
     *
     * @param string $rawBsn          Raw BSN (caller MUST not log it).
     * @param string $indicatieGeheim Value of BRP's indicatieGeheim ("0" = none, "1" = present).
     *
     * @return bool True when an OptOutVlag was newly created / refreshed.
     *
     * @spec openspec/changes/bsn-validatie-en-brp-lookup/specs.md#REQ-BSN-006-01
     */
    public function recordFromBrpResponse(string $rawBsn, string $indicatieGeheim): bool
    {
        if ($indicatieGeheim !== '1') {
            return false;
        }

        if ($this->hasOptOut(rawBsn: $rawBsn) === true) {
            return false;
        }

        try {
            [$register, $schema] = $this->config();
            $today  = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $object = [
                'bsnHash'      => BsnValidationService::hash($rawBsn),
                'type'         => 'geheimhouding-gemeente',
                'bron'         => 'BRP',
                'ingangsdatum' => $today->format('Y-m-d'),
                'beperkt'      => ['commerciele-derden', 'kerkgenootschappen', 'derdeportalen'],
            ];
            $this->getObjectService()->saveObject(
                object: $object,
                extend: [],
                register: $register,
                schema: $schema,
            );
            return true;
        } catch (Throwable $e) {
            $this->logger->error(
                'OptOut record from BRP response failed',
                ['error' => $e->getMessage()]
            );
            return false;
        }//end try
    }//end recordFromBrpResponse()

    /**
     * Manually register a local opt-out (medewerker has captured a citizen request).
     *
     * @param string      $rawBsn  Raw BSN.
     * @param string      $actor   The user UID who registered it.
     * @param string|null $notitie Optional free-text explanation.
     *
     * @return bool True on success.
     *
     * @spec openspec/changes/bsn-validatie-en-brp-lookup/specs.md#REQ-BSN-006-02
     */
    public function recordLocalOptOut(string $rawBsn, string $actor, ?string $notitie=null): bool
    {
        try {
            [$register, $schema] = $this->config();
            $today  = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $object = [
                'bsnHash'             => BsnValidationService::hash($rawBsn),
                'type'                => 'lokale-contact-opt-out',
                'bron'                => 'lokaal',
                'ingangsdatum'        => $today->format('Y-m-d'),
                'beperkt'             => ['commerciele-derden'],
                'lokaalOpgevoerdDoor' => $actor,
                'notitie'             => $notitie,
            ];
            $object = array_filter($object, static fn ($v) => $v !== null);
            $this->getObjectService()->saveObject(
                object: $object,
                extend: [],
                register: $register,
                schema: $schema,
            );
            return true;
        } catch (Throwable $e) {
            $this->logger->error(
                'OptOut local record failed',
                ['error' => $e->getMessage()]
            );
            return false;
        }//end try
    }//end recordLocalOptOut()

    /**
     * Returns true when the opt-out is active today (ingangsdatum <= today <= einddatum-or-null).
     *
     * @param array<string,mixed> $arr   Opt-out object as array.
     * @param DateTimeImmutable   $today Today's UTC date.
     *
     * @return bool
     */
    private function isActive(array $arr, DateTimeImmutable $today): bool
    {
        $ingang    = (string) ($arr['ingangsdatum'] ?? '');
        $einddatum = $arr['einddatum'] ?? null;
        if ($ingang === '') {
            return false;
        }

        try {
            $ingangDt = new DateTimeImmutable($ingang, new DateTimeZone('UTC'));
        } catch (Throwable $e) {
            return false;
        }

        if ($ingangDt > $today) {
            return false;
        }

        if ($einddatum !== null && $einddatum !== '') {
            try {
                $eindDt = new DateTimeImmutable((string) $einddatum, new DateTimeZone('UTC'));
            } catch (Throwable $e) {
                return true;
            }

            if ($today > $eindDt) {
                return false;
            }
        }

        return true;
    }//end isActive()

    /**
     * Normalise an OR object (entity or array) to an array.
     *
     * @param mixed $object Entity instance, JsonSerializable, or array.
     *
     * @return array<string,mixed>
     */
    private static function toArray(mixed $object): array
    {
        if (is_array($object) === true) {
            return $object;
        }

        if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
            $serial = $object->jsonSerialize();
            if (is_array($serial) === true) {
                return $serial;
            }

            return [];
        }

        return [];
    }//end toArray()

    /**
     * Resolve the [register, schema] pair.
     *
     * @return array{0: string, 1: string}
     *
     * @throws RuntimeException If misconfigured.
     */
    private function config(): array
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, 'optOutVlag_schema', '');
        if ($register === '' || $schema === '') {
            throw new RuntimeException('optOutVlag register/schema not configured.');
        }

        return [$register, $schema];
    }//end config()

    /**
     * Lazy OR ObjectService lookup.
     *
     * @return object
     *
     * @throws RuntimeException When OR is not available.
     */
    private function getObjectService(): object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (Throwable $e) {
            throw new RuntimeException('OpenRegister service is not available.');
        }
    }//end getObjectService()
}//end class
