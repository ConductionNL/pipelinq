<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V.
-->

# Website enquiry form

A form on your own website posts to Pipelinq, and the submission becomes the object its published web form names: a ticket, a lead or a contact. Since the marketing-campaign-on-client change, the form also passes the campaign values of the page it sits on, so you can open a contact or a client and see how they found you.

## What Pipelinq keeps

Besides the fields the form maps onto its destination, a submission may carry:

| Field | What it holds | Limit |
| --- | --- | --- |
| `utmCampaign` | the page's `utm_campaign` value | 100 characters |
| `utmSource` | the page's `utm_source` value | 100 characters |
| `utmMedium` | the page's `utm_medium` value | 100 characters |
| `utmContent` | the page's `utm_content` value | 100 characters |
| `utmTerm` | the page's `utm_term` value | 100 characters |
| `pageUrl` | the address of the page the form was sent from | 2048 characters |

The `utm_*` spelling is accepted too. Values are trimmed and cut to the limit. They describe the visit only: they never set the destination's status, channel or any other field the form fixes, and they are shown as text, never as links.

Every submission is recorded as a touchpoint. When the submission's email address (`contactEmail` or `email`) belongs to a contact, the touchpoint names that contact and the contact's client, and both get a **last touch**; the **first touch** is written once and never replaced. When the address belongs to no contact yet, the values stay on the touchpoint alone and no contact is created. When the campaign value is the campaign value of a Pipelinq campaign, the touchpoint names that campaign.

Contact and client pages show the touches under **How they found us** (Dutch: *Hoe ze ons vonden*) and every submission under **Web form submissions** (*Webformulierinzendingen*), newest first.

## Embed snippet

Put this after your form. It copies the page's own campaign values and address into hidden fields just before the form is sent, so the visitor never types them.

```html
<form id="pipelinq-enquiry" action="https://<your-nextcloud>/apps/pipelinq/api/enquiry" method="post">
	<input type="hidden" name="source" value="website-support">
	<!-- Hidden from people: a robot that fills it is refused. -->
	<input type="text" name="website" value="" tabindex="-1" autocomplete="off" hidden>
	<label>Naam <input type="text" name="contactName" required></label>
	<label>E-mailadres <input type="email" name="contactEmail" required></label>
	<label>Uw vraag <textarea name="message" required></textarea></label>
	<button type="submit">Versturen</button>
</form>
<script>
(function () {
	var form = document.getElementById('pipelinq-enquiry')
	var params = new URLSearchParams(window.location.search)
	var fields = {
		utmCampaign: 'utm_campaign',
		utmSource: 'utm_source',
		utmMedium: 'utm_medium',
		utmContent: 'utm_content',
		utmTerm: 'utm_term',
	}
	form.addEventListener('submit', function () {
		Object.keys(fields).forEach(function (name) {
			var value = params.get(fields[name])
			if (value) {
				var input = form.querySelector('input[name="' + name + '"]') || form.appendChild(document.createElement('input'))
				input.type = 'hidden'
				input.name = name
				input.value = value.slice(0, 100)
			}
		})
		var page = form.querySelector('input[name="pageUrl"]') || form.appendChild(document.createElement('input'))
		page.type = 'hidden'
		page.name = 'pageUrl'
		page.value = window.location.origin + window.location.pathname
	})
})()
</script>
```

`source` is the key of the published web form. `pageUrl` is sent without its query string, so the campaign values are not stored twice. A site that keeps the visitor's first landing page in a cookie can send that address instead.

## Related

- [Marketing](marketing.md): campaigns, touchpoints and attribution.
- [Public intake forms](public-intake-forms.md).
