<?php

/**
 * Pipelinq BelastingdienstExportService.
 *
 * Formats kassakoppelingAuditLog entries into the Kassakoppeling-compliant
 * export pack the Dutch tax authority (Belastingdienst) ingests during a
 * POS compliance audit. Renders both XML (the canonical submission format)
 * and JSON (developer-friendly), prefixed with a metadata manifest that
 * documents the export window, entry count, register list and per-register
 * chain integrity status.
 *
 * Pure formatter: no IO. The audit service supplies a pre-loaded entry list
 * and date range; the exporter computes the manifest (counts + registers +
 * chain integrity) and emits a string body. Stamping `exportedAt` and
 * downloading the file are the audit service's responsibility.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#2.3
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use DOMDocument;

/**
 * Format kassakoppelingAuditLog entries for the Belastingdienst audit pack.
 *
 * Output shape (XML):
 *
 * ```xml
 * <KassakoppelingExport>
 *   <Metadata>
 *     <ExportDate>2026-05-21T18:00:00+00:00</ExportDate>
 *     <EntryCount>50</EntryCount>
 *     <DateRange from="2026-05-01" to="2026-05-31"/>
 *     <RegisterList>REG-001, REG-002</RegisterList>
 *     <ChainIntegrity>valid</ChainIntegrity>
 *     <SignatureAlgorithm>HMAC-SHA256</SignatureAlgorithm>
 *   </Metadata>
 *   <Entries>
 *     <Entry>…</Entry>
 *   </Entries>
 * </KassakoppelingExport>
 * ```
 *
 * Output shape (JSON):
 *
 * ```json
 * { "exportMetadata": {…}, "entries": [{…}] }
 * ```
 *
 * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#2.3
 */
class BelastingdienstExportService
{
    /**
     * Signature algorithm advertised in the export metadata.
     *
     * @var string
     */
    public const SIGNATURE_ALGORITHM = 'HMAC-SHA256';

    /**
     * Constructor.
     *
     * @param KassakoppelingSignatureService $signature The signature primitive
     *                                                  used to verify per-register
     *                                                  chain integrity.
     */
    public function __construct(
        private KassakoppelingSignatureService $signature,
    ) {
    }//end __construct()

