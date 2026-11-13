<?php

/**
 * Unit tests for the edge paths of MessageDispatchTrait.
 *
 * Pins how the shared dispatch leg treats an answer it did not expect: a
 * provider exception thrown by the leaf passes through unchanged, any other
 * throwable becomes a transient error (logged), a non-array answer or a
 * non-array `response` yields an empty result, and a container entry without
 * a `dispatch()` method is a permanent error.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Provider
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/archive/2026-06-21-pipelinq-messaging-via-or-leaf/tasks.md#3.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Provider;

use OCA\OpenRegister\Service\Integration\Providers\MessageDispatchProvider;
use OCA\Pipelinq\Service\Provider\CmComSmsClient;
use OCA\Pipelinq\Service\Provider\PermanentSmsProviderException;
use OCA\Pipelinq\Service\Provider\TransientSmsProviderException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Edge paths of the dispatch leg, driven through CmComSmsClient::send().
 *
 * @covers \OCA\Pipelinq\Service\Provider\MessageDispatchTrait
 * @covers \OCA\Pipelinq\Service\Provider\LeafSmsClient
 * @covers \OCA\Pipelinq\Service\Provider\CmComSmsClient
 * @uses   \OCA\Pipelinq\Service\Provider\PermanentSmsProviderException
 * @uses   \OCA\Pipelinq\Service\Provider\TransientSmsProviderException
 */
class MessageDispatchTraitEdgeCasesTest extends TestCase {

	/**
	 * A transient provider exception thrown by the leaf reaches the caller unchanged.
	 *
	 * @return void
	 */
	public function testLeafTransientExceptionPassesThroughUnchanged(): void {
		$thrown = new TransientSmsProviderException('vendor 503');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		$client = $this->client(leaf: $this->throwingLeaf(error: $thrown), logger: $logger);

		try {
			$client->send(toNumber: '+31611111111', body: 'hi');
			self::fail('Expected the leaf exception to propagate.');
		} catch (TransientSmsProviderException $e) {
			self::assertSame($thrown, $e);
		}
	}//end testLeafTransientExceptionPassesThroughUnchanged()

	/**
	 * A permanent provider exception thrown by the leaf reaches the caller unchanged.
	 *
	 * @return void
	 */
	public function testLeafPermanentExceptionPassesThroughUnchanged(): void {
		$thrown = new PermanentSmsProviderException('vendor 401');

		$client = $this->client(leaf: $this->throwingLeaf(error: $thrown));

		try {
			$client->send(toNumber: '+31611111111', body: 'hi');
			self::fail('Expected the leaf exception to propagate.');
		} catch (PermanentSmsProviderException $e) {
			self::assertSame($thrown, $e);
		}
	}//end testLeafPermanentExceptionPassesThroughUnchanged()

	/**
	 * Any other throwable from the leaf is logged and becomes a transient error
	 * that wraps it.
	 *
	 * @return void
	 */
	public function testUnexpectedLeafThrowableBecomesTransient(): void {
		$thrown = new RuntimeException('socket closed');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with(
				'MessageDispatchTrait.dispatchViaLeaf: leaf threw unexpectedly',
				['message' => 'socket closed']
			);

		$client = $this->client(leaf: $this->throwingLeaf(error: $thrown), logger: $logger);

		try {
			$client->send(toNumber: '+31611111111', body: 'hi');
			self::fail('Expected a transient provider exception.');
		} catch (TransientSmsProviderException $e) {
			self::assertSame('socket closed', $e->getMessage());
			self::assertSame($thrown, $e->getPrevious());
		}
	}//end testUnexpectedLeafThrowableBecomesTransient()

	/**
	 * A leaf answer that is not an array gives an empty message id, not an error.
	 *
	 * @return void
	 */
	public function testNonArrayLeafAnswerYieldsEmptyMessageId(): void {
		$leaf = new class {
			/**
			 * Answer with a scalar.
			 *
			 * @param string $source  Source slug.
			 * @param array  $body    Body.
			 * @param string $path    Path.
			 * @param array  $headers Headers.
			 *
			 * @return string
			 */
			public function dispatch(string $source, array $body, string $path, array $headers = []): string {
				return 'ok';
			}
		};

		$result = $this->client(leaf: $leaf)->send(toNumber: '+31611111111', body: 'hi');

		self::assertSame(['externalMessageId' => '', 'vendor' => 'cm-com'], $result);
	}//end testNonArrayLeafAnswerYieldsEmptyMessageId()

