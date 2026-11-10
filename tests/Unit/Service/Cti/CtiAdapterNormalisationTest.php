<?php

/**
 * Characterisation tests for the CTI adapters' webhook normalisation and call origination.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Cti
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Cti;

use OCA\Pipelinq\Service\Cti\Adapter\AsteriskAdapter;
use OCA\Pipelinq\Service\Cti\Adapter\CallVoipAdapter;
use OCA\Pipelinq\Service\Cti\Adapter\RingCentralAdapter;
use OCA\Pipelinq\Service\Cti\Result\CtiCallResult;
use OCA\Pipelinq\Service\Cti\Result\CtiWebhookResult;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins every field each adapter derives from a webhook payload and every
 * outcome of originateCall, so the adapters can be restructured safely.
 */
class CtiAdapterNormalisationTest extends TestCase {

	/**
	 * The app config stub.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig $appConfig;

	/**
	 * The HTTP client stub.
	 *
	 * @var IClient&MockObject
	 */
	private IClient $client;

	/**
	 * The logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Config values served by the app config stub.
	 *
	 * @var array<string,string>
	 */
	private array $config = [];

	/**
	 * Build the shared stubs.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->config = [];
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default)
		);
		$this->client = $this->createMock(IClient::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Asterisk maps every field of a full payload.
	 *
	 * @return void
	 */
	public function testAsteriskFullPayload(): void {
		$payload = [
			'Event' => 'BridgeEnter',
			'Uniqueid' => 'ast-1',
			'Channel' => 'PJSIP/2042-0000',
			'Duration' => '01:02:03',
			'State' => 'ONHOLD',
			'direction' => 'outbound',
			'CallerIDNum' => '+3161',
			'ConnectedLineNum' => '+3162',
			'userId' => 'alice',
			'RecordingURL' => 'https://rec/1',
			'RecordingExpiresAt' => '2026-12-01T00:00:00Z',
			'Queue' => 'support',
		];
		$result = $this->asterisk()->handleInboundWebhook($payload);

		$this->assertSame(
			[
				'eventType' => 'answered',
				'externalCallId' => 'ast-1',
				'direction' => 'outbound',
				'fromNumber' => '+3161',
				'toNumber' => '+3162',
				'extension' => '2042',
				'userId' => 'alice',
				'durationSeconds' => 3723,
				'recordingUrl' => 'https://rec/1',
				'recordingExpiresAt' => '2026-12-01T00:00:00Z',
				'presenceState' => 'wrap-up',
				'queueName' => 'support',
				'agentSkill' => null,
				'raw' => $payload,
			],
			$this->fields($result)
		);
	}//end testAsteriskFullPayload()

	/**
	 * Asterisk falls back to lower-case keys and leaves absent fields null.
	 *
	 * @return void
	 */
	public function testAsteriskLowerCaseKeysAndEmptyPayload(): void {
		$result = $this->asterisk()->handleInboundWebhook(
			['event' => 'DeviceStateChange', 'uniqueid' => 'u-2', 'channel' => 'Local/abc', 'duration' => '42', 'DeviceState' => 'BUSY']
		);
		$this->assertSame('presence', $result->eventType);
		$this->assertSame('u-2', $result->externalCallId);
		$this->assertNull($result->extension);
		$this->assertSame(42, $result->durationSeconds);
		$this->assertSame('on-call', $result->presenceState);

		$empty = $this->asterisk()->handleInboundWebhook([]);
		$this->assertSame(
			['unknown', '', null, null, null, null, null, null, null],
			[
				$empty->eventType,
				$empty->externalCallId,
				$empty->direction,
				$empty->fromNumber,
				$empty->extension,
				$empty->durationSeconds,
				$empty->presenceState,
				$empty->queueName,
				$empty->recordingUrl,
			]
		);
	}//end testAsteriskLowerCaseKeysAndEmptyPayload()

