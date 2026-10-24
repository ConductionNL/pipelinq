<?php

/**
 * A website form submit creates the object its form names, in the request.
 *
 * Decision 179 (Ruben, 10 October 2026): "We dont intake to an intake, we
 * intake into a case, or ticket or something else." Decision 181, Q4: each
 * published form names its own destination (a ticket, a lead, a contact),
 * with no fixed default. This replaces EnquiryIntakeService, which wrote
 * every submit into an `enquiry` holding object for a person to convert.
 *
 * What stays from it, because it is what stands between an anonymous visitor
 * and the CRM's own state:
 * - a filled honeypot stores nothing;
 * - a key that names no published form is refused, which is the allowlist
 *   (adding a form is now an admin publishing one, checked against its
 *   destination, instead of a code change);
 * - an empty or oversized submit is refused, with one message for every
 *   reason, so a caller cannot probe which check failed;
 * - only the fields the form maps reach the object (OpenRegister's
 *   FormSubmitService maps; the visitor does not choose properties), and the
 *   form's fixed values (channel, status) are set whatever the visitor sent.
 *
 * The write runs as pipelinq's disabled system account. A destination such as
 * `ticket` grants create to signed-in users only, and opening it to anonymous
 * creates would let anyone post straight at OpenRegister's objects endpoint
 * and set any property. Here the form's mapping is the list of what may be
 * set, and OpenRegister's full validator and RBAC still judge the object.
 *
 * A refused payload answers 422 with OpenRegister's findings; a destination
 * that is down, missing or refusing the account answers 503 "try again later"
 * with nothing stored (Q3). There is no queue.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\WebForm
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/website-forms-submit-into-tickets/specs/website-enquiry-intake/spec.md#requirement-a-website-form-submits-into-the-destination-it-names
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\WebForm;

use OCA\Pipelinq\Service\SystemServiceAccount;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Submits a website or Nextcloud Forms answer into its form's destination.
 *
 * @spec openspec/changes/website-forms-submit-into-tickets/specs/website-enquiry-intake/spec.md#requirement-a-website-form-submits-into-the-destination-it-names
 */
class WebFormIntakeService {

	/**
	 * The one message every refusal of the visitor's submit carries.
	 *
	 * @var string
	 */
	public const REFUSED = 'This submission could not be accepted.';

	/**
	 * What a visitor reads when the destination cannot take the submit now.
	 *
	 * @var string
	 */
	public const TRY_LATER = 'The form could not be sent right now. Please try again later.';

	/**
	 * Honeypot fields: hidden from people, so any value means a robot filled every input.
	 *
	 * `website` is the field conduction-website ships; `_hp` is OpenRegister's.
	 *
	 * @var array<int, string>
	 */
	private const HONEYPOTS = ['website', '_hp'];

	/**
	 * Longest answer kept, in characters. Longer is refused whole, never cut.
	 *
	 * @var int
	 */
	private const MAX_VALUE_LENGTH = 20000;

	/**
	 * OpenRegister's submit service, resolved by name: pipelinq boots without OpenRegister.
	 *
	 * @var string
	 */
	private const SUBMIT_SERVICE = 'OCA\OpenRegister\Service\Form\FormSubmitService';

	/**
	 * Constructor.
	 *
	 * @param WebFormFinder        $finder    Finds the published form for a key.
	 * @param ContainerInterface   $container Reaches OpenRegister's FormSubmitService.
	 * @param SystemServiceAccount $account   The account every submit is written as.
	 * @param LoggerInterface      $logger    Logger. Submitted personal data MUST NOT be written here.
	 *
	 * @spec exclude constructor wiring only; it makes no decision a requirement can describe
	 */
	public function __construct(
		private readonly WebFormFinder $finder,
		private readonly ContainerInterface $container,
		private readonly SystemServiceAccount $account,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Submit a website visitor's answers into the destination of the form the key names.
	 *
	 * @param string               $key            The form's key (the website's `source`).
	 * @param array<string, mixed> $payload        The raw submitted fields.
	 * @param string|null          $idempotencyKey The Idempotency-Key, when the caller sent one.
	 *
	 * @return array<string, mixed> The answer: reference, id, receivedAt, confirmation.
	 *
	 * @throws WebFormSubmitException 400 refused, 422 payload refused, 503 try again later.
	 *
	 * @spec openspec/changes/website-forms-submit-into-tickets/specs/website-enquiry-intake/spec.md#requirement-a-website-form-submits-into-the-destination-it-names
	 */
	public function submitByKey(string $key, array $payload, ?string $idempotencyKey = null): array {
		$this->refuseHoneypot(payload: $payload);

		try {
			$form = $this->finder->publishedByKey(key: $key);
		} catch (Throwable $e) {
			$this->logger->error('Pipelinq: website forms cannot be read.', ['exception' => $e]);
			throw new WebFormSubmitException(message: self::TRY_LATER, status: 503, previous: $e);
		}

		if ($form === null) {
			// The key is a form identifier, not personal data: a website that
			// added a form nobody published is only diagnosable from this line.
			$this->logger->warning('Pipelinq: website form submit refused, no published form for this key.', ['key' => $key]);
			throw new WebFormSubmitException(message: self::REFUSED, status: 400);
		}

		return $this->submit(form: $form, payload: $payload, idempotencyKey: $idempotencyKey);

	}//end submitByKey()

