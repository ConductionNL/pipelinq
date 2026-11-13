<?php

/**
 * Unit tests for the decision logic on the messaging send value objects.
 *
 * Pins the failover log line each provider attempt picks per exception
 * type, the stored outbound body of a WhatsApp send (template marker vs
 * free-form body) and when a template request counts as a template send.
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
 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Provider;

use OCA\Pipelinq\Service\Provider\PermanentSmsProviderException;
use OCA\Pipelinq\Service\Provider\SmsMessage;
use OCA\Pipelinq\Service\Provider\SmsProviderAttempt;
use OCA\Pipelinq\Service\Provider\SmsProviderClientInterface;
use OCA\Pipelinq\Service\Provider\SmsSendRun;
use OCA\Pipelinq\Service\Provider\TransientSmsProviderException;
use OCA\Pipelinq\Service\Provider\WhatsAppMessage;
use OCA\Pipelinq\Service\Provider\WhatsAppProviderAttempt;
use OCA\Pipelinq\Service\Provider\WhatsAppSendRun;
use OCA\Pipelinq\Service\Provider\WhatsAppTemplateRequest;
use OCA\Pipelinq\Service\Provider\WhatsAppTemplateSend;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Failover log lines, outbound bodies and template detection.
 *
 * @covers \OCA\Pipelinq\Service\Provider\SmsProviderAttempt
 * @covers \OCA\Pipelinq\Service\Provider\WhatsAppProviderAttempt
 * @covers \OCA\Pipelinq\Service\Provider\WhatsAppSendRun
 * @covers \OCA\Pipelinq\Service\Provider\WhatsAppTemplateRequest
 * @uses   \OCA\Pipelinq\Service\Provider\SmsMessage
 * @uses   \OCA\Pipelinq\Service\Provider\SmsSendRun
 * @uses   \OCA\Pipelinq\Service\Provider\WhatsAppMessage
 * @uses   \OCA\Pipelinq\Service\Provider\WhatsAppTemplateSend
 * @uses   \OCA\Pipelinq\Service\Provider\PermanentSmsProviderException
 * @uses   \OCA\Pipelinq\Service\Provider\TransientSmsProviderException
 */
class ProviderAttemptLogicTest extends TestCase {

	/**
	 * The SMS attempt names the failover outcome by exception type.
	 *
	 * @return void
	 */
	public function testSmsAttemptFailureLogPerExceptionType(): void {
		$attempt = new SmsProviderAttempt(
			run: new SmsSendRun(
				message: new SmsMessage(contactId: 'c-1', toNumber: '+31611111111', body: 'hi', context: []),
				tenantId: 't-1',
				allowFailover: true,
			),
			providerId: 'p-1',
			client: $this->createMock(SmsProviderClientInterface::class),
		);

		self::assertSame(
			'SmsAdapter.send: transient — failing over',
			$attempt->failureLog(new TransientSmsProviderException('503'))
		);
		self::assertSame(
			'SmsAdapter.send: permanent provider failure',
			$attempt->failureLog(new PermanentSmsProviderException('401'))
		);
		self::assertSame(
			'SmsAdapter.send: unexpected exception — failing over',
			$attempt->failureLog(new RuntimeException('boom'))
		);
	}//end testSmsAttemptFailureLogPerExceptionType()

	/**
	 * The WhatsApp attempt names the failover outcome by exception type.
	 *
	 * @return void
	 */
	public function testWhatsAppAttemptFailureLogPerExceptionType(): void {
		$attempt = new WhatsAppProviderAttempt(
			run: $this->whatsAppRun(templateName: '', body: 'hi'),
			row: ['id' => 'p-2'],
			providerId: 'p-2',
		);

		self::assertSame(
			'WhatsAppAdapter.send: transient — failing over',
			$attempt->failureLog(new TransientSmsProviderException('503'))
		);
		self::assertSame(
			'WhatsAppAdapter.send: permanent provider failure',
			$attempt->failureLog(new PermanentSmsProviderException('401'))
		);
		self::assertSame(
			'WhatsAppAdapter.send: unexpected — failing over',
			$attempt->failureLog(new \TypeError('bad'))
		);
	}//end testWhatsAppAttemptFailureLogPerExceptionType()

	/**
	 * A template send stores a `[template:NAME]` marker instead of the body.
	 *
	 * @return void
	 */
	public function testOutboundBodyIsTemplateMarkerForTemplateSend(): void {
		self::assertSame(
			'[template:order_confirmed]',
			$this->whatsAppRun(templateName: 'order_confirmed', body: 'ignored')->outboundBody()
		);
	}//end testOutboundBodyIsTemplateMarkerForTemplateSend()

	/**
	 * A free-form send stores the message body unchanged, even when empty.
	 *
	 * @return void
	 */
	public function testOutboundBodyIsMessageBodyForFreeFormSend(): void {
		self::assertSame('Hallo daar', $this->whatsAppRun(templateName: '', body: 'Hallo daar')->outboundBody());
		self::assertSame('', $this->whatsAppRun(templateName: '', body: '')->outboundBody());
	}//end testOutboundBodyIsMessageBodyForFreeFormSend()

	/**
	 * Only a non-empty template id makes a template send.
	 *
	 * @return void
	 */
	public function testIsTemplateSendOnlyForNonEmptyTemplateId(): void {
		self::assertTrue((new WhatsAppTemplateRequest(templateId: 'tpl-1', parameters: [], language: 'nl'))->isTemplateSend());
		self::assertTrue((new WhatsAppTemplateRequest(templateId: '0', parameters: [], language: 'nl'))->isTemplateSend());
		self::assertFalse((new WhatsAppTemplateRequest(templateId: '', parameters: ['a'], language: 'nl'))->isTemplateSend());
		self::assertFalse((new WhatsAppTemplateRequest(templateId: null, parameters: ['a'], language: 'nl'))->isTemplateSend());
	}//end testIsTemplateSendOnlyForNonEmptyTemplateId()

	/**
	 * Build a WhatsApp send run.
	 *
	 * @param string $templateName Template name, empty for free-form.
	 * @param string $body         Free-form body.
	 *
	 * @return WhatsAppSendRun
	 */
	private function whatsAppRun(string $templateName, string $body): WhatsAppSendRun {
		return new WhatsAppSendRun(
			message: new WhatsAppMessage(contactId: 'c-1', toNumber: '+31611111111', body: $body, context: []),
			templateSend: new WhatsAppTemplateSend(templateName: $templateName, language: 'nl', parameters: [], template: null),
			tenantId: 't-1',
		);
	}//end whatsAppRun()
}//end class
