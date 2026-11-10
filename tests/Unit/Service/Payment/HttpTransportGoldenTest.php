<?php

/**
 * Pipelinq payment transport golden tests.
 *
 * Pins what both payment transports send and refuse, so their request methods can
 * be split into named steps without moving an outcome: the direct transport refuses
 * any request carrying the broker-managed placeholder and otherwise sends it, and the
 * broker transport fails closed without a credential and on a broker failure.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Payment
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/pos-psp-keys-via-broker/tasks.md#task-1-brokerhttptransport
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Payment;

use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCA\Pipelinq\Service\Payment\AbstractPaymentAdapter;
use OCA\Pipelinq\Service\Payment\BrokerHttpTransport;
use OCA\Pipelinq\Service\Payment\CurlHttpTransport;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class HttpTransportGoldenTest extends TestCase {
	/**
	 * A temporary file served through file:// to the direct transport.
	 *
	 * @var string
	 */
	private string $file = '';

	/**
	 * Remove the temporary file.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ($this->file !== '' && file_exists($this->file) === true) {
			unlink($this->file);
		}

		parent::tearDown();
	}//end tearDown()

	/**
	 * Refused: the placeholder in any header value, not only the first, stops the send.
	 *
	 * @return void
	 */
	public function testCurlRefusesThePlaceholderInAnyHeader(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'Pipelinq POS payment: refusing to send a PSP request directly — it carries the '
			. 'broker-managed placeholder, which means it bypassed the credential broker.'
		);
		$logger->expects($this->never())->method('warning');

		$result = (new CurlHttpTransport(logger: $logger))->request(
			method: 'GET',
			url: 'file://' . $this->jsonFile('{"sent":true}'),
			headers: [
				'Content-Type' => 'application/json',
				'X-Token' => 'prefix-' . AbstractPaymentAdapter::BROKER_MANAGED_SECRET . '-suffix',
			]
		);

		$this->assertSame(['status' => 0, 'body' => [], 'raw' => ''], $result);
	}//end testCurlRefusesThePlaceholderInAnyHeader()

	/**
	 * Allowed: headers without the placeholder are sent and the JSON reply decoded.
	 *
	 * @return void
	 */
	public function testCurlSendsAndDecodes(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('error');
		$transport = new CurlHttpTransport(logger: $logger);

		$json = $transport->request(method: 'GET', url: 'file://' . $this->jsonFile('{"id":"tr_1","n":2}'), headers: ['Accept' => 'application/json']);
		$this->assertSame(['status' => 0, 'body' => ['id' => 'tr_1', 'n' => 2], 'raw' => '{"id":"tr_1","n":2}'], $json);

		$text = $transport->request(method: 'GET', url: 'file://' . $this->jsonFile('plain'), body: 'x');
		$this->assertSame(['status' => 0, 'body' => [], 'raw' => 'plain'], $text);

		$scalar = $transport->request(method: 'GET', url: 'file://' . $this->jsonFile('42'));
		$this->assertSame(['status' => 0, 'body' => [], 'raw' => '42'], $scalar);

		$missing = $transport->request(method: 'GET', url: 'file:///nonexistent/pipelinq-golden.json');
		$this->assertSame(['status' => 0, 'body' => [], 'raw' => ''], $missing);
	}//end testCurlSendsAndDecodes()

	/**
	 * Refused: no credential means no broker lookup at all.
	 *
	 * @return void
	 */
	public function testBrokerRefusesWithoutACredential(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'Pipelinq POS payment: no broker credential configured for this provider.'
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');

		$result = (new BrokerHttpTransport(credentialId: '', logger: $logger, container: $container))
			->request(method: 'POST', url: 'https://psp.example/v1/pay');

		$this->assertSame(['status' => 0, 'body' => [], 'raw' => ''], $result);
	}//end testBrokerRefusesWithoutACredential()

	/**
	 * Refused: a broker failure logs only the method and path and returns nothing.
	 *
	 * @return void
	 */
	public function testBrokerFailureIsLoggedWithoutDetails(): void {
		$broker = $this->createMock(CredentialBrokerService::class);
		$broker->method('request')->willThrowException(new \RuntimeException('secret-free failure'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('error');
		$logger->expects($this->once())->method('warning')->with(
			'Pipelinq POS payment: broker call failed',
			['method' => 'PATCH', 'path' => '/v1/pay/1?expand=x']
		);

		$result = $this->broker($broker, $logger)->request(method: 'PATCH', url: 'https://psp.example/v1/pay/1?expand=x', body: '{"card":"4111"}');

		$this->assertSame(['status' => 0, 'body' => [], 'raw' => ''], $result);
	}//end testBrokerFailureIsLoggedWithoutDetails()

	/**
	 * Allowed: the broker reply is passed through; a missing status or body reads as 0 and '',
	 * a non-JSON body decodes to an empty array, and a URL without a path goes to `/`.
	 *
	 * @return void
	 */
	public function testBrokerReplyShapes(): void {
		$broker = $this->createMock(CredentialBrokerService::class);
		$broker->expects($this->exactly(3))->method('request')
			->with('cred-1', 'pipelinq', 'GET', $this->logicalOr('/', '/v1/x'), [], null, null)
			->willReturnOnConsecutiveCalls(
				['status' => '204'],
				['status' => 200, 'body' => 'not json'],
				['status' => 200, 'body' => '[1,2]'],
			);
		$logger = $this->createMock(LoggerInterface::class);
		$transport = $this->broker($broker, $logger);

		$this->assertSame(['status' => 204, 'body' => [], 'raw' => ''], $transport->request(method: 'GET', url: 'https://psp.example'));
		$this->assertSame(['status' => 200, 'body' => [], 'raw' => 'not json'], $transport->request(method: 'GET', url: 'https://psp.example/v1/x'));
		$this->assertSame(['status' => 200, 'body' => [1, 2], 'raw' => '[1,2]'], $transport->request(method: 'GET', url: 'https://psp.example/v1/x'));
	}//end testBrokerReplyShapes()

	/**
	 * A broker transport whose container serves the given broker.
	 *
	 * @param CredentialBrokerService $broker The broker double.
	 * @param LoggerInterface         $logger The logger.
	 *
	 * @return BrokerHttpTransport
	 */
	private function broker(CredentialBrokerService $broker, LoggerInterface $logger): BrokerHttpTransport {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(BrokerHttpTransport::BROKER_CLASS)->willReturn($broker);
		return new BrokerHttpTransport(credentialId: 'cred-1', logger: $logger, container: $container);
	}//end broker()

	/**
	 * Write a temporary file with the given contents.
	 *
	 * @param string $contents The contents.
	 *
	 * @return string The path.
	 */
	private function jsonFile(string $contents): string {
		if ($this->file !== '' && file_exists($this->file) === true) {
			unlink($this->file);
		}

		$this->file = (string)tempnam(sys_get_temp_dir(), 'pq-golden');
		file_put_contents($this->file, $contents);
		return $this->file;
	}//end jsonFile()
}//end class
