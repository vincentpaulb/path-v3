# PATH Compliance and Risk Register

Review date: September 8, 2026  
Scope: source-code review of the local PATH Laravel application. This is a technical compliance aid, not a legal opinion or certification.

## Updating sign-off status in PATH

The live sign-off register is managed from **Governance → Compliance Center**. All signed-in users can view current statuses; only an Admin can change them or open the detailed risk register.

1. In the **Required sign-off** card, select the pen button beside a requirement.
2. Choose `Pending`, `Under Review`, `Approved`, or `Rejected`.
3. Record the responsible office or approver, review and approval dates, and decision remarks.
4. Attach signed approval or audit evidence and save the update.
5. Review protected evidence and the timestamped change history in **Internal risk register**.

PATH will not accept `Approved` without a responsible office, review date, approval date, and at least one evidence file. Rejection requires remarks. Evidence is stored privately and its download is restricted to Admin accounts. The header status is calculated from the live requirements; `COMPLIANCE_POLICY_STATUS` is now only the fallback shown before review activity begins.

## Decision summary

- Cookie consent banner: not currently required by the observed implementation. PATH uses necessary authentication/security cookies and an optional user-requested remember-me token. Reassess before adding behavioral analytics, advertising, embedded media, chat, or other optional tracking.
- Behavioral tracking: none found. The Analytics module summarizes proposal data; it does not track user behavior.
- Third-party embeds: no iframe, video, or social-media embed was found in PATH views. External CDN resources and OpenStreetMap services are used and remain a privacy/supply-chain consideration.
- Images: only `public/images/path-logo.png` was found. Its ownership or agency authorization cannot be proven from the file itself and must be documented.
- Accessibility: baseline improvements were added, but formal WCAG conformance requires automated testing plus manual keyboard and assistive-technology testing across every workflow.

## Critical actions before production or public-portal launch

| Risk | Required action | Owner/evidence |
|---|---|---|
| Privacy notice is not formally approved | DPO and legal office must validate purposes, lawful bases, recipients, contact details, and data-subject request process; record the decision and evidence in the Compliance Center. | Signed/versioned approval and published effective date |
| No completed Privacy Impact Assessment is present in the repository | Conduct and approve a PIA covering users, evaluators, proposal contacts, uploaded documents, financial records, geolocation, exports, notifications, audit logs, backups, and the future public portal. | DPO-approved PIA and treatment plan |
| Production security cannot be verified from source | Require HTTPS, `APP_DEBUG=false`, protected secrets, `SESSION_SECURE_COOKIE=true`, least-privilege database accounts, firewalling, patching, backups, restoration tests, malware scanning, centralized logging, and an incident response plan. | Deployment checklist and test evidence |
| Retention periods are not defined in code | Map each record class to the DOST VII Agency Records Disposition Schedule. Do not permanently delete government records without authority under RA 9470 and applicable audit/legal holds. | Approved retention schedule and disposal procedure |
| DPO/DPS registration status is unknown | DPO should determine and document registration or exemption under NPC Circular 2022-04 and keep registration current. | NPC certificate or valid exemption documentation |
| Public portal is not privacy-classified | Before launch, classify every field as public, restricted, or confidential. Never expose contact details, precise coordinates, evaluator remarks, financial attachments, user data, or audit logs by default. | Public-release matrix and separate PIA/security review |

## High risks

