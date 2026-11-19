# Reconstruction Notes

The source package contained 49 PHP application files and six database tables. Its repeated SETUP/GIA/CEST/SMART pages shared nearly identical behavior and were consolidated into one parameterized proposal module. Direct `mysqli` calls, session variables, inline authorization, mixed presentation/business logic, duplicated chart queries, and destructive GET actions were not carried forward.

The new design separates controllers, validation requests, Eloquent models, enums, middleware and audit services. All writes use CSRF-protected Laravel routes. Destructive actions use DELETE/PATCH/PUT semantics instead of URL query deletion.

The UI is intentionally new: a restrained navy/cyan DOST-oriented visual language, high-density operational tables, responsive cards, record workspaces, consistent status badges, and a dedicated funding queue. It does not reuse the BootstrapMade NiceAdmin template found in the legacy application.
