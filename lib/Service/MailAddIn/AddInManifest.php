<?php

/**
 * Pipelinq AddInManifest.
 *
 * The Office add-in manifest an administrator deploys for the organisation,
 * so Outlook shows pipelinq's pane next to a mail being read. Every URL in it
 * points at this instance: the pane, the icons and the support page.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\MailAddIn
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/contact-moments-mail-add-in/specs/mail-add-in/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\MailAddIn;

use OCA\Pipelinq\Kernel\AppIdentity;
use OCP\IConfig;
use OCP\IURLGenerator;

/**
 * Build the Office add-in manifest (mail read form, task pane) for this instance.
 *
 * @spec openspec/changes/contact-moments-mail-add-in/specs/mail-add-in/spec.md#requirement-pipelinq-serves-an-outlook-add-in-req-mai-001
 */
class AddInManifest {
	/**
	 * The manifest's own version. Raise it when the manifest's shape changes,
	 * so Microsoft 365 offers administrators the update.
	 */
	public const VERSION = '1.0.0.0';

	/**
	 * The Outlook hosts that may frame the task pane, and nothing else.
	 */
	public const FRAME_HOSTS = [
		'https://outlook.office.com',
		'https://outlook.office365.com',
		'https://outlook.live.com',
	];

	/**
	 * Where Microsoft serves Office.js, the one script the pane loads from
	 * outside this instance.
	 */
	public const OFFICE_JS_HOST = 'https://appsforoffice.microsoft.com';

	/**
	 * Constructor.
	 *
	 * @param IURLGenerator $urls   Builds the instance's absolute URLs.
	 * @param IConfig       $config Holds the instance id the add-in id derives from.
	 */
	public function __construct(
		private readonly IURLGenerator $urls,
		private readonly IConfig $config,
	) {
	}//end __construct()

	/**
	 * The add-in id: a GUID that stays the same for this instance, so a
	 * redeployed manifest updates the add-in instead of adding a second one,
	 * and two instances never collide.
	 *
	 * @return string A GUID, `xxxxxxxx-xxxx-4xxx-8xxx-xxxxxxxxxxxx`.
	 *
	 * @spec openspec/changes/contact-moments-mail-add-in/specs/mail-add-in/spec.md#requirement-pipelinq-serves-an-outlook-add-in-req-mai-001
	 */
	public function addInId(): string {
		$instanceId = (string)$this->config->getSystemValue('instanceid', '');
		$hex = md5(AppIdentity::APP_ID . '-mail-add-in-' . $instanceId);

		return sprintf(
			'%s-%s-4%s-8%s-%s',
			substr($hex, 0, 8),
			substr($hex, 8, 4),
			substr($hex, 13, 3),
			substr($hex, 17, 3),
			substr($hex, 20, 12)
		);
	}//end addInId()

	/**
	 * The absolute URL of the task pane page on this instance.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/contact-moments-mail-add-in/specs/mail-add-in/spec.md#requirement-pipelinq-serves-an-outlook-add-in-req-mai-001
	 */
	public function taskpaneUrl(): string {
		return $this->urls->linkToRouteAbsolute(AppIdentity::APP_ID . '.mailAddIn.taskpane');
	}//end taskpaneUrl()

	/**
	 * The scheme and host of this instance, e.g. `https://cloud.example.nl`.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/contact-moments-mail-add-in/specs/mail-add-in/spec.md#requirement-pipelinq-serves-an-outlook-add-in-req-mai-001
	 */
	public function origin(): string {
		$parts = parse_url($this->urls->getAbsoluteURL('/'));
		$origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
		if (isset($parts['port']) === true) {
			$origin .= ':' . $parts['port'];
		}

		return $origin;
	}//end origin()

	/**
	 * The manifest XML.
	 *
	 * Element order follows the Office add-in manifest schema (1.1); Outlook
	 * refuses a manifest whose elements are out of order.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/contact-moments-mail-add-in/specs/mail-add-in/spec.md#requirement-pipelinq-serves-an-outlook-add-in-req-mai-001
	 */
	public function xml(): string {
		$values = [
			'id' => $this->addInId(),
			'version' => self::VERSION,
			'icon' => $this->image(name: 'mail-add-in-64.png'),
			'iconHigh' => $this->image(name: 'mail-add-in-128.png'),
			'support' => $this->urls->linkToRouteAbsolute(AppIdentity::APP_ID . '.dashboard.page', ['path' => '']),
			'origin' => $this->origin(),
			'source' => $this->taskpaneUrl(),
		];
		$v = array_map(static fn (string $value): string => htmlspecialchars($value, (ENT_XML1 | ENT_QUOTES), 'UTF-8'), $values);

		return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<OfficeApp xmlns="http://schemas.microsoft.com/office/appforoffice/1.1"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xmlns:bt="http://schemas.microsoft.com/office/officeappbasictypes/1.0"
  xsi:type="MailApp">
  <Id>{$v['id']}</Id>
  <Version>{$v['version']}</Version>
  <ProviderName>Conduction</ProviderName>
  <DefaultLocale>en-US</DefaultLocale>
  <DisplayName DefaultValue="Pipelinq"/>
  <Description DefaultValue="See the sender of a mail in Pipelinq, add them as a contact and log the mail as a contact moment."/>
  <IconUrl DefaultValue="{$v['icon']}"/>
  <HighResolutionIconUrl DefaultValue="{$v['iconHigh']}"/>
  <SupportUrl DefaultValue="{$v['support']}"/>
  <AppDomains>
    <AppDomain>{$v['origin']}</AppDomain>
  </AppDomains>
  <Hosts>
    <Host Name="Mailbox"/>
  </Hosts>
  <Requirements>
    <Sets>
      <Set Name="Mailbox" MinVersion="1.3"/>
    </Sets>
  </Requirements>
  <FormSettings>
    <Form xsi:type="ItemRead">
      <DesktopSettings>
        <SourceLocation DefaultValue="{$v['source']}"/>
        <RequestedHeight>450</RequestedHeight>
      </DesktopSettings>
    </Form>
  </FormSettings>
  <Permissions>ReadItem</Permissions>
  <Rule xsi:type="RuleCollection" Mode="Or">
    <Rule xsi:type="ItemIs" ItemType="Message" FormType="Read"/>
  </Rule>
  <DisableEntityHighlighting>false</DisableEntityHighlighting>
</OfficeApp>

XML;
	}//end xml()

	/**
	 * The absolute URL of one of the app's images.
	 *
	 * @param string $name The file name under img/.
	 *
	 * @return string
	 */
	private function image(string $name): string {
		return $this->urls->getAbsoluteURL($this->urls->imagePath(AppIdentity::APP_ID, $name));
	}//end image()
}//end class