	/**
	 * Submit answers into the destination of a form already resolved (a Nextcloud Forms binding, the drain).
	 *
	 * @param array<string, mixed> $form           The published form body with its `id`.
	 * @param array<string, mixed> $payload        Field to answer.
	 * @param string|null          $idempotencyKey A key that makes a repeat answer the first time's.
	 *
	 * @return array<string, mixed> The answer: reference, id, receivedAt, confirmation.
	 *
	 * @throws WebFormSubmitException 400 refused, 422 payload refused, 503 try again later.
	 *
	 * @spec openspec/changes/website-forms-submit-into-tickets/specs/website-enquiry-intake/spec.md#requirement-a-website-form-submits-into-the-destination-it-names
	 */
	public function submit(array $form, array $payload, ?string $idempotencyKey = null): array {
		$writes = $this->finder->writesOf(form: $form);
		if ($writes === []) {
			// Publishing is refused without a destination, so this is a form
			// changed behind the check: the site owner's problem, not the visitor's.
			$this->logger->error('Pipelinq: published website form has no destination.', ['form' => ($form['id'] ?? '')]);
			throw new WebFormSubmitException(message: self::TRY_LATER, status: 503);
		}

		$answers = $this->withFallbacks(answers: $this->clean(payload: $payload), form: $form);
		$this->refuseEmpty(answers: $answers, form: $form, writes: $writes);

		try {
			$answer = $this->container->get(self::SUBMIT_SERVICE)->submitAll(
				writes: $writes,
				payload: $answers,
				subject: $this->account->account(),
				idempotencyKey: $idempotencyKey,
				scope: 'pipelinq-web-form:' . (string)($form['id'] ?? ''),
			);
		} catch (Throwable $e) {
			throw $this->refusalFor(exception: $e, form: $form);
		}

		return [
			'reference' => (string)($answer['reference'] ?? ''),
			'id' => (string)($answer['id'] ?? ''),
			'receivedAt' => (string)($answer['receivedAt'] ?? ''),
			'confirmation' => (array)($answer['confirmation'] ?? []),
		];

	}//end submit()

	/**
	 * Refuse a submit whose honeypot carries a value.
	 *
	 * @param array<string, mixed> $payload The raw submitted fields.
	 *
	 * @return void
	 *
	 * @throws WebFormSubmitException 400 when a honeypot is filled.
	 */
	private function refuseHoneypot(array $payload): void {
		foreach (self::HONEYPOTS as $name) {
			$raw = ($payload[$name] ?? '');
			// A non-scalar honeypot (`website[]=x`) is a shape no form produces.
			if (is_scalar($raw) === false || trim((string)$raw) !== '') {
				$this->logger->info('Pipelinq: website form submit refused, honeypot filled.');
				throw new WebFormSubmitException(message: self::REFUSED, status: 400);
			}
		}

	}//end refuseHoneypot()

	/**
	 * The answers as values a schema can hold: trimmed scalars and lists of them, blanks dropped.
	 *
	 * `title[]=a&title[]=b` arrives as a list where a form sends text; casting
	 * it is an error, not a value. A list is kept only where every item is
	 * scalar, so a multiple-choice answer survives and a nested shape does not.
	 *
	 * @param array<string, mixed> $payload The raw submitted fields.
	 *
	 * @return array<string, mixed> The answers.
	 *
	 * @throws WebFormSubmitException 400 for an answer longer than the limit.
	 */
	private function clean(array $payload): array {
		$answers = [];
		foreach ($payload as $name => $raw) {
			$value = $this->cleanValue(raw: $raw);
			if ($value !== null) {
				$answers[(string)$name] = $value;
			}
		}

		return $answers;

	}//end clean()

