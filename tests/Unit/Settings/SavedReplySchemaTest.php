<?php

/**
 * The savedReply schema and the resend payloads against the shipped fragments.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Settings
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.pipelinq.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/messaging-saved-replies-and-resend/specs/messaging-saved-replies/spec.md#requirement-the-team-keeps-saved-replies-req-msr-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Settings;

use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * `savedReply` in register.d/81-saved-replies.json, and the channelMessage rows
 * Send again writes, in register.d/80-whatsapp-sms-channel.json.
 *
 * @coversNothing
 */
class SavedReplySchemaTest extends TestCase {

	/**
	 * One fragment, decoded.
	 *
	 * @param string $file The fragment file name.
	 *
	 * @return array<string, mixed>
	 */
	private function fragment(string $file): array {
		$fragment = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/lib/Settings/register.d/' . $file), true);
		$this->assertIsArray($fragment, $file . ' must be valid JSON');
		return $fragment;
	}//end fragment()

	/**
	 * The fragment adds savedReply to the pipelinq register, with the five fields.
	 *
	 * @return void
	 */
	public function testTheRegisterCarriesSavedReplies(): void {
		$fragment = $this->fragment(file: '81-saved-replies.json');

		$this->assertContains('savedReply', $fragment['components']['registers']['pipelinq']['schemas']);
		$schema = $fragment['components']['schemas']['savedReply'];
		$this->assertSame('savedReply', $schema['slug']);
		$this->assertSame(['title', 'body', 'channels', 'language', 'active'], array_keys($schema['properties']));
		$this->assertSame(['sms', 'whatsapp', 'email', 'portal'], $schema['properties']['channels']['items']['enum']);
		$this->assertSame(['title', 'body'], $schema['required']);
	}//end testTheRegisterCarriesSavedReplies()

	/**
	 * The reply a team lead adds in the spec scenario validates.
	 *
	 * @return void
	 */
	public function testTheOpeningHoursReplyValidates(): void {
		$schema = $this->fragment(file: '81-saved-replies.json')['components']['schemas']['savedReply'];
		$payload = [
			'title' => 'Opening hours',
			'body' => 'Dear {{contact.name}}, we are open until five.',
			'channels' => ['sms', 'email'],
			'language' => 'en',
			'active' => true,
		];

		$this->assertValid(schema: $schema, payload: $payload);
	}//end testTheOpeningHoursReplyValidates()

	/**
	 * The failed row Send again links, and the failed template row, validate.
	 *
	 * @return void
	 */
	public function testTheResendRowsValidateAgainstChannelMessage(): void {
		$schema = $this->fragment(file: '80-whatsapp-sms-channel.json')['components']['schemas']['channelMessage'];
		$failed = [
			'contactId' => 'contact-1',
			'conversationId' => 'conv-1',
			'channel' => 'whatsapp',
			'direction' => 'outbound',
			'body' => '[template:afspraak_nl]',
			'providerId' => 'prov-1',
			'templateId' => 'tpl-9',
			'templateParameters' => ['Jan', 'vrijdag'],
			'deliveryStatus' => 'failed',
			'sentAt' => '2026-10-09T10:00:00Z',
			'metadata' => ['error' => 'rejected', 'resentAs' => 'msg-2'],
		];

		$this->assertValid(schema: $schema, payload: $failed);
	}//end testTheResendRowsValidateAgainstChannelMessage()

	/**
	 * Validate a payload against the standard keywords of a schema.
	 *
	 * @param array<string, mixed> $schema The schema.
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return void
	 */
	private function assertValid(array $schema, array $payload): void {
		$properties = [];
		foreach (array_keys($payload) as $key) {
			$this->assertArrayHasKey($key, $schema['properties'], "'{$key}' must be declared or storage drops it");
			$properties[$key] = array_intersect_key($schema['properties'][$key], array_flip(['type', 'enum', 'items', 'maxLength']));
		}

		$jsonSchema = json_decode((string)json_encode(['type' => 'object', 'required' => ($schema['required'] ?? []), 'properties' => $properties]));
		$result = (new Validator())->validate(json_decode((string)json_encode($payload)), $jsonSchema);

		$this->assertTrue($result->isValid(), (string)json_encode($result->error()?->args()));
	}//end assertValid()
}//end class
