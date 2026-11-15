<?php

/**
 * Find Client dashboard widget.
 *
 * Enhanced client search widget with action buttons for quick CRM operations.
 * Replaces the original ClientSearchWidget.
 *
 * @category Dashboard
 * @package  OCA\Pipelinq\Dashboard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

namespace OCA\Pipelinq\Dashboard;

use OCP\IL10N;

/**
 * Find Client widget for the Nextcloud Dashboard.
 *
 * @spec openspec/specs/dashboard/spec.md#requirement-crm-dashboard-layout
 */
class FindClientWidget extends AbstractPipelinqWidget {
	/**
	 * Constructor.
	 *
	 * @param IL10N $l10n Localisation service
	 */
	public function __construct(
		private IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * Get the unique widget identifier.
	 *
	 * @return string The widget ID
	 * @spec openspec/specs/dashboard/spec.md#requirement-crm-dashboard-layout
	 */
	public function getId(): string {
		return 'pipelinq_find_client_widget';
	}//end getId()

	/**
	 * Get the translated widget title.
	 *
	 * @return string The widget title
	 * @spec openspec/specs/dashboard/spec.md#requirement-crm-dashboard-layout
	 */
	public function getTitle(): string {
		return $this->l10n->t('Find Client');
	}//end getTitle()

	/**
	 * Get the display order of this widget.
	 *
	 * @return int The sort order
	 * @spec openspec/specs/dashboard/spec.md#requirement-crm-dashboard-layout
	 */
	public function getOrder(): int {
		return 13;
	}//end getOrder()

	/**
	 * The widget's own webpack entry.
	 *
	 * @return string The entry name
	 */
	protected function scriptEntry(): string {
		return 'findClientWidget';
	}//end scriptEntry()
}//end class
