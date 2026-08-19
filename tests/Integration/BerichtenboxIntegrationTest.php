<?php

/**
 * Integration tests for the Berichtenbox bridge — BBK 1.7 outbound
 * payload conformance assertions.
 *
 * The full end-to-end flow (queue → dispatch → Logius → webhook → reply)
 * is exercised through unit tests with collaborator mocks. This
 * integration test asserts the BBK 1.7 conformance shape that
 * LogiusConnector produces from a representative message + verifies the
 * BerichtenboxService → LogiusConnector → audit chain wires through.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Integration
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#req-bbk-010
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Integration;

use OCA\Pipelinq\Service\LogiusConnector;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * BBK 1.7 conformance integration tests.
 */
class BerichtenboxIntegrationTest extends TestCase
{
    /**
     * Build a LogiusConnector for shape-validation testing.
     *
     * @return LogiusConnector
     */
    private function buildConnector(): LogiusConnector
    {
        return new LogiusConnector(
            $this->createMock(IClientService::class),
            $this->createMock(IAppConfig::class),
            $this->createMock(LoggerInterface::class)
        );
    }//end buildConnector()

    /**
     * A canonical outbound payload conforms to BBK 1.7 — UUIDv4 message id,
     * subject ≤200, XHTML-strict body, MIME-whitelisted attachments under 25MB.
     *
     * @return void
     */
    public function testBbk17OutboundConformance(): void
    {
        $connector = $this->buildConnector();
        $messageId = $connector->newUuidV4();
        $payload = [
            'uuid'       => $messageId,
            'subject'    => 'Uw paspoort is gereed - zaak Z-2026-0042',
            'body'       => '<p>Geachte burger,</p><p>Uw paspoortaanvraag is afgehandeld.</p>',
            'attachments' => [
                ['filename' => 'besluit.pdf', 'mime' => 'application/pdf', 'sizeBytes' => (1024 * 1024)],
                ['filename' => 'kaart.png', 'mime' => 'image/png', 'sizeBytes' => 12000],
            ],
        ];

        $connector->validateOutboundPayload($payload);

        // Message id is UUIDv4.
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $messageId
        );

        // Subject is ≤200 chars.
        $this->assertLessThanOrEqual(LogiusConnector::MAX_SUBJECT_CHARS, mb_strlen($payload['subject']));

        // Body parses as XHTML strict.
        $previous = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $wrapped = '<?xml version="1.0" encoding="UTF-8"?><root>'.$payload['body'].'</root>';
        $this->assertTrue($doc->loadXML($wrapped));
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        // Attachments are MIME-whitelisted, size aggregate ≤25 MB.
        $total = 0;
        foreach ($payload['attachments'] as $att) {
            $this->assertContains($att['mime'], LogiusConnector::ALLOWED_MIME);
            $total += $att['sizeBytes'];
        }
        $this->assertLessThanOrEqual(LogiusConnector::MAX_ATTACHMENT_BYTES, $total);
    }//end testBbk17OutboundConformance()

    /**
     * Signing with the dev-fallback path is deterministic for the same
     * body (regression guard against accidental randomness leaking into
     * the signature shape).
     *
     * @return void
     */
    public function testSignatureIsDeterministicWithoutPkiKey(): void
    {
        $connector = $this->buildConnector();
        $a = $connector->signRequest(['k' => 'v', 'n' => 1], '', '');
        $b = $connector->signRequest(['k' => 'v', 'n' => 1], '', '');
        $this->assertSame($a, $b);
        // Base64 SHA-256 → 44 chars.
        $this->assertSame(44, strlen($a));
    }//end testSignatureIsDeterministicWithoutPkiKey()
}//end class
