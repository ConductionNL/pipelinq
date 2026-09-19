<?php

/**
 * Pipelinq ProvisionContactIdentityNode.
 *
 * The `pipelinq.provision-contact-identity` node type. It resolves or creates
 * the Nextcloud addressbook contact that a `client` or `contact` object must
 * point at, and puts the resulting UID on the item so a following
 * `openregister.object-write` can save an object that validates.
 *
 * 🔴 WHY THIS NODE HAS TO EXIST AT ALL, rather than the flow writing the
 * client directly. Both schemas mark `contactsUid` REQUIRED, and the property's
 * own description is explicit: "Resolved/created via ContactVcardService; never
 * minted locally." The addressbook contact is the authoritative identity and
 * the object is the mirror. A flow built only from generic object-write nodes
 * therefore CANNOT create a client: measured on a local rig, the write failed
 * with "The required property (contactsUid) is missing" every time. Minting a
 * synthetic uid in the flow would satisfy the validator and break the identity
 * model that ContactImportService and the vCard sync both depend on, which is
 * the worse outcome precisely because it would look like it worked.
 *
 * 🔴 IT RESOLVES BEFORE IT CREATES, and that is the whole value. The service
 * matches an existing contact on email first, then on ORG for an organisation,
 * and only writes a fresh vCard when nothing matches. So converting two
 * enquiries from the same company does not leave two identities behind, and a
 * company already in the addressbook keeps the one it has.
 *
 * 🔴 THROWING IS MEANINGFUL. The engine reads the step's `onError` policy from
 * what this throws. Returning the items unchanged when provisioning failed
 * would hand the next step an item with no uid, and the object-write would
 * then fail with a validation error that names `contactsUid` rather than the
 * step that could not reach Contacts. Same reasoning as JourneyActionNode.
 *
 * @category Flow
 * @package  OCA\Pipelinq\Flow
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

namespace OCA\Pipelinq\Flow;

use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys;
use OCA\Pipelinq\Service\ContactVcardService;
use OCP\IURLGenerator;
use RuntimeException;
use UnexpectedValueException;

/**
 * Resolves the addressbook identity a client or contact object needs.
 */
class ProvisionContactIdentityNode implements IFlowNode, IFlowNodeConfigKeys {

	/**
	 * The node type identifier.
	 */
	private const NODE_ID = 'pipelinq.provision-contact-identity';

	/**
	 * The object types ContactVcardService will provision for.
	 *
	 * @var array<int, string>
	 */
	private const OBJECT_TYPES = ['client', 'contact'];

	/**
	 * Constructor.
	 *
	 * @param ContactVcardService $vcards The addressbook identity resolver.
	 * @param IURLGenerator       $urls   Builds the palette icon URL.
	 */
	public function __construct(
		private readonly ContactVcardService $vcards,
		private readonly IURLGenerator $urls,
	) {
	}//end __construct()

	/**
	 * The type identifier.
	 *
	 * @return string The type identifier.
	 *
	 * @spec openspec/changes/website-enquiry-intake/specs/website-enquiry-intake/spec.md
	 */
	public function getId(): string {
		return self::NODE_ID;
	}//end getId()

	/**
	 * Name shown in the flow builder's palette.
	 *
	 * @return string The display name.
	 *
	 * @spec openspec/changes/website-enquiry-intake/specs/website-enquiry-intake/spec.md
	 */
	public function getDisplayName(): string {
		return 'Provision contact identity';
	}//end getDisplayName()

	/**
	 * What the step does, in the builder.
	 *
	 * @return string The description.
	 *
	 * @spec openspec/changes/website-enquiry-intake/specs/website-enquiry-intake/spec.md
	 */
	public function getDescription(): string {
		return 'Find or create the addressbook contact a client or contact object must point at, '
			. 'and put its UID on the item.';
	}//end getDescription()

	/**
	 * The palette icon.
	 *
	 * @return string The icon URL.
	 *
	 * @spec openspec/changes/website-enquiry-intake/specs/website-enquiry-intake/spec.md
	 */
	public function getIcon(): string {
		return $this->urls->getAbsoluteURL($this->urls->imagePath('pipelinq', 'app.svg'));
	}//end getIcon()

	/**
	 * Available in every scope: an addressbook identity is tenant-local either way.
	 *
	 * @param int $scope The scope constant.
	 *
	 * @return bool Whether it is available.
	 *
	 * @spec openspec/changes/website-enquiry-intake/specs/website-enquiry-intake/spec.md
	 */
	public function isAvailableForScope(int $scope): bool {
		unset($scope);

		return true;
	}//end isAvailableForScope()

