<?php

/**
 * Unit tests for the POS payment provider adapters.
 *
 * Covers — without any live provider account or network — the security-critical
 * adapter behaviour: webhook signature verification (each provider's distinct
 * algorithm), provider→normalized status mapping, the amount-to-cents / amount
 * formatting helpers, and the initiate happy path + provider-error path against
 * a fake OCP HTTP client. The external HTTP boundary is fully mocked.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Payment
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Payment;

use OCA\Pipelinq\Service\Payment\AdyenAdapter;
use OCA\Pipelinq\Service\Payment\CcvAdapter;
use OCA\Pipelinq\Service\Payment\MollieAdapter;
use OCA\Pipelinq\Service\Payment\StripeAdapter;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A fake IResponse with a fixed status + body.
 */
class FakeResponse implements IResponse
{
    /**
     * Constructor.
     *
     * @param int    $status The HTTP status.
     * @param string $body   The response body.
     */
    public function __construct(private int $status, private string $body)
    {
    }

    /** {@inheritDoc} @return string */
    public function getBody()
    {
        return $this->body;
    }

    /** {@inheritDoc} @return int */
    public function getStatusCode(): int
    {
        return $this->status;
    }

    /**
     * {@inheritDoc}
     *
     * @param string $key The header name.
     *
     * @return string
     */
    public function getHeader(string $key): string
    {
        return '';
    }

    /** {@inheritDoc} @return array<string, mixed> */
    public function getHeaders(): array
    {
        return [];
    }
}

/**
 * A fake IClient that records requests and returns queued responses.
 */
class FakeHttpClient implements IClient
{
    /** @var array<int, array{method: string, uri: string, options: array<string, mixed>}> */
    public array $requests = [];

    /** @var array<int, IResponse> */
    private array $queue = [];

    /** @var \Throwable|null */
    private ?\Throwable $throw = null;

    /**
     * Queue a response to return on the next request.
     *
     * @param int    $status The status.
     * @param string $body   The body.
     *
     * @return void
     */
    public function queue(int $status, string $body): void
    {
        $this->queue[] = new FakeResponse($status, $body);
    }

    /**
     * Make the next request throw (transport failure).
     *
     * @param \Throwable $e The exception.
     *
     * @return void
     */
    public function willThrow(\Throwable $e): void
    {
        $this->throw = $e;
    }

    /**
     * {@inheritDoc}
     *
     * @param string               $method  The method.
     * @param string               $uri     The URI.
     * @param array<string, mixed> $options The options.
     *
     * @return IResponse
     */
    public function request(string $method, string $uri, array $options = []): IResponse
    {
        $this->requests[] = ['method' => $method, 'uri' => $uri, 'options' => $options];
        if ($this->throw !== null) {
            $e           = $this->throw;
            $this->throw = null;
            throw $e;
        }

        if (empty($this->queue) === true) {
            return new FakeResponse(200, '{}');
        }

        return array_shift($this->queue);
    }

    // --- Unused IClient members (this app only calls request()). ---

    /** {@inheritDoc} */
    public function get(string $uri, array $options = []): IResponse
    {
        return $this->request('GET', $uri, $options);
    }

    /** {@inheritDoc} */
    public function head(string $uri, array $options = []): IResponse
    {
        return $this->request('HEAD', $uri, $options);
    }

    /** {@inheritDoc} */
    public function post(string $uri, array $options = []): IResponse
    {
        return $this->request('POST', $uri, $options);
    }

    /** {@inheritDoc} */
    public function put(string $uri, array $options = []): IResponse
    {
        return $this->request('PUT', $uri, $options);
    }

    /** {@inheritDoc} */
    public function patch(string $uri, array $options = []): IResponse
    {
        return $this->request('PATCH', $uri, $options);
    }

    /** {@inheritDoc} */
    public function delete(string $uri, array $options = []): IResponse
    {
        return $this->request('DELETE', $uri, $options);
    }

    /** {@inheritDoc} */
    public function options(string $uri, array $options = []): IResponse
    {
        return $this->request('OPTIONS', $uri, $options);
    }

    /** {@inheritDoc} */
    public function getResponseFromThrowable(\Throwable $e): IResponse
    {
        return new FakeResponse(500, '');
    }

    /** {@inheritDoc} */
    public function getAsync(string $uri, array $options = []): \OCP\Http\Client\IPromise
    {
        throw new \LogicException('async not used');
    }

    /** {@inheritDoc} */
    public function headAsync(string $uri, array $options = []): \OCP\Http\Client\IPromise
    {
        throw new \LogicException('async not used');
    }

    /** {@inheritDoc} */
    public function postAsync(string $uri, array $options = []): \OCP\Http\Client\IPromise
    {
        throw new \LogicException('async not used');
    }

    /** {@inheritDoc} */
    public function putAsync(string $uri, array $options = []): \OCP\Http\Client\IPromise
    {
        throw new \LogicException('async not used');
    }

    /** {@inheritDoc} */
    public function deleteAsync(string $uri, array $options = []): \OCP\Http\Client\IPromise
    {
        throw new \LogicException('async not used');
    }