	/**
	 * A `response` that is not an array gives an empty message id.
	 *
	 * @return void
	 */
	public function testNonArrayResponseYieldsEmptyMessageId(): void {
		$leaf = new MessageDispatchProvider();
		$leaf->nextResult = ['status' => 'sent', 'response' => 'messageId=abc'];

		$result = $this->client(leaf: $leaf)->send(toNumber: '+31611111111', body: 'hi');

		self::assertSame(['externalMessageId' => '', 'vendor' => 'cm-com'], $result);
		self::assertSame(
			[
				[
					'source' => 'cm-sms',
					'body' => ['from' => '+31600000000', 'to' => ['+31611111111'], 'body' => 'hi'],
					'path' => 'messages',
					'headers' => [],
				],
			],
			$leaf->calls
		);
	}//end testNonArrayResponseYieldsEmptyMessageId()

	/**
	 * The message id is read from the array `response` under the vendor key.
	 *
	 * @return void
	 */
	public function testMessageIdIsReadFromResponse(): void {
		$leaf = new MessageDispatchProvider();
		$leaf->nextResult = ['status' => 'sent', 'response' => ['messageId' => 'cm-42']];

		$result = $this->client(leaf: $leaf)->send(toNumber: '+31611111111', body: 'hi');

		self::assertSame(['externalMessageId' => 'cm-42', 'vendor' => 'cm-com'], $result);
	}//end testMessageIdIsReadFromResponse()

	/**
	 * A container entry without a dispatch() method is a permanent error.
	 *
	 * @return void
	 */
	public function testContainerEntryWithoutDispatchIsPermanent(): void {
		$client = $this->client(leaf: new \stdClass());

		$this->expectException(PermanentSmsProviderException::class);
		$this->expectExceptionMessage('OpenRegister MessageDispatchProvider lacks dispatch');
		$client->send(toNumber: '+31611111111', body: 'hi');
	}//end testContainerEntryWithoutDispatchIsPermanent()

	/**
	 * A container miss is a permanent error carrying the container's message.
	 *
	 * @return void
	 */
	public function testContainerMissIsPermanentWithReason(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('no such service'));
		$client = new CmComSmsClient(
			container: $container,
			logger: $this->createMock(LoggerInterface::class),
			credentials: [],
			fromNumber: '+31600000000',
			webhookSecret: 's',
			sourceId: 'cm-sms',
		);

		$this->expectException(PermanentSmsProviderException::class);
		$this->expectExceptionMessage('OpenRegister MessageDispatchProvider not available: no such service');
		$client->send(toNumber: '+31611111111', body: 'hi');
	}//end testContainerMissIsPermanentWithReason()

	/**
	 * A leaf whose dispatch() throws the given error.
	 *
	 * @param \Throwable $error The error to throw.
	 *
	 * @return object
	 */
	private function throwingLeaf(\Throwable $error): object {
		return new class($error) {
			/**
			 * Constructor.
			 *
			 * @param \Throwable $error The error to throw.
			 */
			public function __construct(private \Throwable $error) {
			}

			/**
			 * Throw the configured error.
			 *
			 * @param string $source  Source slug.
			 * @param array  $body    Body.
			 * @param string $path    Path.
			 * @param array  $headers Headers.
			 *
			 * @return array
			 */
			public function dispatch(string $source, array $body, string $path, array $headers = []): array {
				throw $this->error;
			}
		};
	}//end throwingLeaf()

	/**
	 * Build a CM.com client whose container resolves the leaf class to $leaf.
	 *
	 * @param object               $leaf   The object the container returns for the leaf.
	 * @param LoggerInterface|null $logger Logger (a plain mock when null).
	 *
	 * @return CmComSmsClient
	 */
	private function client(object $leaf, ?LoggerInterface $logger = null): CmComSmsClient {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->once())
			->method('get')
			->with(MessageDispatchProvider::class)
			->willReturn($leaf);

		return new CmComSmsClient(
			container: $container,
			logger: $logger ?? $this->createMock(LoggerInterface::class),
			credentials: [],
			fromNumber: '+31600000000',
			webhookSecret: 's',
			sourceId: 'cm-sms',
		);
	}//end client()
}//end class