| Risk | Current state | Recommended treatment |
|---|---|---|
| External dependency requests | Google Fonts, cdnjs Font Awesome, jsDelivr Chart.js, unpkg Leaflet, Nominatim, and OpenStreetMap tiles are used. Providers receive normal network metadata; address searches are sent to Nominatim through the server. | Prefer approved self-hosted, pinned assets. Maintain vendor review, licenses, versions, and integrity hashes where CDN delivery remains. Consider an approved geocoder/tile service or self-hosting for production volume and privacy. |
| Uploaded-file threats | File-size limits and authenticated downloads existed; MIME/extension allowlists were added. ZIP and Office/PDF files can still contain harmful content. | Add antivirus/content-disarm scanning, quarantine, safe preview rules, filename sanitation review, and ZIP-bomb protections. Never serve uploads inline from an executable/public directory. |
| Authentication assurance | Password hashing and session regeneration exist; login rate limiting was added. MFA, forced lockout governance, compromised-password checks, and privileged re-authentication are absent. | Require MFA for administrators and privileged roles, review password rules, log authentication events, and establish account lifecycle/access review procedures. |
| Data exports | Spreadsheet/PDF exports can create unmanaged copies of personal and financial information. | Add export audit logs, classification labels/watermarks where appropriate, role review, secure transfer/storage guidance, and disposal controls. |
| At-rest protection | Session payload encryption was enabled. Database fields and stored uploaded files are not application-level encrypted. | Confirm encrypted disks/backups and restricted storage keys; assess field/file encryption based on the PIA and threat model. |
| Availability and recovery | Source code does not prove backup, restoration, continuity, monitoring, or disaster-recovery controls. | Implement tested encrypted backups, RPO/RTO, monitoring, capacity limits, and a documented business-continuity plan. |

## Medium risks and observations

- A Content Security Policy and baseline security headers were added, but inline scripts/styles still require `'unsafe-inline'`. Refactor inline code to versioned local assets and then adopt nonces/hashes.
- The code relies on external JavaScript without Subresource Integrity. Pin and self-host approved versions to reduce supply-chain exposure.
- Map use is user-triggered and geocoding is cached/rate-limited, which reduces disclosure and service load. Ensure usage complies with Nominatim and OpenStreetMap tile policies; public-portal traffic may exceed community-service expectations.
- Role and province scoping are positive controls, but permissions and direct-object authorization need repeatable automated tests for every role.
- Permanent-delete controls exist in Archives. Confirm they respect government records schedules, audit holds, and related-record integrity before use.
- Application activity logs exist, but retention, tamper resistance, administrator access, and security-event alerting require operational policy.
- Notification polling and error logs may expose names or record context. Limit log content and access, set retention, and ensure production errors never show stack traces.
- The current code has no privacy-request workflow. Establish an offline DPO procedure or build a tracked request process.
- Contact and evaluator data include sex, phone, email, address, and organizational details. Confirm necessity and minimize optional fields under the proportionality principle.
- Exact proposal coordinates should be restricted; public maps should use generalized locations unless precise publication is authorized and assessed.
- The DPO name and office contact in `config/compliance.php` came from current official DOST pages but must be revalidated before deployment and whenever personnel change.
- No automated accessibility test suite is configured. Add axe/Lighthouse checks and manual screen-reader, zoom, reflow, and full keyboard-path testing.
- Several custom JavaScript modals need a full focus-trap, focus-return, Escape-key, and accessible-name audit.
- Color contrast has not been measured for every state and chart color. Test WCAG contrast, non-color status cues, chart alternatives, and 200%/400% zoom.
- The PATH logo has useful alt text. Decorative icons should remain hidden from assistive technology; remaining icon-only controls should be checked as pages evolve.
- Password rules are inconsistent between account creation, account update, and evaluator workflows. Standardize them and prioritize length, breached-password screening, MFA, and secure recovery.
- A public accessibility statement and alternate contact channel were added. A formal feedback mechanism, FAQ, API publication policy, and government directory are not implemented; these become particularly important if PATH becomes a public government portal under RA 12254 and its IRR.

## Applicable Philippine legal and policy framework to validate with counsel

