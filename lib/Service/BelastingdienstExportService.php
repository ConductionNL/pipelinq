<?php

/**
 * Pipelinq BelastingdienstExportService.
 *
 * Formats Kassakoppeling audit entries into the Belastingdienst export
 * representations (XML and JSON) and builds the export manifest (entry count,
 * register list, date range and hash-chain integrity). Pure formatting: it
 * never mutates or persists entries.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V.
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
use DOMDocument;

/**
 * Builds Belastingdienst Kassakoppeling export documents.
 *
 * The XML and JSON renderings carry the same metadata manifest and entry list.
 * Hash-chain integrity is computed via the signature service so a broken chain
 * is reported as chainIntegrity=invalid with the index of the first broken
 * link, while every entry is still included in the export.
 *
 * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#2.3
 */
class BelastingdienstExportService
{
    /**
     * Signature algorithm label emitted in the manifest.
     *
     * @var string
     */
    private const SIGNATURE_ALGORITHM = 'HMAC-SHA256';

    /**
     * Entry fields exported to the Belastingdienst (server-authoritative only).
     *
     * @var string[]
     */
    private const EXPORT_FIELDS = [
        'timestamp',
        'operatorId',
        'registerNumber',
        'action',
        'amount',
        'taxAmount',
        'signature',
        'currentHash',
        'previousHash',
        'verified',
    ];

    /**
     * Constructor.
     *
     * @param KassakoppelingSignatureService $signatureService The signature service.
     */
    public function __construct(private KassakoppelingSignatureService $signatureService)
    {
    }//end __construct()

    /**
     * Build the export metadata manifest for a set of entries.
     *
     * @param array<int, array<string, mixed>> $entries The entries (chain order).
     *
     * @return array<string, mixed> The manifest.
     *
     * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#2.3
     */
    public function buildManifest(array $entries): array
    {
        $registers  = [];
        $timestamps = [];
        foreach ($entries as $entry) {
            $register = (string) ($entry['registerNumber'] ?? '');
            if ($register !== '') {
                $registers[$register] = true;
            }

            $timestamp = (string) ($entry['timestamp'] ?? '');
            if ($timestamp !== '') {
                $timestamps[] = $timestamp;
            }
        }

        sort($timestamps);
        $from = '';
        $to   = '';
        if (count($timestamps) > 0) {
            $from = $timestamps[0];
            $to   = $timestamps[(count($timestamps) - 1)];
        }

        $chain = $this->chainStatus(entries: $entries);

        return [
            'exportDate'         => $this->now(),
            'entryCount'         => count($entries),
            'dateRange'          => [
                'from' => $from,
                'to'   => $to,
            ],
            'registerList'       => array_keys($registers),
            'chainIntegrity'     => $chain['integrity'],
            'chainStatus'        => $chain['status'],
            'signatureAlgorithm' => self::SIGNATURE_ALGORITHM,
        ];
    }//end buildManifest()

