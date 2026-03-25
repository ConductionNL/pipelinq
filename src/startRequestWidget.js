import Vue from 'vue'
import { PiniaVuePlugin } from 'pinia'
import pinia from './pinia.js'
import StartRequestWidget from './views/widgets/StartRequestWidget.vue'

Vue.use(PiniaVuePlugin)

OCA.Dashboard.register('pipelinq_start_request_widget', async (el, { widget }) => {
	Vue.mixin({ methods: { t, n } })
	const View = Vue.extend(StartRequestWidget)
	new View({
		pinia,
		propsData: { title: widget.title },
	}).$mount(el)
})