    /** {@inheritDoc} */
    public function optionsAsync(string $uri, array $options = []): \OCP\Http\Client\IPromise
    {
        throw new \LogicException('async not used');
    }

    /** {@inheritDoc} */
    public function patchAsync(string $uri, array $options = []): \OCP\Http\Client\IPromise
    {
        throw new \LogicException('async not used');
    }

    /** {@inheritDoc} */
    public function requestAsync(string $method, string $uri, array $options = []): \OCP\Http\Client\IPromise
    {
        throw new \LogicException('async not used');
    }
}

/**
 * Tests for the four payment adapters.
 */
class PaymentAdapterTest extends TestCase
{
    /**
     * Mollie webhook validation accepts the correct HMAC-SHA256 and rejects a forgery.
     *
     * @return void
     */
    public function testMollieWebhookSignature(): void
    {
        $client  = new FakeHttpClient();
        $adapter = new MollieAdapter(client: $client, logger: new NullLogger(), credentials: ['webhookSecret' => 'shh']);

        $body  = 'id=tr_abc123';
        $valid = hash_hmac('sha256', $body, 'shh');

        $this->assertTrue($adapter->validateWebhook(rawBody: $body, signature: $valid));
        $this->assertFalse($adapter->validateWebhook(rawBody: $body, signature: 'deadbeef'));
        // Tampered body must not verify against the original signature.
        $this->assertFalse($adapter->validateWebhook(rawBody: $body.'x', signature: $valid));
    }

    /**
     * No webhook secret configured fails closed (never authenticates).
     *
     * @return void
     */
    public function testMollieWebhookFailsClosedWithoutSecret(): void
    {
        $adapter = new MollieAdapter(client: new FakeHttpClient(), logger: new NullLogger(), credentials: []);
        $body    = 'id=tr_abc';
        // An empty-secret HMAC would still produce a value; the adapter must reject.
        $this->assertFalse($adapter->validateWebhook(rawBody: $body, signature: hash_hmac('sha256', $body, '')));
    }

    /**
     * Mollie status mapping covers the settlement vocabulary.
     *
     * @return void
     */
    public function testMollieStatusMapping(): void
    {
        $client  = new FakeHttpClient();
        $client->queue(200, json_encode(['status' => 'paid']));
        $adapter = new MollieAdapter(client: $client, logger: new NullLogger(), credentials: ['apiKey' => 'k']);

        $parsed = $adapter->parseWebhook(payload: ['id' => 'tr_X']);
        $this->assertSame('tr_X', $parsed['sessionId']);
        $this->assertSame('settled', $parsed['status']);
    }

    /**
     * Mollie initiate returns the hosted checkout URL on success.
     *
     * @return void
     */
    public function testMollieInitiateHappyPath(): void
    {
        $client = new FakeHttpClient();
        $client->queue(201, json_encode([
            'id'     => 'tr_WDqYK6vllg',
            '_links' => ['checkout' => ['href' => 'https://mollie.com/pay/x']],
        ]));
        $adapter = new MollieAdapter(client: $client, logger: new NullLogger(), credentials: ['apiKey' => 'k']);

        $result = $adapter->initiate(transactionData: ['reference' => 'TXN-1'], amount: 21.53, paymentMethod: 'ideal');
        $this->assertSame('tr_WDqYK6vllg', $result['sessionId']);
        $this->assertSame('https://mollie.com/pay/x', $result['redirectUrl']);
        $this->assertSame('pending', $result['status']);
    }

    /**
     * A provider API error becomes a failed result (no exception, no stack trace).
     *
     * @return void
     */
    public function testMollieInitiateProviderError(): void
    {
        $client = new FakeHttpClient();
        $client->queue(401, '{"detail":"invalid key"}');
        $adapter = new MollieAdapter(client: $client, logger: new NullLogger(), credentials: ['apiKey' => 'bad']);

        $result = $adapter->initiate(transactionData: ['reference' => 'TXN-1'], amount: 10.0, paymentMethod: 'ideal');
        $this->assertSame('failed', $result['status']);
        $this->assertSame('', $result['sessionId']);
        $this->assertNotEmpty($result['error']);
        // The user-safe error must not leak the provider body.
        $this->assertStringNotContainsString('invalid key', (string) $result['error']);
    }

    /**
     * A transport timeout becomes a failed result with a connection message.
     *
     * @return void
     */
    public function testTransportFailureMapsToFailed(): void
    {
        $client = new FakeHttpClient();
        $client->willThrow(new \RuntimeException('cURL timeout'));
        $adapter = new StripeAdapter(client: $client, logger: new NullLogger(), credentials: ['apiSecret' => 'sk']);

        $result = $adapter->initiate(transactionData: ['reference' => 'T'], amount: 5.0, paymentMethod: 'card');
        $this->assertSame('failed', $result['status']);
        $this->assertNotEmpty($result['error']);
    }