	/**
	 * Asterisk event names and their normalised type.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function asteriskEvents(): array {
		return [
			'newchannel' => ['Newchannel', 'ringing'],
			'dialbegin' => ['DialBegin', 'ringing'],
			'bridgeenter' => ['BridgeEnter', 'answered'],
			'answer' => ['Answer', 'answered'],
			'hangup' => ['Hangup', 'ended'],
			'dialend' => ['DialEnd', 'ended'],
			'channelhangup' => ['ChannelHangup', 'ended'],
			'devicestatechange' => ['DeviceStateChange', 'presence'],
			'other' => ['VarSet', 'varset'],
		];
	}//end asteriskEvents()

	/**
	 * Each Asterisk event maps to its normalised type.
	 *
	 * @param string $event    The raw event name.
	 * @param string $expected The normalised type.
	 *
	 * @return void
	 *
	 * @dataProvider asteriskEvents
	 */
	public function testAsteriskEventMap(string $event, string $expected): void {
		$this->assertSame($expected, $this->asterisk()->handleInboundWebhook(['Event' => $event])->eventType);
	}//end testAsteriskEventMap()

	/**
	 * Asterisk presence states and duration shapes.
	 *
	 * @return void
	 */
	public function testAsteriskPresenceAndDurationShapes(): void {
		$expected = [
			'NOT_INUSE' => 'available',
			'INUSE' => 'on-call',
			'BUSY' => 'on-call',
			'RINGING' => 'on-call',
			'ONHOLD' => 'wrap-up',
			'UNAVAILABLE' => 'offline',
			'WEIRD' => null,
		];
		foreach ($expected as $state => $presence) {
			$this->assertSame($presence, $this->asterisk()->handleInboundWebhook(['State' => $state])->presenceState);
		}

		$this->assertSame(17, $this->asterisk()->handleInboundWebhook(['Duration' => 17])->durationSeconds);
		$this->assertNull($this->asterisk()->handleInboundWebhook(['Duration' => 'n/a'])->durationSeconds);
		$this->assertNull($this->asterisk()->handleInboundWebhook(['Duration' => ['x']])->durationSeconds);
	}//end testAsteriskPresenceAndDurationShapes()

	/**
	 * CallVoip maps every field of a full payload.
	 *
	 * @return void
	 */
	public function testCallVoipFullPayload(): void {
		$payload = [
			'event' => 'ended',
			'callId' => 'cv-1',
			'direction' => 'outbound',
			'from' => '+3161',
			'to' => '+3162',
			'extension' => 101,
			'userId' => 'bob',
			'duration' => '327',
			'recording' => ['url' => 'https://rec/2', 'expiresAt' => '2027-01-01'],
			'presence' => 'available',
			'queue' => 'sales',
			'skill' => 'dutch',
		];
		$result = $this->callVoip()->handleInboundWebhook($payload);

		$this->assertSame(
			[
				'eventType' => 'ended',
				'externalCallId' => 'cv-1',
				'direction' => 'outbound',
				'fromNumber' => '+3161',
				'toNumber' => '+3162',
				'extension' => '101',
				'userId' => 'bob',
				'durationSeconds' => 327,
				'recordingUrl' => 'https://rec/2',
				'recordingExpiresAt' => '2027-01-01',
				'presenceState' => 'available',
				'queueName' => 'sales',
				'agentSkill' => 'dutch',
				'raw' => $payload,
			],
			$this->fields($result)
		);
	}//end testCallVoipFullPayload()

	/**
	 * CallVoip defaults an extension-targeted answer to inbound, and nothing else.
	 *
	 * @return void
	 */
	public function testCallVoipDirectionDefaults(): void {
		$this->assertSame('inbound', $this->callVoip()->handleInboundWebhook(['event' => 'answered', 'extension' => '1'])->direction);
		$this->assertNull($this->callVoip()->handleInboundWebhook(['event' => 'ringing', 'extension' => '1'])->direction);
		$this->assertNull($this->callVoip()->handleInboundWebhook(['event' => 'answered'])->direction);

		$empty = $this->callVoip()->handleInboundWebhook(['recording' => ['url' => null]]);
		$this->assertSame('unknown', $empty->eventType);
		$this->assertSame('', $empty->externalCallId);
		$this->assertNull($empty->recordingUrl);
		$this->assertNull($empty->recordingExpiresAt);
		$this->assertNull($empty->durationSeconds);
		$this->assertNull($empty->agentSkill);
	}//end testCallVoipDirectionDefaults()

