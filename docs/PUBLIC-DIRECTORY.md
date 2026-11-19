# Public directory

The public map includes funded, non-archived proposals only. It exposes proponent names, addresses, program and industry classifications, saved locations, and explicitly approved highlights. It does not expose contact details, financial amounts, internal documents, or visitor reports.

## Browsing

- On mobile, the map opens first. Browse proponents opens a dedicated directory view; Back to map returns to the map. Search and filters expand on demand.
- The region is framed symmetrically in the map viewport. Province, industry, and program filters narrow the results. The former map-area search is removed, and old bounds parameters no longer hide projects.
- Saved pins display a location-accuracy label. Geocoded and manually placed coordinates are unverified until an administrator verifies them. Changing the coordinates invalidates the previous verification.
- Share project link opens the specific public proposal popup. It does not grant access to staff records.
- Report incorrect information is available in the directory and map popups. It accepts a category and a short description, is protected by CSRF and rate limiting, and never changes the proposal automatically.

## Administration

Open **Administration → Public Directory**, or `/public-directory/review` as an administrator.

Select a funded project to enter a public outcome summary, upload a JPEG/PNG/WebP photo, and provide a photo description. Check the approval box only after reviewing the content for public release. Clear approval to withdraw the highlight. Unapproved photos stay in private storage and are inaccessible through the public photo endpoint. Staff can preview them on the review screen.

Verify coordinates only after checking their accuracy. Visitor reports appear in the same review page; mark them resolved or dismissed after investigation. Make confirmed corrections in the existing proposal editor.

## Setup and checks

Run `php artisan migrate` when deploying the new tables. No external reporting service or public storage symlink is required for highlights.

Run `php artisan test --filter=PublicProposalDirectoryTest` for data visibility, geographic filtering, publishing, and review access checks. Mobile layout, clipboard behavior, and shared-link popup persistence require browser verification.
