<?php

/**
 * Unit tests for LogiusConnector — BBK 1.7 outbound payload validation,
 * UUIDv4 generation, signature compute, webhook signature verification.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
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

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\LogiusConnector;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for LogiusConnector.
 */
class LogiusConnectorTest extends TestCase
{
    /**
     * In-memory app config.
     *
     * @var array<string, string>
     */
    private array $appConfigStore = [];

    /**
     * Build the connector with fake collaborators.
     *
     * @return LogiusConnector
     */
    private function buildConnector(): LogiusConnector
    {
        $clientService = $this->createMock(IClientService::class);
        $appConfig     = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            function (string $app, string $key, string $default=''): string {
                return ($this->appConfigStore[$key] ?? $default);
            }
        );
        $logger = $this->createMock(LoggerInterface::class);
        return new LogiusConnector($clientService, $appConfig, $logger);
    }//end buildConnector()

    /**
     * UUIDv4 generation produces a 36-char canonical-form string.
     *
     * @return void
     */
    public function testUuidV4Format(): void
    {
        $connector = $this->buildConnector();
        $uuid      = $connector->newUuidV4();
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $uuid
        );
    }//end testUuidV4Format()

    /**
     * Subject >200 chars throws.
     *
     * @return void
     */
    public function testValidateRejectsSubjectTooLong(): void
    {
        $connector = $this->buildConnector();
        $this->expectException(\RuntimeException::class);
        $connector->validateOutboundPayload([
            'subject' => str_repeat('A', 201),
            'body'    => '<p>x</p>',
            'attachments' => [],
        ]);
    }//end testValidateRejectsSubjectTooLong()

    /**
     * Empty body throws.
     *
     * @return void
     */
    public function testValidateRejectsEmptyBody(): void
    {
        $connector = $this->buildConnector();
        $this->expectException(\RuntimeException::class);
        $connector->validateOutboundPayload([
            'subject' => 'hi',
            'body'    => '',
            'attachments' => [],
        ]);
    }//end testValidateRejectsEmptyBody()

    /**
     * Non-XHTML body throws.
     *
     * @return void
     */
    public function testValidateRejectsBrokenXhtml(): void
    {
        $connector = $this->buildConnector();
        $this->expectException(\RuntimeException::class);
        $connector->validateOutboundPayload([
            'subject' => 'hi',
            'body'    => '<p>broken <br></p>',
            'attachments' => [],
        ]);
    }//end testValidateRejectsBrokenXhtml()

    /**
     * Non-whitelisted MIME throws.
     *
     * @return void
     */
    public function testValidateRejectsNonWhitelistedMime(): void
    {
        $connector = $this->buildConnector();
        $this->expectException(\RuntimeException::class);
        $connector->validateOutboundPayload([
            'subject' => 'hi',
            'body'    => '<p>x</p>',
            'attachments' => [
                ['mime' => 'application/zip', 'sizeBytes' => 100],
            ],
        ]);
    }//end testValidateRejectsNonWhitelistedMime()

    /**
     * Aggregate size >25MB throws.
     *
     * @return void
     */
    public function testValidateRejectsOversizedAttachments(): void
    {
        $connector = $this->buildConnector();
        $this->expectException(\RuntimeException::class);
        $connector->validateOutboundPayload([
            'subject' => 'hi',
            'body'    => '<p>x</p>',
            'attachments' => [
                ['mime' => 'application/pdf', 'sizeBytes' => (26 * 1024 * 1024)],
            ],
        ]);
    }//end testValidateRejectsOversizedAttachments()

    /**
     * Valid payload passes (no exception).
     *
     * @return void
     */
    public function testValidatePassesGoodPayload(): void
    {
        $connector = $this->buildConnector();
        $connector->validateOutboundPayload([
            'subject' => 'Uw paspoort is gereed',
            'body'    => '<p>Hello, burger.</p>',
            'attachments' => [
                ['mime' => 'application/pdf', 'sizeBytes' => 1024],
                ['mime' => 'image/png', 'sizeBytes' => 2048],
            ],
        ]);
        $this->addToAssertionCount(1);
    }//end testValidatePassesGoodPayload()

    /**
     * Webhook signature verification succeeds when the header matches the
     * tenant-configured HMAC of the body.
     *
     * @return void
     */
    public function testWebhookSignatureValid(): void
    {
        $this->appConfigStore[LogiusConnector::CONFIG_WEBHOOK_SECRET] = 'unit-secret';
        $connector = $this->buildConnector();
        $rawBody   = '{"a":1}';
        $expected  = hash_hmac('sha256', $rawBody, 'unit-secret');

        $request = $this->createMock(IRequest::class);
        $request->method('getHeader')->willReturnCallback(
            static function (string $name) use ($expected): string {
                return ($name === 'X-Logius-Signature') ? $expected : '';
            }
        );

        $this->assertTrue($connector->handleWebhookSignature($request, $rawBody));
    }//end testWebhookSignatureValid()

    /**
     * Wrong signature is rejected.
     *
     * @return void
     */
    public function testWebhookSignatureInvalid(): void
    {
        $this->appConfigStore[LogiusConnector::CONFIG_WEBHOOK_SECRET] = 'unit-secret';
        $connector = $this->buildConnector();

        $request = $this->createMock(IRequest::class);
        $request->method('getHeader')->willReturn('bogus-signature');

        $this->assertFalse($connector->handleWebhookSignature($request, '{"a":1}'));
    }//end testWebhookSignatureInvalid()

    /**
     * Missing webhook secret causes verification to refuse.
     *
     * @return void
     */
    public function testWebhookSignatureRefusedWithoutSecret(): void
    {
        $connector = $this->buildConnector();
        $request   = $this->createMock(IRequest::class);
        $request->method('getHeader')->willReturn('any-sig');
        $this->assertFalse($connector->handleWebhookSignature($request, '{}'));
    }//end testWebhookSignatureRefusedWithoutSecret()

    /**
     * signRequest with no PKI key returns deterministic dev signature.
     *
     * @return void
     */
    public function testSignRequestDevFallback(): void
    {
        $connector = $this->buildConnector();
        $sig = $connector->signRequest(['a' => 1], '', '');
        $this->assertNotEmpty($sig);
        // Idempotent for the same body.
        $sig2 = $connector->signRequest(['a' => 1], '', '');
        $this->assertSame($sig, $sig2);
    }//end testSignRequestDevFallback()
}//end class