	/**
	 * The configuration keys this node reads.
	 *
	 * @return array<int, string> The accepted keys.
	 *
	 * @spec openspec/changes/website-enquiry-intake/specs/website-enquiry-intake/spec.md
	 */
	public function configKeys(): array {
		return ['objectType', 'nameFrom', 'emailFrom', 'phoneFrom', 'output'];
	}//end configKeys()

	/**
	 * Refuse a configuration this node cannot act on.
	 *
	 * @param array<string, mixed> $config The step's authored configuration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the object type or output is missing or unknown.
	 *
	 * @spec openspec/changes/website-enquiry-intake/specs/website-enquiry-intake/spec.md
	 */
	public function validateConfig(array $config): void {
		$type = trim((string)($config['objectType'] ?? ''));
		if (in_array($type, self::OBJECT_TYPES, true) === false) {
			throw new UnexpectedValueException(
				'"objectType" must be one of: ' . implode(', ', self::OBJECT_TYPES) . '.'
			);
		}

		if (trim((string)($config['nameFrom'] ?? '')) === '') {
			throw new UnexpectedValueException('"nameFrom" must name the item field holding the name.');
		}

		if (trim((string)($config['output'] ?? '')) === '') {
			throw new UnexpectedValueException('"output" must name the item field to write the UID to.');
		}
	}//end validateConfig()

	/**
	 * Provision one addressbook identity per item.
	 *
	 * @param array<int, array> $items   The incoming items.
	 * @param array<string, mixed> $config The step configuration.
	 * @param array<string, mixed> $context The run context.
	 *
	 * @return array<int, array> The items, each with the UID added.
	 *
	 * @throws UnexpectedValueException When the configuration is unusable.
	 * @throws RuntimeException When an identity could not be provisioned.
	 *
	 * @spec openspec/changes/website-enquiry-intake/specs/website-enquiry-intake/spec.md
	 */
	public function execute(array $items, array $config, array $context): array {
		unset($context);

		// `validateConfig()` only runs on SAVE. A flow imported from an
		// `x-openregister-flows` declaration reaches execute() unvalidated, so
		// the configuration is checked again here rather than assumed.
		$this->validateConfig(config: $config);

		$type = trim((string)$config['objectType']);
		$outKey = trim((string)$config['output']);
		$out = [];

		foreach ($items as $item) {
			$json = (array)($item['json'] ?? []);

			// The vCard matcher only searches ORG when the form type says
			// organization, so a client must say so or two enquiries from one
			// company provision two identities.
			$formType = 'person';
			if ($type === 'client') {
				$formType = 'organization';
			}

			$form = [
				'name' => $this->field(json: $json, path: (string)($config['nameFrom'] ?? '')),
				'email' => $this->field(json: $json, path: (string)($config['emailFrom'] ?? '')),
				'phone' => $this->field(json: $json, path: (string)($config['phoneFrom'] ?? '')),
				'type' => $formType,
			];

			if ($form['name'] === '' && $form['email'] === '') {
				throw new RuntimeException(
					'Cannot provision a contact identity without a name or an email address.'
				);
			}

			$resolved = $this->vcards->provisionContactFromForm(form: $form, objectType: $type);
			if ($resolved === null || trim((string)($resolved['contactsUid'] ?? '')) === '') {
				// Null means Contacts was unavailable or the write failed.
				// Throwing hands the engine the step's onError policy; carrying
				// on would give the next write an item with no uid and a
				// validation error naming the wrong thing.
				throw new RuntimeException(
					'Could not provision an addressbook identity for this ' . $type . '.'
				);
			}

			$json[$outKey] = (string)$resolved['contactsUid'];
			$item['json'] = $json;
			$out[] = $item;
		}//end foreach

		return $out;
	}//end execute()

	/**
	 * One dotted field off the item, as a trimmed string.
	 *
	 * @param array<string, mixed> $json The item's record.
	 * @param string               $path The dotted path, or '' for none.
	 *
	 * @return string The value, or '' when absent or not scalar.
	 */
	private function field(array $json, string $path): string {
		$path = trim($path);
		if ($path === '') {
			return '';
		}

		$cursor = $json;
		foreach (explode('.', $path) as $segment) {
			if (is_array($cursor) === false || array_key_exists($segment, $cursor) === false) {
				return '';
			}

			$cursor = $cursor[$segment];
		}

		if (is_scalar($cursor) === false) {
			return '';
		}

		return trim((string)$cursor);
	}//end field()
}//end class