	/**
	 * RingCentral maps every field of a full payload.
	 *
	 * @return void
	 */
	public function testRingCentralFullPayload(): void {
		$payload = [
			'body' => [
				'sessionId' => 'rc-s',
				'duration' => '61',
				'recording' => ['contentUri' => 'https://rec/3', 'expirationTime' => '2027-02-02'],
				'presenceStatus' => 'Busy',
				'queue' => 'billing',
				'parties' => [
					[
						'direction' => 'Outbound',
						'status' => ['code' => 'Parked'],
						'from' => ['phoneNumber' => '+3161'],
						'to' => ['phoneNumber' => '+3162'],
						'extensionId' => 777,
						'accountId' => 888,
					],
				],
			],
		];
		$result = $this->ringCentral()->handleInboundWebhook($payload);

		$this->assertSame(
			[
				'eventType' => 'transferred',
				'externalCallId' => 'rc-s',
				'direction' => 'outbound',
				'fromNumber' => '+3161',
				'toNumber' => '+3162',
				'extension' => '777',
				'userId' => '888',
				'durationSeconds' => 61,
				'recordingUrl' => 'https://rec/3',
				'recordingExpiresAt' => '2027-02-02',
				'presenceState' => 'Busy',
				'queueName' => 'billing',
				'agentSkill' => null,
				'raw' => $payload,
			],
			$this->fields($result)
		);
	}//end testRingCentralFullPayload()

	/**
	 * RingCentral status codes, direction values and a body-less payload.
	 *
	 * @return void
	 */
	public function testRingCentralStatusDirectionAndFlatPayload(): void {
		$expected = [
			'Setup' => 'ringing',
			'Proceeding' => 'ringing',
			'Answered' => 'answered',
			'Disconnected' => 'ended',
			'VoiceMail' => 'abandoned',
			'Parked' => 'transferred',
			'Hold' => 'hold',
			'' => 'unknown',
		];
		foreach ($expected as $code => $type) {
			$payload = ['body' => ['parties' => [['status' => ['code' => $code]]]]];
			$this->assertSame($type, $this->ringCentral()->handleInboundWebhook($payload)->eventType);
		}

		$flat = $this->ringCentral()->handleInboundWebhook(
			['telephonySessionId' => 't-1', 'parties' => [['direction' => 'inbound', 'from' => 'oops']]]
		);
		$this->assertSame('t-1', $flat->externalCallId);
		$this->assertSame('inbound', $flat->direction);
		$this->assertNull($flat->fromNumber);

		$sideways = $this->ringCentral()->handleInboundWebhook(['body' => ['parties' => [['direction' => 'sideways']]]]);
		$this->assertNull($sideways->direction);
		$this->assertSame('', $sideways->externalCallId);
		$this->assertNull($this->ringCentral()->handleInboundWebhook([])->extension);
	}//end testRingCentralStatusDirectionAndFlatPayload()

	/**
	 * Each adapter refuses to originate without a base URL and posts nothing.
	 *
	 * @return void
	 */
	public function testOriginateWithoutBaseUrl(): void {
		$this->client->expects($this->never())->method('post');
		$this->assertCall(
			[false, null, 'Asterisk API base URL not configured.', 'asterisk'],
			$this->asterisk()->originateCall('101', '+3161', '+3100')
		);
		$this->assertCall(
			[false, null, 'CallVoip API base URL not configured.', 'callvoip'],
			$this->callVoip()->originateCall('101', '+3161', '+3100')
		);
		$this->assertCall(
			[false, null, 'RingCentral API base URL not configured.', 'ringcentral'],
			$this->ringCentral()->originateCall('101', '+3161', '+3100')
		);
	}//end testOriginateWithoutBaseUrl()