1. Republic Act No. 10173 (Data Privacy Act of 2012), its IRR, and NPC issuances: transparency, lawful basis, proportionality, data-subject rights, security, accountability, and breach handling.
2. NPC Circular No. 2023-06: current security requirements for personal data in government and private sectors, including DPO, PIA, privacy management, access, continuity, training, and security controls. It repealed NPC Circular 16-01 effective March 30, 2024.
3. NPC Circular No. 2022-04: registration of DPOs and data processing systems, including mandatory thresholds and registration timing.
4. NPC Circular No. 2023-04: consent guidance and the requirement for an accessible privacy notice even when consent is not the lawful basis.
5. Republic Act No. 9470 (National Archives of the Philippines Act): records disposition schedules and authorization before disposal of public records.
6. Republic Act No. 8792 (Electronic Commerce Act): integrity, confidentiality, and government use of electronic records and transactions.
7. Republic Act No. 10175 (Cybercrime Prevention Act): offenses affecting confidentiality, integrity, availability, and lawful system access.
8. Republic Act No. 8293 (Intellectual Property Code): copyrighted software, images, fonts, maps, and other works; government-work rules do not automatically clear third-party material.
9. DICT Memorandum Circular No. 2017-004 / Philippine Web Accessibility Policy, adopting ISO/IEC 40500:2012 (WCAG 2.0) for covered government web services.
10. Republic Act No. 12254 (E-Governance Act) and its 2026 IRR: cybersecurity, privacy, accessibility, resilience, interoperability, and additional minimum standards for government websites and portals.
11. Republic Act No. 11032 (Ease of Doing Business and Efficient Government Service Delivery Act), where PATH supports covered government transactions or public services.
12. DOST policies, COA rules, funding-program requirements, contracts, records schedules, FOI rules/exemptions, and agency cybersecurity/acceptable-use policies.

## Authoritative references

- NPC, DPA IRR: https://privacy.gov.ph/implementing-rules-regulations-data-privacy-act-2012/
- NPC, Circular 2023-06 overview: https://privacy.gov.ph/npc-issues-circulars-to-strengthen-personal-data-protection-in-ph/
- NPC, Circular 2022-04 registration reminder: https://privacy.gov.ph/reminder-on-mandatory-data-protection-officer-and-data-processing-system-registration/
- NPC, Circular 2023-04 consent guidelines: https://privacy.gov.ph/wp-content/uploads/2023/11/NPC-Circular-No.-2023-04_Guidelines-on-Consent_07Nov2023.pdf
- NPC cookie advisory opinion 2017-047: https://privacy.gov.ph/wp-content/uploads/2022/01/NPC_AdvisoryOpinionNo._2017-047.pdf
- Philippine Web Accessibility Policy: https://ncda.gov.ph/memorandum-circular-no-2017-004-prescribing-the-philippine-web-accessibility-policy-and-adopting-for-this-purpose-iso-iec-405002012-information-technology-w3c-web-content-accessibility-gu/
- RA 12254 and 2026 IRR: https://lawphil.net/statutes/repacts/ra2026/irr_12254_2026.html
- RA 9470: https://lawphil.net/statutes/repacts/ra2007/ra_9470_2007.html
- RA 8792: https://lawphil.net/statutes/repacts/ra2000/ra_8792_2000.html
- RA 10175: https://lawphil.net/statutes/repacts/ra2012/ra_10175_2012.html
- RA 8293: https://lawphil.net/statutes/repacts/ra1997/ra_8293_1997.html
- DOST VII privacy notice: https://region7.dost.gov.ph/wp-content/uploads/2017/11/DOST-VII-Privacy-Notice.pdf
- DOST VII contact page: https://region7.dost.gov.ph/contact-us/
- DOST DPO directory: https://dost.gov.ph/contact-us/list-of-freedom-of-information-receiving-officers-and-data-protection-officers.html

## Required sign-off checklist

- [ ] DPO approved privacy notice, lawful bases, data inventory, recipients, retention language, and contact details.
- [ ] Legal office approved Terms and Conditions and applicable-law analysis.
- [ ] Records officer mapped every record and backup to an approved retention/disposal schedule.
- [ ] ICT/CISO completed threat model, vulnerability assessment, deployment hardening, backup restore test, and incident-response exercise.
- [ ] System owner completed role/access review and data-classification matrix.
- [ ] Accessibility reviewers completed automated and manual WCAG testing with documented remediation.
- [ ] Procurement/licensing owner documented PATH/DOST logo authorization and all third-party licenses/services.
- [ ] Public-portal owner completed a new PIA, penetration test, content/publication review, and consent reassessment before launch.
