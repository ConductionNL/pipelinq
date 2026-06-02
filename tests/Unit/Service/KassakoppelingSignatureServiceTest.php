<?php

/**
 * Unit tests for KassakoppelingSignatureService.
 *
 * Verifies the HMAC-SHA256 signature is deterministic and tamper-evident, that
 * verifySignature is constant-time-correct on both a valid and a mutated entry,
 * that generateHash produces a stable SHA-256 chain link, and that
 * verifyHashChain accepts an intact chain and rejects a broken one.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
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

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\KassakoppelingSignatureService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Test suite for the Kassakoppeling signing primitives.
 */
class KassakoppelingSignatureServiceTest extends TestCase
{
    /**
     * The configured signing secret used across the suite.
     *
     * @var string
     */
    private const SECRET = 'test-secret-key';

    /**
     * The service under test.
     *
     * @var KassakoppelingSignatureService
     */
    private KassakoppelingSignatureService $service;

    /**
     * Build the service with a stub app config returning the test secret.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturn(self::SECRET);

        $this->service = new KassakoppelingSignatureService($appConfig);
    }//end setUp()

    /**
     * A representative entry payload.
     *
     * @return array<string, mixed> The entry.
     */
    private function entry(): array
    {
        return [
            'operatorId'      => 'user_john',
            'registerNumber'  => 'REG-001',
            'action'          => 'sale',
            'amount'          => 4950,
            'itemCount'       => 3,
            'taxAmount'       => 870,
            'timestamp'       => '2026-05-20T08:15:30+00:00',
            'transactionUuid' => 'uuid-txn-1',
            'previousHash'    => '0',
        ];
    }//end entry()

    /**
     * generateSignature returns the HMAC-SHA256 over the ordered fields.
     *
     * @return void
     */
    public function testGenerateSignatureMatchesHmac(): void
    {
        $entry    = $this->entry();
        $message  = 'user_john|REG-001|sale|4950|870|2026-05-20T08:15:30+00:00|0';
        $expected = hash_hmac('sha256', $message, self::SECRET);

        $this->assertSame($expected, $this->service->generateSignature($entry));
    }//end testGenerateSignatureMatchesHmac()

    /**
     * A signature over the original entry verifies true.
     *
     * @return void
     */
    public function testVerifySignatureValid(): void
    {
        $entry     = $this->entry();
        $signature = $this->service->generateSignature($entry);

        $this->assertTrue($this->service->verifySignature($entry, $signature));
    }//end testVerifySignatureValid()

    /**
     * Mutating the amount invalidates the signature.
     *
     * @return void
     */
    public function testVerifySignatureFailsOnTamper(): void
    {
        $entry     = $this->entry();
        $signature = $this->service->generateSignature($entry);

        $entry['amount'] = 9999;

        $this->assertFalse($this->service->verifySignature($entry, $signature));
    }//end testVerifySignatureFailsOnTamper()

    /**
     * An empty signature never verifies.
     *
     * @return void
     */
    public function testVerifySignatureFailsOnEmpty(): void
    {
        $this->assertFalse($this->service->verifySignature($this->entry(), ''));
    }//end testVerifySignatureFailsOnEmpty()

    /**
     * generateHash returns a deterministic 64-char SHA-256 hex digest.
     *
     * @return void
     */
    public function testGenerateHashIsDeterministic(): void
    {
        $entry = $this->entry();

        $hashA = $this->service->generateHash($entry, '0');
        $hashB = $this->service->generateHash($entry, '0');

        $this->assertSame($hashA, $hashB);
        $this->assertSame(64, strlen($hashA));
        $this->assertNotSame($hashA, $this->service->generateHash($entry, 'different-prev'));
    }//end testGenerateHashIsDeterministic()

    /**
     * verifyHashChain accepts an intact two-entry chain.
     *
     * @return void
     */
    public function testVerifyHashChainValid(): void
    {
        $chain = $this->buildChain();

        $this->assertTrue($this->service->verifyHashChain($chain));
    }//end testVerifyHashChainValid()

    /**
     * verifyHashChain rejects a chain whose second link was rewritten.
     *
     * @return void
     */
    public function testVerifyHashChainBroken(): void
    {
        $chain = $this->buildChain();

        // Tamper with the second entry's amount without re-hashing.
        $chain[1]['amount'] = 1;

        $this->assertFalse($this->service->verifyHashChain($chain));
    }//end testVerifyHashChainBroken()

    /**
     * verifyHashChain rejects a chain whose link pointer is wrong.
     *
     * @return void
     */
    public function testVerifyHashChainBrokenLink(): void
    {
        $chain = $this->buildChain();

        $chain[1]['previousHash'] = 'deadbeef';

        $this->assertFalse($this->service->verifyHashChain($chain));
    }//end testVerifyHashChainBrokenLink()

    /**
     * An unconfigured secret is a hard error (fail closed).
     *
     * @return void
     */
    public function testMissingSecretThrows(): void
    {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturn('');
        $service = new KassakoppelingSignatureService($appConfig);

        $this->expectException(RuntimeException::class);
        $service->getSecretKey();
    }//end testMissingSecretThrows()

    /**
     * Build a valid two-entry chain whose hashes link correctly.
     *
     * @return array<int, array<string, mixed>> The chain.
     */
    private function buildChain(): array
    {
        $first = [
            'operatorId'      => 'user_john',
            'registerNumber'  => 'REG-001',
            'action'          => 'sale',
            'amount'          => 4950,
            'itemCount'       => 3,
            'taxAmount'       => 870,
            'timestamp'       => '2026-05-20T08:15:30+00:00',
            'transactionUuid' => 'uuid-1',
            'previousHash'    => '0',
        ];
        $first['currentHash'] = $this->service->generateHash($first, '0');

        $second = [
            'operatorId'      => 'user_john',
            'registerNumber'  => 'REG-001',
            'action'          => 'void',
            'amount'          => 4950,
            'itemCount'       => 3,
            'taxAmount'       => 870,
            'timestamp'       => '2026-05-20T08:18:15+00:00',
            'transactionUuid' => 'uuid-1',
            'previousHash'    => $first['currentHash'],
        ];
        $second['currentHash'] = $this->service->generateHash($second, $first['currentHash']);

        return [$first, $second];
    }//end buildChain()
}//end class
