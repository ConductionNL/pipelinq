<template>
	<div class="qe">
		<div v-for="(q, i) in questions" :key="q.id" class="qcard">
			<div class="qhdr">
				<span>{{ i + 1 }}.</span>
				<select :value="q.type" @change="setType(i, $event.target.value)">
					<option value="nps">NPS (0-10)</option><option value="rating">Rating (1-5)</option><option value="multiple_choice">Multiple Choice</option><option value="open_text">Open Text</option><option value="yes_no">Yes/No</option>
				</select>
				<label><input type="checkbox" :checked="q.required !== false" @change="setProp(i, 'required', $event.target.checked)" /> Req</label>
				<button type="button" @click="remove(i)">X</button>
			</div>
			<input :value="q.text" :placeholder="t('pipelinq', 'Question text')" @input="setProp(i, 'text', $event.target.value)" />
			<div v-if="q.type === 'multiple_choice'" class="opts">
				<div v-for="(o, oi) in q.options || []" :key="oi" class="opt-row">
					<input :value="o" :placeholder="'Option ' + (oi+1)" @input="setOpt(i, oi, $event.target.value)" />
					<button v-if="(q.options || []).length > 2" type="button" @click="rmOpt(i, oi)">x</button>
				</div>
				<button v-if="(q.options || []).length < 20" type="button" @click="addOpt(i)">+ Option</button>
			</div>
		</div>
		<button v-if="questions.length < 50" type="button" @click="add">+ {{ t('pipelinq', 'Add Question') }}</button>
	</div>
</template>
<script>
function uuid() { return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => { const r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16) }) }
export default {
	name: 'QuestionEditor',
	props: { value: { type: Array, default: () => [] } },
	computed: { questions() { return this.value || [] } },
	methods: {
		emit(arr) { this.$emit('input', arr) },
		add() { this.emit([...this.questions, { id: uuid(), type: 'rating', text: '', required: true, options: [], order: this.questions.length + 1 }]) },
		remove(i) { const a = [...this.questions]; a.splice(i, 1); this.emit(a) },
		setProp(i, k, v) { const a = [...this.questions]; a[i] = { ...a[i], [k]: v }; this.emit(a) },
		setType(i, t) { const a = [...this.questions]; a[i] = { ...a[i], type: t }; if (t === 'multiple_choice' && (!a[i].options || a[i].options.length < 2)) a[i].options = ['', '']; this.emit(a) },
		setOpt(i, oi, v) { const a = [...this.questions]; const opts = [...(a[i].options || [])]; opts[oi] = v; a[i] = { ...a[i], options: opts }; this.emit(a) },
		addOpt(i) { const a = [...this.questions]; a[i] = { ...a[i], options: [...(a[i].options || []), ''] }; this.emit(a) },
		rmOpt(i, oi) { const a = [...this.questions]; const opts = [...(a[i].options || [])]; opts.splice(oi, 1); a[i] = { ...a[i], options: opts }; this.emit(a) },
	},
}
</script>
<style scoped>
.qe { display: flex; flex-direction: column; gap: 12px; margin: 12px 0; }
.qcard { padding: 12px; border: 1px solid var(--color-border); border-radius: 8px; }
.qhdr { display: flex; gap: 8px; align-items: center; margin-bottom: 8px; }
.qcard input[type=text], .qcard input:not([type]) { width: 100%; padding: 8px; border: 1px solid var(--color-border); border-radius: 4px; }
.opts { margin-top: 8px; padding-left: 20px; }
.opt-row { display: flex; gap: 4px; margin-bottom: 4px; }
.opt-row input { flex: 1; padding: 6px; border: 1px solid var(--color-border); border-radius: 4px; }
</style>
