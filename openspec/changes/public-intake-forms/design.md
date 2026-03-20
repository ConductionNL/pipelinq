# Public Intake Forms - Design

## Approach
1. Add form, formField, formSubmission schemas
2. Build form builder UI in admin settings
3. Build public form rendering endpoint (PublicController)
4. Implement form submission processing (create contact/lead)
5. Generate embed codes (iframe/JS snippet)

## Files Affected
- `lib/Settings/pipelinq_register.json` - Add form schemas
- `lib/Controller/PublicFormController.php` - Public form rendering and submission
- `src/views/settings/FormBuilder.vue` - No-code form builder
- `src/views/settings/FormList.vue` - Form management list
- `src/components/forms/FormField.vue` - Field type components
- `src/components/forms/FormPreview.vue` - Live preview
