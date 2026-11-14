<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V.

  The sign-up form of one mailing list, as a snippet to paste into any web
  page. The form posts to POST /api/lists/{id}/subscribe, the public endpoint
  that starts the double opt-in: the person gets a link and is only added
  once they open it.

  @spec openspec/specs/marketing-lists/spec.md#requirement-self-service-subscribe-creates-a-pending-subscription
-->
<template>
	<div class="list-signup" data-testid="list-signup-form">
		<p class="list-signup__intro">
			{{ t('pipelinq', 'Paste this form into a page so people can sign up. They get a link and are only added once they open it.') }}
		</p>
		<p class="list-signup__label">
			{{ t('pipelinq', 'Sign-up form') }}
		</p>
		<pre class="list-signup__code"><code>{{ snippet }}</code></pre>
		<NcButton variant="secondary" @click="copy">
			<template #icon>
				<Check v-if="copied" :size="16" />
				<ContentCopy v-else :size="16" />
			</template>
			{{ copied ? t('pipelinq', 'Copied') : t('pipelinq', 'Copy embed code') }}
		</NcButton>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import { NcButton } from '@nextcloud/vue'
import Check from 'vue-material-design-icons/Check.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'

export default {
	name: 'ListSignupFormSection',
	components: { Check, ContentCopy, NcButton },

	props: {
		/** The mailing list the form signs people up to. */
		listId: {
			type: String,
			required: true,
		},
	},

	data() {
		return { copied: false }
	},

	computed: {
		/**
		 * The embed code, with the list's own subscribe address.
		 *
		 * @return {string} The HTML snippet.
		 * @spec openspec/specs/marketing-lists/spec.md#requirement-self-service-subscribe-creates-a-pending-subscription
		 */
		snippet() {
			const action = window.location.origin + generateUrl(`/apps/pipelinq/api/lists/${encodeURIComponent(this.listId)}/subscribe`)
			return [
				`<form method="post" action="${action}">`,
				'  <input type="email" name="email" required>',
				'  <button>Subscribe</button>',
				'</form>',
			].join('\n')
		},
	},

	methods: {
		/**
		 * Put the embed code on the clipboard.
		 *
		 * @spec openspec/specs/marketing-lists/spec.md#requirement-self-service-subscribe-creates-a-pending-subscription
		 */
		async copy() {
			try {
				await navigator.clipboard.writeText(this.snippet)
				this.copied = true
			} catch {
				this.copied = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.list-signup {
	display: flex;
	flex-direction: column;
	gap: 8px;
	align-items: flex-start;
}

.list-signup__intro {
	margin: 0;
}

.list-signup__label {
	margin: 4px 0 0;
	font-weight: 600;
}

.list-signup__code {
	width: 100%;
	box-sizing: border-box;
	margin: 0;
	padding: 10px 12px;
	background: var(--color-background-dark);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	font-size: 12px;
	white-space: pre-wrap;
	word-break: break-all;
}
</style>