	/**
	 * Asterisk posts to ARI with basic auth and reads the channel id.
	 *
	 * @return void
	 */
	public function testAsteriskOriginatePostsAndReadsId(): void {
		$this->config = [
			'cti_asterisk_api_base_url' => 'https://pbx/',
			'cti_asterisk_ari_user' => 'ari',
			'cti_asterisk_ari_pass' => 'pw',
		];
		$this->client->expects($this->once())->method('post')->with(
			'https://pbx/ari/channels',
			[
				'auth' => ['ari', 'pw'],
				'headers' => ['Content-Type' => 'application/json'],
				'body' => '{"endpoint":"SIP\/101","extension":"+3161","context":"from-internal","callerId":"+3100"}',
				'timeout' => 10,
			]
		)->willReturn($this->response('{"uniqueid":12.5}'));

		$this->assertCall([true, '12.5', null, 'asterisk'], $this->asterisk()->originateCall('101', '+3161', '+3100'));
	}//end testAsteriskOriginatePostsAndReadsId()

	/**
	 * Asterisk without an ARI user posts without auth; id wins over uniqueid; a non-JSON body gives no id.
	 *
	 * @return void
	 */
	public function testAsteriskOriginateWithoutUserAndBodyShapes(): void {
		$this->config = ['cti_asterisk_api_base_url' => 'https://pbx', 'cti_asterisk_context' => 'ctx'];
		$this->client->method('post')->willReturnCallback(
			function (string $url, array $options): IResponse {
				$this->assertNull($options['auth']);
				$this->assertStringContainsString('"context":"ctx"', $options['body']);
				return $this->response('{"id":"chan-1","uniqueid":"u"}');
			}
		);
		$this->assertCall([true, 'chan-1', null, 'asterisk'], $this->asterisk()->originateCall('1', '2', '3'));
	}//end testAsteriskOriginateWithoutUserAndBodyShapes()

	/**
	 * CallVoip posts with a bearer key and reads callId; a non-JSON body gives no id.
	 *
	 * @return void
	 */
	public function testCallVoipOriginatePostsAndReadsCallId(): void {
		$this->config = ['cti_callvoip_api_base_url' => 'https://cv/', 'cti_callvoip_api_key' => 'k'];
		$this->client->expects($this->exactly(2))->method('post')->with(
			'https://cv/calls',
			[
				'headers' => [
					'Authorization' => 'Bearer k',
					'Content-Type' => 'application/json',
				],
				'body' => '{"extension":"101","target":"+3161","callerId":"+3100"}',
				'timeout' => 10,
			]
		)->willReturnOnConsecutiveCalls($this->response('{"callId":"cv-9"}'), $this->response('not json'));

		$this->assertCall([true, 'cv-9', null, 'callvoip'], $this->callVoip()->originateCall('101', '+3161', '+3100'));
		$this->assertCall([true, null, null, 'callvoip'], $this->callVoip()->originateCall('101', '+3161', '+3100'));
	}//end testCallVoipOriginatePostsAndReadsCallId()

	/**
	 * RingCentral posts the ring-out request and reads its id.
	 *
	 * @return void
	 */
	public function testRingCentralOriginatePostsAndReadsId(): void {
		$this->config = ['cti_ringcentral_api_base_url' => 'https://rc', 'cti_ringcentral_access_token' => 't'];
		$this->client->expects($this->once())->method('post')->with(
			'https://rc/restapi/v1.0/account/~/extension/~/ring-out',
			[
				'headers' => [
					'Authorization' => 'Bearer t',
					'Content-Type' => 'application/json',
				],
				'body' => '{"from":{"phoneNumber":"+3100"},"to":{"phoneNumber":"+3161"},"caller":{"phoneNumber":"+3100"},"playPrompt":false}',
				'timeout' => 10,
			]
		)->willReturn($this->response('{"id":"rc-1"}'));

		$this->assertCall([true, 'rc-1', null, 'ringcentral'], $this->ringCentral()->originateCall('101', '+3161', '+3100'));
	}//end testRingCentralOriginatePostsAndReadsId()

