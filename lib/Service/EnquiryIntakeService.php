<?php

/**
 * Website enquiry intake.
 *
 * Turns an anonymous form submission into an `enquiry` object, and refuses
 * everything else it was handed.
 *
 * ⚠️ THIS CLASS IS THE ONLY THING STANDING BETWEEN AN ANONYMOUS VISITOR AND
 * THE CRM'S OWN STATE. OpenRegister has no per-property scoping on create, and
 * `readOnly` is documented as a no-op there ("No-op on CREATE (uuid === null)"
 * in ObjectService::enforceReadOnly). So every property the schema declares is
 * settable by anyone allowed to create the object, and `enquiry` declares
 * `authorization.create: ["public"]`.
 *
 * Posting the form straight at `/apps/openregister/api/objects/pipelinq/enquiry`
 * would therefore let a visitor set `status: "converted"` and drop their own
 * enquiry out of the sales inbox, or set `handledBy` to a real user's id. The
 * whitelist below is the refusal the schema cannot express.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/website-enquiry-intake/specs/website-enquiry-intake/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Accepts a website enquiry, or explains why it did not.
 */
class EnquiryIntakeService {

	/**
	 * The only fields a submitter may set.
	 *
	 * Everything the schema declares beyond this list is CRM state:
	 * `status`, `receivedAt`, `client`, `contact`, `lead`, `handledBy` and
	 * `handledAt`. A submitter supplying one of those is not making a mistake
	 * we should correct, they are attempting a write, so the value is dropped
	 * rather than merged.
	 *
	 * @var array<int, string>
	 */
	private const SUBMITTER_FIELDS = [
		'title',
		'contactName',
		'contactEmail',
		'contactPhone',
		'organisation',
		'message',
		'source',
		'pageUrl',
		'locale',
	];

	/**
	 * The forms allowed to submit, named by the page they sit on.
	 *
	 * A collector that accepts whatever arrives has no allowlist, it has a
	 * default. Adding a form is a one-line change here plus its test, which is
	 * cheaper than the alternative: an open `source` field is a free-text
	 * column that every report then has to guess at.
	 *
	 * @var array<int, string>
	 */
	private const ALLOWED_SOURCES = [
		// Exactly the forms conduction-website ships today, verified against
		// its source rather than guessed: `website-partner` is SINGULAR there,
		// and an allowlist holding the plural would have refused every partner
		// application while looking correct in review.
		'website-support',
		'website-partner',
		'website-contact',
	];

	/**
	 * Longest message we store, in characters.
	 *
	 * Past this the submission is refused whole rather than truncated: a
	 * silently shortened message is one the reader cannot tell is incomplete.
	 */
	private const MAX_MESSAGE_LENGTH = 20000;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig            $appConfig     App configuration, source of the register and schema ids.
	 * @param LoggerInterface       $logger        Logger. Submitted personal data MUST NOT be written here.
	 * @param ObjectServiceInterface $objectService OpenRegister's published object service.
	 *
	 * @spec exclude constructor wiring only; it makes no decision a requirement can describe
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Accept one website enquiry.
	 *
	 * @param array<string, mixed> $payload The raw submitted fields.
	 *
	 * @return string The stored enquiry's uuid, or an empty string when the
	 *                store returned no identifier. The write succeeded either
	 *                way: saveObject throws when it does not.
	 *
	 * @throws InvalidArgumentException When the submission is refused.
	 * @throws RuntimeException         When the register or schema is not configured.
	 *
	 * @spec openspec/changes/website-enquiry-intake/specs/website-enquiry-intake/spec.md#requirement-the-endpoint-decides-state-the-submitter-does-not
	 */
	public function submit(array $payload): string {
		$this->refuseHoneypot(payload: $payload);

		$fields = $this->whitelist(payload: $payload);
		$source = $this->resolveSource(submitted: ($fields['source'] ?? ''));

		$this->refuseEmpty(fields: $fields);

		$object = $fields;
		// Server-owned, every time, whatever the submitter sent. These four
		// assignments are the point of this class: see the class docblock.
		$object['source'] = $source;
		$object['status'] = 'new';
		$object['receivedAt'] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
		$object['title'] = $this->resolveTitle(fields: $fields);

		return $this->store(object: $object);
	}//end submit()

	/**
	 * Refuse a submission whose honeypot field was filled.
	 *
	 * The field is hidden from humans, so a non-empty value means the caller
	 * filled in every input it found, which no person does.
	 *
	 * @param array<string, mixed> $payload The raw submitted fields.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the honeypot carries a value.
	 *
	 * @spec openspec/changes/website-enquiry-intake/specs/website-enquiry-intake/spec.md#requirement-a-filled-honeypot-is-refused
	 */
	private function refuseHoneypot(array $payload): void {
		$raw = ($payload['website'] ?? '');
		// A non-scalar honeypot (`website[]=x`) is not "empty", it is a caller
		// sending a shape no form produces. Refused rather than cast, because
		// casting an array to string is a PHP error.
		if (is_scalar($raw) === true && trim((string)$raw) === '') {
			return;
		}

		$this->logger->info('Pipelinq: website enquiry refused, honeypot filled.');
		throw new InvalidArgumentException('This submission could not be accepted.');
	}//end refuseHoneypot()

