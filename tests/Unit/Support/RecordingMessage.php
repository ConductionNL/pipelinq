<?php

/**
 * An IMessage that records what was set, including headers through the
 * getSymfonyEmail() path OpenRegister's UnsubscribeHeaders helper uses.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Support;

use OCP\Mail\IAttachment;
use OCP\Mail\IMessage;

/**
 * Records the envelope, body and headers.
 */
class RecordingMessage implements IMessage {

	/** @var array<int|string, string> */
	public array $to = [];

	public string $subject = '';

	public string $plain = '';

	public string $html = '';

	/** @var array<string, string> */
	public array $headers = [];

	public function setFrom(array $addresses): IMessage {
		return $this;
	}

	public function setTo(array $recipients): IMessage {
		$this->to = $recipients;
		return $this;
	}

	public function setCc(array $recipients): IMessage {
		return $this;
	}

	public function setBcc(array $recipients): IMessage {
		return $this;
	}

	public function setReplyTo(array $addresses): IMessage {
		return $this;
	}

	public function setSubject(string $subject): IMessage {
		$this->subject = $subject;
		return $this;
	}

	public function setPlainBody(string $body): IMessage {
		$this->plain = $body;
		return $this;
	}

	public function setHtmlBody(string $body): IMessage {
		$this->html = $body;
		return $this;
	}

	public function attach(IAttachment $attachment): IMessage {
		return $this;
	}

	public function attachInline(string $body, string $name, ?string $contentType = null): IMessage {
		return $this;
	}

	public function useTemplate(\OCP\Mail\IEMailTemplate $emailTemplate): IMessage {
		return $this;
	}

	public function setAutoSubmitted(string $value): IMessage {
		return $this;
	}

	/**
	 * The private Nextcloud path, recorded.
	 *
	 * @return object An email whose headers are recorded here.
	 */
	public function getSymfonyEmail(): object {
		$owner = $this;
		$headers = new class($owner) {
			public function __construct(private RecordingMessage $owner) {
			}

			public function has(string $name): bool {
				return isset($this->owner->headers[$name]);
			}

			public function remove(string $name): void {
				unset($this->owner->headers[$name]);
			}

			public function addTextHeader(string $name, string $value): void {
				$this->owner->headers[$name] = $value;
			}
		};
		return new class($headers) {
			public function __construct(private object $headers) {
			}

			public function getHeaders(): object {
				return $this->headers;
			}
		};
	}
}//end class