	/**
	 * A failing post logs a warning and returns the platform's failure message.
	 *
	 * @return void
	 */
	public function testOriginateFailureIsLoggedAndReported(): void {
		$this->config = [
			'cti_asterisk_api_base_url' => 'https://pbx',
			'cti_callvoip_api_base_url' => 'https://cv',
			'cti_ringcentral_api_base_url' => 'https://rc',
		];
		$this->client->method('post')->willThrowException(new \RuntimeException('boom'));
		$warnings = [];
		$this->logger->method('warning')->willReturnCallback(
			function (string $message, array $context) use (&$warnings): void {
				$warnings[] = [$message, $context];
			}
		);

		$this->assertCall([false, null, 'Asterisk originate failed: boom', 'asterisk'], $this->asterisk()->originateCall('1', '2', '3'));
		$this->assertCall([false, null, 'CallVoip originate failed: boom', 'callvoip'], $this->callVoip()->originateCall('1', '2', '3'));
		$this->assertCall([false, null, 'RingCentral originate failed: boom', 'ringcentral'], $this->ringCentral()->originateCall('1', '2', '3'));
		$this->assertSame(
			[
				['Asterisk originate failed', ['exception' => 'boom']],
				['CallVoip originate failed', ['exception' => 'boom']],
				['RingCentral originate failed', ['exception' => 'boom']],
			],
			$warnings
		);
	}//end testOriginateFailureIsLoggedAndReported()

	/**
	 * Assert a call result's success, call id, error and platform.
	 *
	 * @param array{0:bool,1:?string,2:?string,3:string} $expected The expected values.
	 * @param CtiCallResult                              $result   The result.
	 *
	 * @return void
	 */
	private function assertCall(array $expected, CtiCallResult $result): void {
		$this->assertSame($expected, [$result->success, $result->externalCallId, $result->error, $result->platform]);
	}//end assertCall()

	/**
	 * A response stub with the given body.
	 *
	 * @param string $body The body.
	 *
	 * @return IResponse
	 */
	private function response(string $body): IResponse {
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn($body);
		return $response;
	}//end response()

	/**
	 * All fields of a webhook result, in declaration order.
	 *
	 * @param CtiWebhookResult $result The result.
	 *
	 * @return array<string,mixed>
	 */
	private function fields(CtiWebhookResult $result): array {
		return get_object_vars($result);
	}//end fields()

	/**
	 * Client service returning the shared client stub.
	 *
	 * @return IClientService
	 */
	private function clientService(): IClientService {
		$service = $this->createMock(IClientService::class);
		$service->method('newClient')->willReturn($this->client);
		return $service;
	}//end clientService()

	/**
	 * An Asterisk adapter on the shared stubs.
	 *
	 * @return AsteriskAdapter
	 */
	private function asterisk(): AsteriskAdapter {
		return new AsteriskAdapter($this->appConfig, $this->clientService(), $this->logger);
	}//end asterisk()

	/**
	 * A CallVoip adapter on the shared stubs.
	 *
	 * @return CallVoipAdapter
	 */
	private function callVoip(): CallVoipAdapter {
		return new CallVoipAdapter($this->appConfig, $this->clientService(), $this->logger);
	}//end callVoip()

	/**
	 * A RingCentral adapter on the shared stubs.
	 *
	 * @return RingCentralAdapter
	 */
	private function ringCentral(): RingCentralAdapter {
		return new RingCentralAdapter($this->appConfig, $this->clientService(), $this->logger);
	}//end ringCentral()
}//end class