    /**
     * Render the export pack as XML.
     *
     * The XML body is built with DOMDocument so attribute / element escaping
     * is handled correctly without manual string concatenation. Returns a
     * pretty-printed UTF-8 string with the XML declaration header.
     *
     * @param array<int, array<string, mixed>> $entries  The entries.
     * @param array<string, mixed>|null        $manifest Optional pre-computed
     *                                                   manifest (computed when null).
     *
     * @return string The XML body.
     *
     * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#2.3
     */
    public function exportAsXml(array $entries, ?array $manifest=null): string
    {
        if ($manifest === null) {
            $manifest = $this->buildManifest(entries: $entries, fromDate: '', toDate: '');
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;

        $root = $document->createElement('KassakoppelingExport');
        $document->appendChild($root);

        $metadata = $document->createElement('Metadata');
        $metadata->appendChild($document->createElement('ExportDate', (string) $manifest['exportDate']));
        $metadata->appendChild($document->createElement('EntryCount', (string) $manifest['entryCount']));

        $dateRange = $document->createElement('DateRange');
        $dateRange->setAttribute('from', (string) $manifest['dateRange']['from']);
        $dateRange->setAttribute('to', (string) $manifest['dateRange']['to']);
        $metadata->appendChild($dateRange);

        $registerList = $document->createElement('RegisterList');
        $registerList->appendChild(
            $document->createTextNode(implode(', ', (array) ($manifest['registerList'] ?? [])))
        );
        $metadata->appendChild($registerList);

        $metadata->appendChild($document->createElement('ChainIntegrity', (string) $manifest['chainIntegrity']));
        if (($manifest['chainStatus'] ?? '') !== '') {
            $metadata->appendChild($document->createElement('ChainStatus', (string) $manifest['chainStatus']));
        }

        $metadata->appendChild($document->createElement('SignatureAlgorithm', self::SIGNATURE_ALGORITHM));
        $root->appendChild($metadata);

        $entriesNode = $document->createElement('Entries');
        foreach ($entries as $entry) {
            $entryNode = $document->createElement('Entry');
            foreach ($this->canonicalEntry(entry: $entry) as $field => $value) {
                $childValue = $value;
                if (is_bool($childValue) === true) {
                    $boolAsString = 'false';
                    if ($childValue === true) {
                        $boolAsString = 'true';
                    }

                    $childValue = $boolAsString;
                }

                if ($childValue === null) {
                    $childValue = '';
                }

                $child = $document->createElement($field);
                $child->appendChild($document->createTextNode((string) $childValue));
                $entryNode->appendChild($child);
            }

            $entriesNode->appendChild($entryNode);
        }//end foreach

        $root->appendChild($entriesNode);

        return (string) $document->saveXML();
    }//end exportAsXml()

    /**
     * Render the export pack as pretty-printed JSON.
     *
     * @param array<int, array<string, mixed>> $entries  The entries.
     * @param array<string, mixed>|null        $manifest Optional pre-computed
     *                                                   manifest (computed when null).
     *
     * @return string The JSON body.
     *
     * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#2.3
     */
    public function exportAsJson(array $entries, ?array $manifest=null): string
    {
        if ($manifest === null) {
            $manifest = $this->buildManifest(entries: $entries, fromDate: '', toDate: '');
        }

        $entriesPayload = [];
        foreach ($entries as $entry) {
            $entriesPayload[] = $this->canonicalEntry(entry: $entry);
        }

        $payload = [
            'exportMetadata' => [
                'exportDate'         => (string) $manifest['exportDate'],
                'entryCount'         => (int) $manifest['entryCount'],
                'dateRange'          => $manifest['dateRange'],
                'registerList'       => array_values((array) ($manifest['registerList'] ?? [])),
                'chainIntegrity'     => (string) $manifest['chainIntegrity'],
                'chainStatus'        => (string) ($manifest['chainStatus'] ?? ''),
                'signatureAlgorithm' => self::SIGNATURE_ALGORITHM,
            ],
            'entries'        => $entriesPayload,
        ];

        $encoded = json_encode($payload, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        if ($encoded === false) {
            return '{}';
        }

        return $encoded;
    }//end exportAsJson()

    /**
     * Build the export-manifest metadata.
     *
     * Computes the entry count, unique register list (sorted), exportDate
     * (current UTC), per-register chain integrity (via
     * KassakoppelingSignatureService::verifyHashChain) and a human-readable
     * chainStatus message when the chain is broken.
     *
     * @param array<int, array<string, mixed>> $entries  The entries.
     * @param string                           $fromDate The lower bound (YYYY-MM-DD).
     * @param string                           $toDate   The upper bound (YYYY-MM-DD).
     *
     * @return array<string, mixed> The manifest.
     *
     * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#2.3
     */
    public function buildManifest(array $entries, string $fromDate, string $toDate): array
    {
        $registers = [];
        foreach ($entries as $entry) {
            $register = (string) ($entry['registerNumber'] ?? '');
            if ($register !== '' && in_array($register, $registers, true) === false) {
                $registers[] = $register;
            }
        }

        sort($registers);

        $perRegisterStatus = [];
        $chainIntegrity    = 'valid';
        $chainStatus       = '';

        foreach ($registers as $register) {
            $forRegister = array_values(
                array_filter(
                    $entries,
                    fn (array $row): bool => (string) ($row['registerNumber'] ?? '') === $register
                )
            );

            usort(
                $forRegister,
                fn (array $left, array $right): int => strcmp(
                    (string) ($left['timestamp'] ?? ''),
                    (string) ($right['timestamp'] ?? '')
                )
            );

            $result = $this->signature->verifyHashChain(entries: $forRegister);
            $perRegisterStatus[$register] = $result;
            if ($result['chainValid'] === false) {
                $chainIntegrity = 'invalid';
                if ($chainStatus === '') {
                    $chainStatus = 'Broken at entry '.((int) ($result['brokenAt'] ?? 0)).' on register '.$register;
                }
            }
        }//end foreach

        return [
            'exportDate'         => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'entryCount'         => count($entries),
            'dateRange'          => [
                'from' => $fromDate,
                'to'   => $toDate,
            ],
            'registerList'       => $registers,
            'chainIntegrity'     => $chainIntegrity,
            'chainStatus'        => $chainStatus,
            'perRegisterStatus'  => $perRegisterStatus,
            'signatureAlgorithm' => self::SIGNATURE_ALGORITHM,
        ];
    }//end buildManifest()

    /**
     * Project an entry into the canonical export field order.
     *
     * @param array<string, mixed> $entry The entry.
     *
     * @return array<string, mixed> The canonical projection.
     */
    private function canonicalEntry(array $entry): array
    {
        return [
            'id'              => (string) ($entry['id'] ?? $entry['uuid'] ?? ''),
            'timestamp'       => (string) ($entry['timestamp'] ?? ''),
            'operatorId'      => (string) ($entry['operatorId'] ?? ''),
            'registerNumber'  => (string) ($entry['registerNumber'] ?? ''),
            'action'          => (string) ($entry['action'] ?? ''),
            'amount'          => (int) ($entry['amount'] ?? 0),
            'itemCount'       => (int) ($entry['itemCount'] ?? 0),
            'taxAmount'       => (int) ($entry['taxAmount'] ?? 0),
            'transactionUuid' => (string) ($entry['transactionUuid'] ?? ''),
            'description'     => (string) ($entry['description'] ?? ''),
            'signature'       => (string) ($entry['signature'] ?? ''),
            'previousHash'    => (string) ($entry['previousHash'] ?? ''),
            'currentHash'     => (string) ($entry['currentHash'] ?? ''),
            'verified'        => $entry['verified'] ?? null,
            'exportedAt'      => (string) ($entry['exportedAt'] ?? ''),
        ];
    }//end canonicalEntry()
}//end class
