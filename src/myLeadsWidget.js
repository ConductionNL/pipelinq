import Vue from 'vue'
import { PiniaVuePlugin } from 'pinia'
import pinia from './pinia.js'
import MyLeadsWidget from './views/widgets/MyLeadsWidget.vue'

Vue.use(PiniaVuePlugin)

OCA.Dashboard.register('pipelinq_my_leads_widget', async (el, { widget }) => {
	Vue.mixin({ methods: { t, n } })
	const View = Vue.extend(MyLeadsWidget)
	new View({
		pinia,
		propsData: { title: widget.title },
	}).$mount(el)
})
