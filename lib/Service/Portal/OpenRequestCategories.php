<?php

/**
 * Pipelinq OpenRequestCategories.
 *
 * The ticket categories a desk opened to clients: the ones the customer
 * portal offers on a new request, and the only ones a portal request may name
 * once any is open.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Portal
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/specify-capability-rows/specs/customer-portal/spec.md#requirement-a-client-files-a-request-in-the-portal-and-follows-it
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Portal;

use OCP\AppFramework\Http;

/**
 * Reads the open categories from the ticket category vocabulary.
 *
 * @spec openspec/changes/specify-capability-rows/specs/customer-portal/spec.md#requirement-a-client-files-a-request-in-the-portal-and-follows-it
 */
final class OpenRequestCategories {
	/**
	 * The vocabulary a request's category key comes from.
	 *
	 * @var string
	 */
	private const SCHEMA = 'ticketCategory';

	/**
	 * Constructor.
	 *
	 * @param MainRegisterReader $reader Reads the main register.
	 */
	public function __construct(
		private MainRegisterReader $reader,
	) {
	}//end __construct()

	/**
	 * The active categories a desk opened to clients, by name.
	 *
	 * @return array<int, array{key: string, name: string, description: string}> The categories.
	 *
	 * @spec openspec/changes/specify-capability-rows/specs/customer-portal/spec.md#requirement-a-client-files-a-request-in-the-portal-and-follows-it
	 */
	public function all(): array {
		if ($this->reader->hasSchema(schemaKey: self::SCHEMA) === false) {
			return [];
		}

		$open = [];
		foreach ($this->reader->findAll(self::SCHEMA) as $category) {
			$key = trim((string)($category['key'] ?? ''));
			if ($key === '' || ($category['exposeToCustomer'] ?? false) !== true || ($category['active'] ?? true) === false) {
				continue;
			}

			$open[$key] = [
				'key' => $key,
				'name' => (string)($category['name'] ?? $key),
				'description' => (string)($category['description'] ?? ''),
			];
		}

		usort($open, static fn (array $left, array $right): int => strcmp($left['name'], $right['name']));
		return $open;
	}//end all()

	/**
	 * Refuse a category a client may not file under.
	 *
	 * Once a desk has opened any category to clients, a request names one of
	 * those. While none is open the intake stays open, as it was before
	 * categories could be opened, so a portal whose desk has not sorted its
	 * categories yet still takes requests.
	 *
	 * @param string $categoryId The chosen category key.
	 *
	 * @return void
	 *
	 * @throws PortalException 422 categoryNotAvailable for any other category.
	 *
	 * @spec openspec/changes/specify-capability-rows/specs/customer-portal/spec.md#requirement-a-client-files-a-request-in-the-portal-and-follows-it
	 */
	public function assertOpen(string $categoryId): void {
		$open = array_column($this->all(), 'key');
		if ($open === [] || in_array($categoryId, $open, true) === true) {
			return;
		}

		throw new PortalException(
			Http::STATUS_UNPROCESSABLE_ENTITY,
			'categoryNotAvailable',
			'Deze categorie is niet beschikbaar.'
		);
	}//end assertOpen()
}//end class
