# Register Content Internationalization - Tasks

- [ ] Mark pipeline.name, pipeline.description as translatable in pipelinq_register.json
- [ ] Mark stage.name as translatable
- [ ] Mark product.name, product.description as translatable
- [ ] Mark productCategory.name, productCategory.description as translatable
- [ ] Build LanguageSelector.vue component
- [ ] Integrate language selector on detail pages with translated content
- [ ] Implement language fallback chain (user lang -> app default -> nl -> en)
- [ ] Add Accept-Language header support in API responses
- [ ] Add ?lang= query parameter override
- [ ] Preserve language selection across navigation (no page reload)
- [ ] Add fallback indicator when showing non-preferred language
