<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
  - The segment rule editor: an AND/OR tree of conditions (SegmentRuleNode),
  - validated and sized by a debounced POST /api/segments/preview.
  -
  - Unfinished conditions are caught here before anything is sent, so a row
  - the user has only just added is not reported as an error. Once every
  - condition is filled in, the server's verdict decides validity, and its
  - error is shown on the row its path points at.
  -->
<template>
	<div class="segment-builder">
		<div class="segment-builder__header">
			<h3 class="segment-builder__title">
				{{ t('pipelinq', 'Rules') }}
			</h3>
			<div class="segment-builder__estimate" role="status" aria-live="polite">
				<template v-if="status === 'checking'">
					<NcLoadingIcon :size="16" />
					{{ t('pipelinq', 'Checking rules…') }}
				</template>
				<span v-else-if="status === 'valid'" class="segment-builder__count">
					{{ t('pipelinq', 'Estimated members:') }}
					<strong>{{ estimatedSize }}</strong>
				</span>
				<span v-else-if="status === 'incomplete'">
					{{ t('pipelinq', 'Complete every condition to see the estimate.') }}
				</span>
			</div>
		</div>

		<SegmentRuleNode
			:node="tree"
			:depth="0"
			:fieldOptions="fieldOptions"
			:errors="errors"
			@update:node="onTreeUpdate" />

		<NcNoteCard v-if="generalError" type="error" class="segment-builder__error">
			{{ generalError }}
		</NcNoteCard>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import SegmentRuleNode from './SegmentRuleNode.vue'

const PREVIEW_DEBOUNCE_MS = 400

// The validator prefixes its message with the failing node's path, e.g.
// `$.children[1].children[0]: field "x" is not declared on the entity schema.`
const ERROR_PATH = /^(\$(?:\.children\[\d+\])*):\s*(.*)$/s

/**
 * @return {object} An empty AND group.
 */
function emptyTree() {
	return { type: 'AND', children: [] }
}

/**
 * @param {object} node A rule tree node.
 * @return {object} A deep copy, or an empty tree for anything unusable.
 */
function cloneTree(node) {
	if (!node || typeof node !== 'object') {
		return emptyTree()
	}
	try {
		return JSON.parse(JSON.stringify(node))
	} catch {
		return emptyTree()
	}
}

/**
 * @param {object} node A rule tree node.
 * @return {{leaves: number, unfinished: number}} Its condition count, and how
 *   many conditions or groups are not filled in yet.
 */
function tally(node) {
	if (Array.isArray(node?.children)) {
		if (node.children.length === 0) {
			return { leaves: 0, unfinished: 1 }
		}
		return node.children.reduce((sum, child) => {
			const sub = tally(child)
			return { leaves: sum.leaves + sub.leaves, unfinished: sum.unfinished + sub.unfinished }
		}, { leaves: 0, unfinished: 0 })
	}
	const noValue = node?.value === '' || node?.value === null || node?.value === undefined
	const unfinished = !node?.field || !node?.operator || noValue
	return { leaves: 1, unfinished: unfinished ? 1 : 0 }
}

export default {
	name: 'SegmentBuilder',
	components: {
		NcLoadingIcon,
		NcNoteCard,
		SegmentRuleNode,
	},

	props: {
		modelValue: {
			type: Object,
			default: () => emptyTree(),
		},

		entityType: {
			type: String,
			required: true,
			validator: (v) => ['contact', 'customer'].includes(v),
		},

		fieldOptions: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['update:modelValue', 'validityChange', 'statusChange'],

	data() {
		return {
			tree: cloneTree(this.modelValue),
			// empty | incomplete | checking | valid | invalid | failed
			status: 'empty',
			estimatedSize: null,
			errors: {},
			generalError: '',
			previewTimer: null,
			// Only the newest request may set the result; an older one can
			// come back later.
			requestSeq: 0,
		}
	},

	watch: {
		modelValue(next) {
			if (next !== this.tree && JSON.stringify(next) !== JSON.stringify(this.tree)) {
				this.tree = cloneTree(next)
				this.evaluate()
			}
		},

		status(next) {
			this.$emit('statusChange', next)
			this.$emit('validityChange', next === 'valid')
		},
	},

	mounted() {
		this.$emit('statusChange', this.status)
		this.evaluate()
	},

	beforeUnmount() {
		clearTimeout(this.previewTimer)
	},

	methods: {
		onTreeUpdate(updated) {
			this.tree = updated
			this.$emit('update:modelValue', cloneTree(updated))
			this.evaluate()
		},

		/**
		 * Settle what can be told locally, and schedule a preview only for a
		 * tree whose every condition is filled in.
		 *
		 * @spec openspec/specs/marketing-ui/spec.md#requirement-segment-builder-ui-composes-rule-trees
		 */
		evaluate() {
			clearTimeout(this.previewTimer)
			this.requestSeq++
			this.errors = {}
			this.generalError = ''
			this.estimatedSize = null

			const { leaves, unfinished } = tally(this.tree)
			if (leaves === 0 && this.tree.children?.length === 0) {
				this.status = 'empty'
				return
			}
			if (unfinished > 0) {
				this.status = 'incomplete'
				return
			}
			this.status = 'checking'
			this.previewTimer = setTimeout(() => this.runPreview(), PREVIEW_DEBOUNCE_MS)
		},

		/**
		 * Validate and size the unsaved tree in one request.
		 *
		 * @spec openspec/specs/marketing-ui/spec.md#scenario-live-size-estimate-shown
		 * @spec openspec/specs/marketing-ui/spec.md#scenario-visual-rule-tree-with-live-validation
		 */
		async runPreview() {
			const seq = this.requestSeq
			let data
			try {
				const response = await axios.post(generateUrl('/apps/pipelinq/api/segments/preview'), {
					entityType: this.entityType,
					rules: this.tree,
				})
				data = response.data
			} catch {
				data = null
			}
			if (seq !== this.requestSeq) {
				return
			}
			if (!data) {
				this.status = 'failed'
				this.generalError = this.t('pipelinq', 'Could not validate rules.')
				return
			}
			if (data.valid === false) {
				this.status = 'invalid'
				this.placeError(data.error || this.t('pipelinq', 'Invalid rules.'))
				return
			}
			this.estimatedSize = data.estimatedSize ?? 0
			this.status = 'valid'
		},

		/**
		 * Show the validator's message on the node its path names, or under
		 * the rules when the path is missing or points nowhere shown.
		 *
		 * @param {string} message The validator's error.
		 */
		placeError(message) {
			const match = ERROR_PATH.exec(message)
			if (match && match[1] !== '$') {
				this.errors = { [match[1]]: match[2] }
				return
			}
			this.generalError = match ? match[2] : message
		},
	},
}
</script>

<style scoped>
.segment-builder {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.segment-builder__header {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: 8px 12px;
}

.segment-builder__title {
	margin: 0;
	font-size: 1.1em;
}

.segment-builder__estimate {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	color: var(--color-text-maxcontrast);
}

.segment-builder__count strong {
	color: var(--color-main-text);
}

.segment-builder__error {
	margin: 0;
}
</style>
