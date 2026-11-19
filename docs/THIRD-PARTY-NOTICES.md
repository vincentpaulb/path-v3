# PATH Third-Party Notices and Asset Register

Review date: September 8, 2026. Verify versions and licenses again before each release.

| Component/service | Observed use | License/terms note | Risk treatment |
|---|---|---|---|
| Laravel and Composer packages | Server framework and libraries | Each package retains its own license; `composer.lock` records installed versions. | Preserve license notices and run `composer audit` during builds. |
| Google Fonts: DM Sans and Manrope | CSS font import in `public/css/path.css` | Font families are generally distributed under the SIL Open Font License; verify the exact downloaded files and retain their license if self-hosted. | Prefer approved self-hosting to avoid client requests to Google. |
| Font Awesome Free 6.4.0 via cdnjs | Interface icons | Font Awesome Free uses separate licenses for icons, fonts, and code; comply with the upstream Free license terms. | Pin/self-host the approved build and retain notices. |
| Chart.js and chartjs-plugin-datalabels via jsDelivr | Dashboard and analytics charts | MIT-licensed upstream projects. | Pin exact versions, self-host where possible, and provide accessible text/table alternatives. |
| Leaflet 1.9.4 via unpkg | Proposal map selector | Leaflet is BSD-2-Clause licensed. | Retain copyright/license notice; self-host pinned assets. |
| OpenStreetMap data and tiles | Map display | OpenStreetMap data is available under ODbL and requires attribution. Tile services also have usage policies. | Attribution is displayed on the map; review tile policy before public/high-volume use. |
| Nominatim | User-triggered address search | Governed by OpenStreetMap Foundation usage policy and service privacy practices. | Server proxy, global rate limit, and caching are implemented; do not use bulk geocoding on the public service. |
| PATH logo (`public/images/path-logo.png`) | Login and application navigation | Ownership/license metadata is not embedded or documented in this repository. | Obtain and retain written DOST VII authorization or source/design records. Do not assume government status clears third-party design elements. |

Upstream references:

- Font Awesome Free license: https://fontawesome.com/license/free
- Chart.js: https://github.com/chartjs/Chart.js
- chartjs-plugin-datalabels: https://github.com/chartjs/chartjs-plugin-datalabels
- Leaflet: https://github.com/Leaflet/Leaflet
- OpenStreetMap copyright: https://www.openstreetmap.org/copyright
- OpenStreetMap tile usage policy: https://operations.osmfoundation.org/policies/tiles/
- Nominatim usage policy: https://operations.osmfoundation.org/policies/nominatim/

This register documents observed components; it does not replace the license texts bundled with installed packages or a procurement/legal review.
