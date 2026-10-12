# Public date field restoration

Approved intent: restore the public legacy date factory using native HTML date inputs, without a calendar feature.

Inventory: `src/Field/Factory.php`, facade documentation, new `Forms/Fields/Types/DateType.php` extending TextType for string transformation and existing post/user/term metadata contracts; native `types.date` views in Forms themosis/bootstrap, User themosis.users, Taxonomy themosis.taxonomy, and Page themosis.pages (inherited settings contract). Registered `themosis.fields.date` React component and core entry. PHP factory/form/theme tests, React render/event/registration tests, owned WordPress metadata tests and opt-in date HTTP fixture. Direct core bundle and its referenced license only.

Legacy mapping: retain `date($name, array $features = [], array $attributes = [])`; map features `title` to current `label`, retain `info` and current options including configured rules; explicit current `label` takes precedence over title. Merge default `class=newtag`, feature attributes, positional attributes, then force `data-field=date` as before. Return FieldTypeInterface; no new mandatory third-party interface method. Current prefix and generated id remain consistent with other types.

Values remain ISO strings or empty strings; validation is explicitly configured through existing form/metadata managers. Native views and React show type=date, preserve attributes and change serialization. Text controls retain type=text. Browser checks cover current Forms/User/Taxonomy/metabox architecture on owned resources, API/database persistence, reload, empty values and configured rejection.