    /**
     * CCV webhook uses HMAC-SHA512 over merchantId-prefixed body.
     *
     * @return void
     */
    public function testCcvWebhookSignature(): void
    {
        $adapter = new CcvAdapter(
            client: new FakeHttpClient(),
            logger: new NullLogger(),
            credentials: ['webhookSecret' => 'sec', 'config' => ['merchantId' => 'M1']]
        );

        $body  = '{"reference":"CCV1","status":"success"}';
        $valid = hash_hmac('sha512', 'M1'.$body, 'sec');

        $this->assertTrue($adapter->validateWebhook(rawBody: $body, signature: $valid));
        $this->assertFalse($adapter->validateWebhook(rawBody: $body, signature: 'nope'));
    }

    /**
     * CCV initiate returns no redirect URL (terminal flow) and the reference id.
     *
     * @return void
     */
    public function testCcvInitiateTerminalFlow(): void
    {
        $client = new FakeHttpClient();
        $client->queue(200, json_encode(['reference' => 'CCV20260520102833001']));
        $adapter = new CcvAdapter(
            client: $client,
            logger: new NullLogger(),
            credentials: ['apiKey' => 'k', 'config' => ['terminalId' => 'kassa-01']]
        );

        $result = $adapter->initiate(transactionData: ['reference' => 'TXN-3'], amount: 89.97, paymentMethod: 'card');
        $this->assertSame('CCV20260520102833001', $result['sessionId']);
        $this->assertNull($result['redirectUrl']);
        $this->assertSame('pending', $result['status']);
    }

    /**
     * Stripe webhook uses the t=,v1= scheme (HMAC-SHA256 over "t.body").
     *
     * @return void
     */
    public function testStripeWebhookSignature(): void
    {
        $adapter = new StripeAdapter(
            client: new FakeHttpClient(),
            logger: new NullLogger(),
            credentials: ['webhookSecret' => 'whsec_test']
        );

        $body      = '{"id":"evt_1","type":"payment_intent.succeeded"}';
        $timestamp = '1716191640';
        $v1        = hash_hmac('sha256', $timestamp.'.'.$body, 'whsec_test');
        $header    = 't='.$timestamp.',v1='.$v1;

        $this->assertTrue($adapter->validateWebhook(rawBody: $body, signature: $header));
        // Wrong timestamp → different signing string → reject.
        $this->assertFalse($adapter->validateWebhook(rawBody: $body, signature: 't=9999,v1='.$v1));
        // Missing v1 → reject.
        $this->assertFalse($adapter->validateWebhook(rawBody: $body, signature: 't='.$timestamp));
    }

    /**
     * Stripe converts euros to integer cents and parses settled webhooks.
     *
     * @return void
     */
    public function testStripeCentsAndWebhookParse(): void
    {
        $client = new FakeHttpClient();
        $client->queue(200, json_encode(['id' => 'pi_123']));
        $adapter = new StripeAdapter(client: $client, logger: new NullLogger(), credentials: ['apiSecret' => 'sk']);

        $adapter->initiate(transactionData: ['reference' => 'T'], amount: 25.50, paymentMethod: 'card');
        $body = (string) ($client->requests[0]['options']['body'] ?? '');
        $this->assertStringContainsString('amount=2550', $body);

        $parsed = $adapter->parseWebhook(payload: [
            'id'   => 'evt_9',
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => ['payment_intent' => 'pi_123']],
        ]);
        $this->assertSame('pi_123', $parsed['sessionId']);
        $this->assertSame('settled', $parsed['status']);
        $this->assertSame('evt_9', $parsed['eventId']);
    }

    /**
     * Adyen verifies a base64 HMAC-SHA256 over the packed notification fields.
     *
     * @return void
     */
    public function testAdyenWebhookSignatureAndParse(): void
    {
        $hmacKeyHex = bin2hex('adyenkey');
        $adapter    = new AdyenAdapter(
            client: new FakeHttpClient(),
            logger: new NullLogger(),
            credentials: ['webhookSecret' => $hmacKeyHex, 'config' => ['merchantAccount' => 'TestMerchant']]
        );

        $item = [
            'pspReference'        => '8814',
            'originalReference'   => '',
            'merchantAccountCode' => 'TestMerchant',
            'merchantReference'   => 'TXN-9',
            'amount'              => ['value' => 9999, 'currency' => 'EUR'],
            'eventCode'           => 'AUTHORISATION',
            'success'             => 'true',
        ];
        $signing  = implode(':', [
            '8814', '', 'TestMerchant', 'TXN-9', '9999', 'EUR', 'AUTHORISATION', 'true',
        ]);
        $expected = base64_encode(hash_hmac('sha256', $signing, 'adyenkey', true));

        $body = json_encode(['notificationItems' => [['NotificationRequestItem' => $item]]]);
        $this->assertTrue($adapter->validateWebhook(rawBody: $body, signature: $expected));
        $this->assertFalse($adapter->validateWebhook(rawBody: $body, signature: 'forged'));

        $parsed = $adapter->parseWebhook(payload: json_decode($body, true));
        $this->assertSame('8814', $parsed['sessionId']);
        $this->assertSame('captured', $parsed['status']);
    }
}
