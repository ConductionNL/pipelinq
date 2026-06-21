<?php

/**
 * Pipelinq StufMessageHandler.
 *
 * Owns the per-call audit log (StufMessage) — creates one row per outbound
 * envelope, updates it when the response arrives, appends retries to the
 * `retries[]` array, and transitions the status.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Stuf
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#REQ-STUF-008
 * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#REQ-STUF-009
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Stuf;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerInterface;

/**
 * Persists and updates StufMessage audit rows.
 */
class StufMessageHandler
{
    /**
     * Constructor.
     *
     * @param StufRegisterAccess $register The register access helper.
     * @param LoggerInterface    $logger   The logger.
     */
    public function __construct(
        private StufRegisterAccess $register,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Create an outbound audit row with status=verzonden.
     *
     * @param array       $endpoint         The StufEndpoint.
     * @param string      $envelopeXml      The full envelope XML.
     * @param string      $referentienummer The outbound referentienummer.
     * @param string      $berichtSoort     The bericht code (Lk01, Lv01, ...).
     * @param string      $functie          The functie (creeerZaak, ...).
     * @param string|null $zaakId           Optional zaak identificatie.
     * @param string|null $requestId        Optional pipelinq Request id.
     * @param string|null $contactId        Optional pipelinq Contact id.
     *
     * @return array The persisted StufMessage as array.
     *
     * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#REQ-STUF-008
     */
    public function logOutbound(
        array $endpoint,
        string $envelopeXml,
        string $referentienummer,
        string $berichtSoort,
        string $functie,
        ?string $zaakId=null,
        ?string $requestId=null,
        ?string $contactId=null
    ): array {
        $data = [
            'id'                    => $this->newId(prefix: 'stuf-msg'),
            'endpointId'            => (string) ($endpoint['id'] ?? ''),
            'richting'              => 'uitgaand',
            'berichtSoort'          => $berichtSoort,
            'functie'               => $functie,
            'entiteittype'          => 'ZAK',
            'referentienummer'      => $referentienummer,
            'zaakIdentificatie'     => ($zaakId ?? ''),
            'envelopeXml'           => $envelopeXml,
            'verzondenOp'           => $this->isoNow(),
            'gerelateerdeRequestId' => ($requestId ?? ''),
            'gerelateerdeContactId' => ($contactId ?? ''),
            'status'                => 'verzonden',
            'retries'               => [],
        ];
        return $this->register->saveObject(schema: StufRegisterAccess::SCHEMA_MESSAGE, data: $data);
    }//end logOutbound()

    /**
     * Create an inbound audit row from a received envelope.
     *
     * @param array       $endpoint       The StufEndpoint that received.
     * @param string      $responseXml    The full inbound envelope XML.
     * @param string      $berichtSoort   The bericht code (Bv01, Lk02, ...).
     * @param string      $crossRefnummer The crossRefnummer (matches an outbound referentienummer).
     * @param string|null $zaakId         Optional zaak identificatie.
     * @param string|null $functie        Optional functie.
     *
     * @return array The persisted StufMessage as array.
     *
     * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#REQ-STUF-008
     */
    public function logInbound(
        array $endpoint,
        string $responseXml,
        string $berichtSoort,
        string $crossRefnummer,
        ?string $zaakId=null,
        ?string $functie=null
    ): array {
        $data = [
            'id'                => $this->newId(prefix: 'stuf-msg'),
            'endpointId'        => (string) ($endpoint['id'] ?? ''),
            'richting'          => 'inkomend',
            'berichtSoort'      => $berichtSoort,
            'functie'           => ($functie ?? ''),
            'entiteittype'      => 'ZAK',
            'crossRefnummer'    => $crossRefnummer,
            'zaakIdentificatie' => ($zaakId ?? ''),
            'envelopeXml'       => $responseXml,
            'verzondenOp'       => $this->isoNow(),
            'ontvangenOp'       => $this->isoNow(),
            'status'            => 'bevestigd',
        ];
        return $this->register->saveObject(schema: StufRegisterAccess::SCHEMA_MESSAGE, data: $data);
    }//end logInbound()

    /**
     * Append a retry entry to an existing outbound message and persist.
     *
     * @param array $msg        The existing StufMessage row.
     * @param int   $attempt    The retry attempt number.
     * @param int   $httpStatus The HTTP status code on this attempt.
     * @param array $fout       The fout payload (code, omschrijving, details, soort).
     * @param int   $durationMs The wall-clock duration of this attempt.
     *
     * @return array The updated row.
     *
     * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#REQ-STUF-009
     */
    public function recordRetry(array $msg, int $attempt, int $httpStatus, array $fout, int $durationMs): array
    {
        $retries           = (array) ($msg['retries'] ?? []);
        $retries[]         = [
            'poging'     => $attempt,
            'timestamp'  => $this->isoNow(),
            'httpStatus' => $httpStatus,
            'duurMs'     => $durationMs,
            'fout'       => $fout,
        ];
        $msg['retries']    = $retries;
        $msg['status']     = 'wacht_op_retry';
        $msg['httpStatus'] = $httpStatus;
        return $this->register->saveObject(schema: StufRegisterAccess::SCHEMA_MESSAGE, data: $msg);
    }//end recordRetry()

    /**
     * Transition the message lifecycle status.
     *
     * @param array  $msg       The existing message row.
     * @param string $newStatus One of verzonden, bevestigd, fout, wacht_op_retry.
     * @param array  $extras    Optional extra fields to merge (httpStatus, duurMs, fout, responseEnvelopeXml, ontvangenOp).
     *
     * @return array The updated row.
     *
     * @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#REQ-STUF-008
     */
    public function transitionStatus(array $msg, string $newStatus, array $extras=[]): array
    {
        $msg['status'] = $newStatus;
        if (array_key_exists(key: 'ontvangenOp', array: $extras) === false) {
            $msg['ontvangenOp'] = $this->isoNow();
        }

        foreach ($extras as $key => $value) {
            $msg[$key] = $value;
        }

        return $this->register->saveObject(schema: StufRegisterAccess::SCHEMA_MESSAGE, data: $msg);
    }//end transitionStatus()

    /**
     * Find an outbound message by referentienummer.
     *
     * @param string $referentienummer The referentienummer.
     *
     * @return array|null The message row, or null.
     */
    public function findOutboundByReferentienummer(string $referentienummer): ?array
    {
        return $this->register->findOne(
            schema: StufRegisterAccess::SCHEMA_MESSAGE,
            filters: ['referentienummer' => $referentienummer, 'richting' => 'uitgaand']
        );
    }//end findOutboundByReferentienummer()

    /**
     * Mint a new readable id with a millisecond suffix.
     *
     * @param string $prefix The prefix.
     *
     * @return string The new id.
     */
    private function newId(string $prefix): string
    {
        $now = new DateTimeImmutable(datetime: 'now', timezone: new DateTimeZone(timezone: 'Europe/Amsterdam'));
        return $prefix.'-'.$now->format(format: 'Y-m-d-H-i-s').'-'.bin2hex(string: random_bytes(length: 3));
    }//end newId()

    /**
     * ISO-8601 timestamp at second precision in Europe/Amsterdam.
     *
     * @return string The ISO timestamp.
     */
    private function isoNow(): string
    {
        $now = new DateTimeImmutable(datetime: 'now', timezone: new DateTimeZone(timezone: 'Europe/Amsterdam'));
        return $now->format(format: 'c');
    }//end isoNow()
}//end class