    /**
     * Render the entries as a Belastingdienst JSON document.
     *
     * @param array<int, array<string, mixed>> $entries The entries (chain order).
     *
     * @return string The pretty-printed JSON.
     *
     * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#2.3
     */
    public function exportAsJson(array $entries): string
    {
        $manifest = $this->buildManifest(entries: $entries);

        $rows = [];
        foreach ($entries as $entry) {
            $rows[] = $this->exportRow(entry: $entry);
        }

        $document = [
            'exportMetadata' => $manifest,
            'entries'        => $rows,
        ];

        $json = json_encode($document, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        if ($json === false) {
            return '{}';
        }

        return $json;
    }//end exportAsJson()

    /**
     * Render the entries as a Belastingdienst XML document.
     *
     * @param array<int, array<string, mixed>> $entries The entries (chain order).
     *
     * @return string The XML string with declaration.
     *
     * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#2.3
     */
    public function exportAsXml(array $entries): string
    {
        $manifest = $this->buildManifest(entries: $entries);

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElement('KassakoppelingExport');
        $dom->appendChild($root);

        $metadata = $dom->createElement('Metadata');
        $root->appendChild($metadata);

        $dateRange = ((string) $manifest['dateRange']['from'].' to '.(string) $manifest['dateRange']['to']);
        $this->appendChild(dom: $dom, parent: $metadata, name: 'ExportDate', value: (string) $manifest['exportDate']);
        $this->appendChild(dom: $dom, parent: $metadata, name: 'EntryCount', value: (string) $manifest['entryCount']);
        $this->appendChild(dom: $dom, parent: $metadata, name: 'DateRange', value: $dateRange);
        $this->appendChild(
            dom: $dom,
            parent: $metadata,
            name: 'RegisterList',
            value: implode(', ', $manifest['registerList'])
        );
        $this->appendChild(
            dom: $dom,
            parent: $metadata,
            name: 'ChainIntegrity',
            value: (string) $manifest['chainIntegrity']
        );
        $this->appendChild(dom: $dom, parent: $metadata, name: 'ChainStatus', value: (string) $manifest['chainStatus']);
        $this->appendChild(
            dom: $dom,
            parent: $metadata,
            name: 'SignatureAlgorithm',
            value: (string) $manifest['signatureAlgorithm']
        );

        $entriesNode = $dom->createElement('Entries');
        $root->appendChild($entriesNode);

        foreach ($entries as $entry) {
            $entryNode = $dom->createElement('Entry');
            $entriesNode->appendChild($entryNode);
            $row = $this->exportRow(entry: $entry);

            $this->appendChild(dom: $dom, parent: $entryNode, name: 'Timestamp', value: (string) $row['timestamp']);
            $this->appendChild(dom: $dom, parent: $entryNode, name: 'OperatorId', value: (string) $row['operatorId']);
            $this->appendChild(
                dom: $dom,
                parent: $entryNode,
                name: 'RegisterNumber',
                value: (string) $row['registerNumber']
            );
            $this->appendChild(dom: $dom, parent: $entryNode, name: 'Action', value: (string) $row['action']);
            $this->appendChild(dom: $dom, parent: $entryNode, name: 'Amount', value: (string) $row['amount']);
            $this->appendChild(dom: $dom, parent: $entryNode, name: 'TaxAmount', value: (string) $row['taxAmount']);
            $this->appendChild(dom: $dom, parent: $entryNode, name: 'Signature', value: (string) $row['signature']);
            $this->appendChild(dom: $dom, parent: $entryNode, name: 'CurrentHash', value: (string) $row['currentHash']);
            $this->appendChild(
                dom: $dom,
                parent: $entryNode,
                name: 'PreviousHash',
                value: (string) $row['previousHash']
            );
            $this->appendChild(
                dom: $dom,
                parent: $entryNode,
                name: 'Verified',
                value: $this->boolText(value: $row['verified'])
            );
        }//end foreach

        $xml = $dom->saveXML();
        if ($xml === false) {
            return '<?xml version="1.0" encoding="UTF-8"?><KassakoppelingExport/>';
        }

        return $xml;
    }//end exportAsXml()

    /**
     * Project an entry to its exported (whitelisted) fields.
     *
     * @param array<string, mixed> $entry The full entry.
     *
     * @return array<string, mixed> The export row.
     */
    private function exportRow(array $entry): array
    {
        $row = [];
        foreach (self::EXPORT_FIELDS as $field) {
            $row[$field] = ($entry[$field] ?? null);
        }

        $row['amount']    = (int) ($entry['amount'] ?? 0);
        $row['taxAmount'] = (int) ($entry['taxAmount'] ?? 0);

        return $row;
    }//end exportRow()

    /**
     * Compute the hash-chain integrity status for the manifest.
     *
     * @param array<int, array<string, mixed>> $entries The entries (chain order).
     *
     * @return array{integrity: string, status: string} The integrity result.
     */
    private function chainStatus(array $entries): array
    {
        if ($this->signatureService->verifyHashChain(entries: $entries) === true) {
            return [
                'integrity' => 'valid',
                'status'    => 'Chain intact across all entries.',
            ];
        }

        $expectedPrevious = '0';
        $index            = 0;
        foreach ($entries as $entry) {
            $index++;
            $previousHash = (string) ($entry['previousHash'] ?? '');
            $computed     = $this->signatureService->generateHash(entryData: $entry, previousHash: $previousHash);
            $matchesPrev  = ($previousHash === $expectedPrevious);
            $matchesSelf  = hash_equals($computed, (string) ($entry['currentHash'] ?? ''));
            if ($matchesPrev === false || $matchesSelf === false) {
                return [
                    'integrity' => 'invalid',
                    'status'    => 'Broken at entry '.$index.': previousHash mismatch',
                ];
            }

            $expectedPrevious = (string) ($entry['currentHash'] ?? '');
        }

        return [
            'integrity' => 'invalid',
            'status'    => 'Chain integrity could not be confirmed.',
        ];
    }//end chainStatus()

    /**
     * Append a text child element to a parent node.
     *
     * @param DOMDocument $dom    The document.
     * @param \DOMElement $parent The parent element.
     * @param string      $name   The child tag name.
     * @param string      $value  The text content.
     *
     * @return void
     */
    private function appendChild(DOMDocument $dom, \DOMElement $parent, string $name, string $value): void
    {
        $child = $dom->createElement($name);
        $child->appendChild($dom->createTextNode($value));
        $parent->appendChild($child);
    }//end appendChild()

    /**
     * Render a tri-state verified flag as XML text.
     *
     * @param mixed $value The verified value (true / false / null).
     *
     * @return string The text representation.
     */
    private function boolText(mixed $value): string
    {
        if ($value === null) {
            return 'pending';
        }

        if ((bool) $value === true) {
            return 'true';
        }

        return 'false';
    }//end boolText()

    /**
     * Current time as an ISO 8601 string.
     *
     * @return string The current timestamp.
     */
    private function now(): string
    {
        return (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
    }//end now()
}//end class