	/**
	 * One answer cleaned, or null when it is blank or a shape no form sends.
	 *
	 * @param mixed $raw The raw value.
	 *
	 * @return mixed The value, or null.
	 *
	 * @throws WebFormSubmitException 400 for an answer longer than the limit.
	 */
	private function cleanValue(mixed $raw): mixed {
		if (is_array($raw) === true) {
			return $this->cleanList(raw: $raw);
		}

		if (is_string($raw) === false) {
			if (is_int($raw) === true || is_float($raw) === true || is_bool($raw) === true) {
				return $raw;
			}

			return null;
		}

		$value = trim($raw);
		if ($value === '') {
			return null;
		}

		if (mb_strlen($value) > self::MAX_VALUE_LENGTH) {
			throw new WebFormSubmitException(message: self::REFUSED, status: 400);
		}

		return $value;

	}//end cleanValue()

	/**
	 * A list answer cleaned: kept only when every item is a plain value, blanks dropped.
	 *
	 * @param array<mixed> $raw The raw list.
	 *
	 * @return array<int, mixed>|null The items, or null when the list is empty or nested.
	 *
	 * @throws WebFormSubmitException 400 for an item longer than the limit.
	 */
	private function cleanList(array $raw): ?array {
		$items = [];
		foreach ($raw as $item) {
			if (is_scalar($item) === false) {
				return null;
			}

			$value = $this->cleanValue(raw: $item);
			if ($value !== null) {
				$items[] = $value;
			}
		}

		if ($items === []) {
			return null;
		}

		return $items;

	}//end cleanList()

	/**
	 * Fill a blank field from the first filled field its form lists as a fallback.
	 *
	 * @param array<string, mixed> $answers The cleaned answers.
	 * @param array<string, mixed> $form    The form.
	 *
	 * @return array<string, mixed> The answers with fallbacks applied.
	 */
	private function withFallbacks(array $answers, array $form): array {
		foreach ((array)($form['fieldFallbacks'] ?? []) as $field => $candidates) {
			if (array_key_exists((string)$field, $answers) === true) {
				continue;
			}

			foreach ((array)$candidates as $candidate) {
				if (is_scalar($answers[(string)$candidate] ?? null) === true) {
					$answers[(string)$field] = mb_substr((string)$answers[(string)$candidate], 0, 255);
					break;
				}
			}
		}

		return $answers;

	}//end withFallbacks()

	/**
	 * Refuse a submit that gives nobody anything to act on.
	 *
	 * With `requireAnyOf` the form names the fields of which one must be
	 * filled (a message or an email address). Without it, at least one mapped
	 * field must carry an answer.
	 *
	 * @param array<string, mixed>             $answers The answers.
	 * @param array<string, mixed>             $form    The form.
	 * @param array<int, array<string, mixed>> $writes  The form's writes.
	 *
	 * @return void
	 *
	 * @throws WebFormSubmitException 400 when nothing to act on was sent.
	 */
	private function refuseEmpty(array $answers, array $form, array $writes): void {
		$wanted = array_map('strval', (array)($form['requireAnyOf'] ?? []));
		if ($wanted === []) {
			foreach ($writes as $write) {
				foreach ((array)($write['mapping']['fields'] ?? []) as $field) {
					$wanted[] = (string)($field['field'] ?? '');
				}
			}
		}

		foreach ($wanted as $field) {
			if (array_key_exists($field, $answers) === true) {
				return;
			}
		}

		throw new WebFormSubmitException(message: self::REFUSED, status: 400);

	}//end refuseEmpty()

	/**
	 * The refusal a failed submit becomes.
	 *
	 * OpenRegister's refusal carries a status: 422 is the visitor's to fix and
	 * keeps its findings; 403 and 404 are a form pointing somewhere it may not
	 * write, and 503 an outage, which all read "try again later" to a visitor.
	 *
	 * @param Throwable            $exception What the submit threw.
	 * @param array<string, mixed> $form      The form, for the log.
	 *
	 * @return WebFormSubmitException The refusal.
	 */
	private function refusalFor(Throwable $exception, array $form): WebFormSubmitException {
		$status = 0;
		if (method_exists($exception, 'getStatus') === true) {
			$status = (int)$exception->getStatus();
		}

		if ($status === 422) {
			$findings = [];
			if (method_exists($exception, 'getFindings') === true) {
				$findings = (array)$exception->getFindings();
			}

			return new WebFormSubmitException(message: $exception->getMessage(), status: 422, findings: $findings, previous: $exception);
		}

		$this->logger->error(
			'Pipelinq: website form submit did not reach its destination.',
			['form' => ($form['id'] ?? ''), 'status' => $status, 'exception' => $exception]
		);

		return new WebFormSubmitException(message: self::TRY_LATER, status: 503, previous: $exception);

	}//end refusalFor()
}//end class
