# Register Content Internationalization - Design

## Approach
1. Mark translatable fields in pipelinq_register.json using OpenRegister's translatable flag
2. Build LanguageSelector.vue component for content language switching
3. Extend API responses with Accept-Language / Content-Language header support
4. Implement fallback chain (user lang -> app default -> nl -> en -> first available)

## Files Affected
- `lib/Settings/pipelinq_register.json` - Add translatable: true to pipeline.name, stage.name, product.name, product.description, etc.
- `src/components/LanguageSelector.vue` - New language switcher component
- `src/views/settings/PipelineForm.vue` - Multi-language input for pipeline names
- `src/views/products/ProductDetail.vue` - Multi-language input for product fields
- `lib/Controller/ObjectsController.php` - Add Accept-Language header handling (if custom controller exists)
