# Required Documents Management

This update completes the partially started Required Documents feature.

## Access

The management page is available to roles that already control document review:

- Admin
- TOD

The page is available at `/required-documents` and is linked in the sidebar under **Document Control**.

## Behavior

Authorized users can:

- add a required document;
- delete a required document from the master checklist.

The proposal **Lacking Documents** checklist now reads its items from the `required_documents` table every time the proposal review page is loaded. The former hard-coded JavaScript list has been removed.

Deleting a required document only removes it from current/future checklists. Historical `missing_documents.description` entries are intentionally left unchanged for audit/history purposes.

## Database

Run:

```bash
php artisan migrate
```

The migration creates and seeds the `required_documents` table with the original 17 checklist items. A follow-up repair migration is also included to recover installations where the Codex session created part of the feature but did not complete the database setup.

## Security

All three Required Documents routes (index, add, delete) are protected by `auth` and `role:Admin,TOD` middleware. The page is therefore protected server-side even when someone manually types the URL.
# Required Documents - Edit support

- Added PUT update route for Required Documents.
- Admin/TOD can edit a checklist item from the management page.
- Changes immediately affect the master list used by proposal Document Review.
- Historical lacking-document entries remain unchanged.
