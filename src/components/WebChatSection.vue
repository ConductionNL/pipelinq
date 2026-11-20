<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V.

  The website chat on its ticket (requests-web-chat-and-bot D6): the transcript
  with who said what, Take this chat, a reply box and End chat. Renders nothing
  on a ticket that is not a website chat. Polls every three seconds while the
  chat is open.

  @spec openspec/changes/requests-web-chat-and-bot/tasks.md#task-3.2
-->
<template>
	<section v-if="conversationId" class="web-chat-section">
		<h3 class="web-chat-section__title">
			{{ t('pipelinq', 'Website chat') }}
		</h3>
		<p v-if="error" class="web-chat-section__error" role="alert">
			{{ error }}
		</p>
		<ol class="web-chat-section__log" role="log" aria-live="polite">
			<li
v-for="message in messages"
				:key="message.id"
				class="web-chat-section__message" :class="['web-chat-section__message--' + message.author]">
				<span class="web-chat-section__author">{{ authorLabel(message) }}</span>
				<p class="web-chat-section__body">
					{{ message.body }}
				</p>
				<ul v-if="message.sources && message.sources.length" class="web-chat-section__sources">
					<li v-for="source in message.sources" :key="source.url">
						<a :href="source.url" target="_blank" rel="noopener noreferrer">{{ source.title }}</a>
					</li>
				</ul>
			</li>
		</ol>
		<p class="web-chat-section__state">
			{{ stateLabel }}
		</p>
		<div v-if="status !== 'closed'" class="web-chat-section__actions">
			<NcButton
v-if="assignedTo === ''"
				variant="primary"
				data-testid="web-chat-take"
				:disabled="busy"
				@click="act('take')">
				{{ t('pipelinq', 'Take this chat') }}
			</NcButton>
			<NcTextArea
v-model="reply"
				:label="t('pipelinq', 'Your answer')"
				data-testid="web-chat-reply"
				:disabled="busy" />
			<NcButton
variant="primary"
				data-testid="web-chat-send"
				:disabled="busy || reply.trim() === ''"
				@click="act('reply', { text: reply })">
				{{ t('pipelinq', 'Send') }}
			</NcButton>
			<NcButton
variant="tertiary"
				data-testid="web-chat-end"
				:disabled="busy"
				@click="act('end')">
				{{ t('pipelinq', 'End chat') }}
			</NcButton>
		</div>
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcTextArea } from '@nextcloud/vue'

/** How often the transcript is read while the chat is open (D6). */
export const POLL_MS = 3000

/**
 * The conversation id of a website chat ticket, or '' for any other ticket.
 *
 * @param {string} channel The ticket's channel.
 * @param {string} threadKey The ticket's inbound thread key.
 * @return {string}
 */
export function chatConversationOf(channel, threadKey) {
	if (channel !== 'chat' || typeof threadKey !== 'string' || !threadKey.startsWith('conversation:')) {
		return ''
	}
	return threadKey.slice('conversation:'.length)
}

export default {
	name: 'WebChatSection',
	components: { NcButton, NcTextArea },
	props: {
		channel: { type: String, default: '' },
		threadKey: { type: String, default: '' },
	},

	data() {
		return { messages: [], status: '', assignedTo: '', reply: '', busy: false, error: '', timer: null }
	},

	computed: {
		conversationId() {
			return chatConversationOf(this.channel, this.threadKey)
		},

		stateLabel() {
			if (this.status === 'closed') {
				return t('pipelinq', 'This chat has ended.')
			}
			if (this.assignedTo !== '') {
				return t('pipelinq', 'Taken by {user}.', { user: this.assignedTo })
			}
			return t('pipelinq', 'Waiting for a colleague.')
		},
	},

	watch: {
		conversationId: {
			immediate: true,
			handler() {
				this.stop()
				if (this.conversationId !== '') {
					this.load()
					this.timer = setInterval(() => this.load(), POLL_MS)
				}
			},
		},
	},

	beforeUnmount() {
		this.stop()
	},

	methods: {
		url(suffix = '') {
			return generateUrl('/apps/pipelinq/api/web-chat/conversations/{id}' + suffix, { id: this.conversationId })
		},

		apply(view) {
			this.messages = Array.isArray(view.messages) ? view.messages : []
			this.status = view.status || ''
			this.assignedTo = view.assignedTo || ''
			if (this.status === 'closed') {
				this.stop()
			}
		},

		async load() {
			try {
				const { data } = await axios.get(this.url())
				this.error = ''
				this.apply(data)
			} catch {
				this.error = t('pipelinq', 'The chat could not be loaded.')
			}
		},

		async act(verb, body = {}) {
			this.busy = true
			try {
				const { data } = await axios.post(this.url('/' + verb), body)
				this.error = ''
				this.apply(data)
				if (verb === 'reply') {
					this.reply = ''
				}
			} catch {
				this.error = t('pipelinq', 'The chat could not be changed.')
			} finally {
				this.busy = false
			}
		},

		authorLabel(message) {
			if (message.author === 'visitor') {
				return t('pipelinq', 'Visitor')
			}
			if (message.author === 'assistant') {
				return t('pipelinq', 'Assistant')
			}
			return message.agentName || t('pipelinq', 'Colleague')
		},

		stop() {
			if (this.timer !== null) {
				clearInterval(this.timer)
				this.timer = null
			}
		},
	},
}
</script>

<style scoped>
.web-chat-section__log {
	margin: 0;
	padding: 0;
	list-style: none;
	max-height: 360px;
	overflow-y: auto;
}

.web-chat-section__message {
	margin: 6px 0;
	padding: 8px 10px;
	border-radius: var(--border-radius-large);
	background: var(--color-background-dark);
}

.web-chat-section__message--visitor {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
}

.web-chat-section__author {
	font-weight: bold;
	font-size: 0.85em;
}

.web-chat-section__body {
	margin: 2px 0 0;
	white-space: pre-wrap;
}

.web-chat-section__state,
.web-chat-section__error {
	color: var(--color-text-maxcontrast);
}

.web-chat-section__error {
	color: var(--color-error-text);
}

.web-chat-section__actions {
	display: flex;
	flex-direction: column;
	gap: 8px;
}
</style>