	/**
	 * Keep only the fields a submitter is allowed to set.
	 *
	 * Absent and blank values are dropped rather than stored as empty strings,
	 * so "the visitor left this blank" and "the visitor typed a space" do not
	 * become two different-looking rows.
	 *
	 * @param array<string, mixed> $payload The raw submitted fields.
	 *
	 * @return array<string, string> The fields that survived.
	 *
	 * @spec openspec/changes/website-enquiry-intake/specs/website-enquiry-intake/spec.md#requirement-the-endpoint-decides-state-the-submitter-does-not
	 */
	private function whitelist(array $payload): array {
		$fields = [];
		foreach (self::SUBMITTER_FIELDS as $name) {
			$raw = ($payload[$name] ?? '');
			// `title[]=a&title[]=b` arrives as an array, and casting one to
			// string is a PHP error, not a value. The caller here is anonymous
			// and can send any shape it likes, so a non-scalar is treated as
			// absent rather than allowed to reach a cast.
			if (is_scalar($raw) === false) {
				continue;
			}

			$value = trim((string)$raw);
			if ($value === '') {
				continue;
			}

			$fields[$name] = $value;
		}

		return $fields;
	}//end whitelist()

	/**
	 * Resolve the submitted source against the allowlist.
	 *
	 * @param string $submitted The `source` the caller sent.
	 *
	 * @return string The accepted source.
	 *
	 * @throws InvalidArgumentException When the source is absent or unknown.
	 *
	 * @spec openspec/changes/website-enquiry-intake/specs/website-enquiry-intake/spec.md#requirement-the-source-is-refused-unless-it-is-on-the-allowlist
	 */
	private function resolveSource(string $submitted): string {
		if (in_array($submitted, self::ALLOWED_SOURCES, true) === true) {
			return $submitted;
		}

		// The refused value is logged because an unknown source is usually a
		// website deploy that added a form without adding it here, and that is
		// only diagnosable if the name it tried to use is recorded. It is a
		// form identifier, not personal data.
		$this->logger->warning(
			'Pipelinq: website enquiry refused, unknown source.',
			['source' => $submitted]
		);
		throw new InvalidArgumentException('This submission could not be accepted.');
	}//end resolveSource()

	/**
	 * Refuse a submission that gives nobody anything to act on.
	 *
	 * @param array<string, string> $fields The whitelisted fields.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When there is no message and no email address.
	 *
	 * @spec openspec/changes/website-enquiry-intake/specs/website-enquiry-intake/spec.md#requirement-an-empty-submission-is-refused
	 */
	private function refuseEmpty(array $fields): void {
		if (isset($fields['message']) === true || isset($fields['contactEmail']) === true) {
			if (mb_strlen((string)($fields['message'] ?? '')) > self::MAX_MESSAGE_LENGTH) {
				throw new InvalidArgumentException('This submission could not be accepted.');
			}

			return;
		}

		throw new InvalidArgumentException('This submission could not be accepted.');
	}//end refuseEmpty()

	/**
	 * The title the sales inbox shows.
	 *
	 * Falls back through the identifying fields rather than storing a blank,
	 * because `title` is the schema's one required property and an untitled row
	 * is unreadable in a list.
	 *
	 * @param array<string, string> $fields The whitelisted fields.
	 *
	 * @return string A non-empty title.
	 *
	 * @spec openspec/changes/website-enquiry-intake/specs/website-enquiry-intake/spec.md#requirement-the-enquiry-schema-holds-intake-data-not-deal-data
	 */
	private function resolveTitle(array $fields): string {
		$candidates = [
			($fields['title'] ?? ''),
			($fields['organisation'] ?? ''),
			($fields['contactName'] ?? ''),
			($fields['contactEmail'] ?? ''),
		];
		foreach ($candidates as $candidate) {
			if (trim($candidate) !== '') {
				return mb_substr(trim($candidate), 0, 255);
			}
		}

		return 'Website enquiry';
	}//end resolveTitle()

	/**
	 * Write the enquiry to OpenRegister.
	 *
	 * @param array<string, mixed> $object The object to store.
	 *
	 * @return string The stored object's uuid, or an empty string when it has none.
	 *
	 * @throws RuntimeException When the register or schema is not configured.
	 *
	 * @spec openspec/changes/website-enquiry-intake/specs/website-enquiry-intake/spec.md#requirement-the-intake-endpoint-accepts-an-anonymous-submission
	 */
	private function store(array $object): string {
		$registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schemaId = $this->appConfig->getValueString(Application::APP_ID, 'enquiry_schema', '');
		if ($registerId === '' || $schemaId === '') {
			// Not the submitter's fault, and not something they can be told
			// about: the caller turns this into a 503.
			throw new RuntimeException('Pipelinq: the enquiry register or schema is not configured.');
		}

		$saved = $this->objectService->saveObject(
			$object,
			[],
			$registerId,
			$schemaId,
			null
		);

		return (string)($saved->getUuid() ?? '');
	}//end store()
}//end class
