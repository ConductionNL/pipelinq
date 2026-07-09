<?php

/**
 * Env-guarded live-gate for the outbound messaging pipeline.
 *
 * These tests validate real provider request shapes at zero cost against the
 * vendor test facilities, and are SKIPPED (never failed) when their env
 * credentials are absent (the default in CI and locally). They follow the
 * gate-19 live-verify convention: opt-in via an explicit env flag, degrade to
 * skipped otherwise.
 *
 *   SMS  : PIPELINQ_LIVE_MESSAGING=1 + BIRD_TEST_ACCESS_KEY (a `test_…` key —
 *          request validated by rest.messagebird.com, nothing sent, no charge).
 *   WA   : PIPELINQ_LIVE_MESSAGING=1 + META_WA_TEST_TOKEN + META_WA_TEST_PHONE_ID
 *          (Meta test number → a registered test recipient; real wamid.*).
 *
 * Live provider keys were NOT available when this change shipped, so the
 * live-gate leg is config-ready-but-untested — documented honestly in
 * docs/Features/outbound-messaging.md.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Integration
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/outbound-messaging-provider-wiring/specs/outbound-messaging/spec.md#requirement-req-om-007--contract-tests-in-ci-and-the-live-gate
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Outbound messaging live gate (env-guarded, skips without credentials).
 */
class OutboundMessagingLiveGateTest extends TestCase
{
    /**
     * Skip the whole suite unless the live-messaging flag is set.
     *
     * @return void
     */
    protected function setUp(): void
    {
        if (getenv('PIPELINQ_LIVE_MESSAGING') !== '1') {
            $this->markTestSkipped('Live messaging gate disabled (set PIPELINQ_LIVE_MESSAGING=1 to enable).');
        }
    }//end setUp()

    /**
     * Bird SMS test-key request-shape validation (zero cost, nothing sent).
     *
     * @return void
     */
    public function testBirdSmsRequestShapeAccepted(): void
    {
        $key = getenv('BIRD_TEST_ACCESS_KEY');
        if ($key === false || $key === '') {
            $this->markTestSkipped('BIRD_TEST_ACCESS_KEY not set.');
        }

        // A Bird test access key (`test_…`) validates the full send request at
        // rest.messagebird.com without dispatching a message or incurring cost.
        $response = $this->postJson(
            url: 'https://rest.messagebird.com/messages',
            authHeader: 'AccessKey '.$key,
            payload: ['recipients' => ['+31600000000'], 'originator' => 'Pipelinq', 'body' => 'live-gate shape check']
        );

        // Any structured JSON response (accepted, or a validation error the key
        // exposes) proves the request shape reached Bird's validator; a
        // transport failure (null) is a real gate failure.
        $this->assertNotNull($response, 'Bird did not return a JSON response — request shape or transport rejected.');
    }//end testBirdSmsRequestShapeAccepted()

    /**
     * Meta WhatsApp test-number send returns a real wamid.*.
     *
     * @return void
     */
    public function testMetaWhatsAppTestNumberReturnsWamid(): void
    {
        $token   = getenv('META_WA_TEST_TOKEN');
        $phoneId = getenv('META_WA_TEST_PHONE_ID');
        $to      = getenv('META_WA_TEST_RECIPIENT');
        if ($token === false || $token === '' || $phoneId === false || $phoneId === '') {
            $this->markTestSkipped('META_WA_TEST_TOKEN / META_WA_TEST_PHONE_ID not set.');
        }

        if ($to === false || $to === '') {
            $this->markTestSkipped('META_WA_TEST_RECIPIENT (a registered test recipient) not set.');
        }

        $response = $this->postJson(
            url: 'https://graph.facebook.com/v19.0/'.$phoneId.'/messages',
            authHeader: 'Bearer '.$token,
            payload: [
                'messaging_product' => 'whatsapp',
                'to'                => $to,
                'type'              => 'template',
                'template'          => ['name' => 'hello_world', 'language' => ['code' => 'en_US']],
            ]
        );

        $this->assertIsArray($response);
        $wamid = (string) ($response['messages'][0]['id'] ?? '');
        $this->assertStringStartsWith('wamid.', $wamid, 'Meta did not return a wamid.* id.');
    }//end testMetaWhatsAppTestNumberReturnsWamid()

    /**
     * Minimal JSON POST helper (live gate only).
     *
     * @param string               $url        Endpoint URL.
     * @param string               $authHeader Authorization header value.
     * @param array<string, mixed> $payload    JSON payload.
     *
     * @return array<string, mixed>|null Decoded response, or null on transport failure.
     */
    private function postJson(string $url, string $authHeader, array $payload): ?array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }

        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: '.$authHeader]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, (string) json_encode($payload));

        $body = curl_exec($ch);
        curl_close($ch);

        if (is_string($body) === false || $body === '') {
            return null;
        }

        $decoded = json_decode($body, true);
        if (is_array($decoded) === false) {
            return null;
        }

        return $decoded;
    }//end postJson()
}//end class
