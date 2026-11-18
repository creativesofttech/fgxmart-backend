# Legal page content

The two public pages are `/terms-and-conditions` and `/privacy-policy`. Their editors are under Core Settings in the unified admin panel. Each editor contains a page title and the same Tiptap rich text editor used for products.

## JSON files

Bundled starter documents are committed at:

- `resources/content/legal/terms-and-conditions.json`
- `resources/content/legal/privacy-policy.json`

On the first read, these documents are used directly. Saving in the admin panel writes an override to:

- `storage/app/private/legal-pages/terms-and-conditions.json`
- `storage/app/private/legal-pages/privacy-policy.json`

The override takes precedence over the bundled file. Files contain `title`, HTML `content` and `updated_at` (null for starter documents). You may edit JSON directly with a text editor; keep it valid JSON. If an override already exists, editing only the bundled file will not change the published page. Reads sanitize HTML from both sources. Corrupt saved JSON returns an unavailable response rather than silently reverting to old content. Admin saves replace the file atomically.

Keep `storage/app/private/legal-pages` on persistent writable storage across deployments and include it in backups. Overrides are intentionally ignored by Git through the existing private-storage ignore rule. The `legal-pages.path` and `legal-pages.defaults_path` configuration values control the directories.

## Access and deployment

Public access is read-only through `GET /api/v1/legal-pages/{page}`. Only the two fixed slugs are accepted. Admin reads and writes pass through the Nuxt HttpOnly-session proxy to the protected Laravel endpoints. `legal-pages.index` grants viewing and `legal-pages.edit` grants saving. Super Admin has access automatically; grant these permissions explicitly to other roles as needed.

Run `php artisan migrate` when deploying to install the two permission records. The permission seeder also includes the module for fresh installations. The pages do not require a seeder or database content table.

HTML uses the existing editor's paragraph, H2/H3, list and inline formatting, with supported block font sizes. Scripts, event attributes, embeds and unsupported styles are removed before saving or serving content. Title and nonempty content are validated.

## Starter wording

The starter text describes the implemented catalogue, cart, PDF/WhatsApp enquiry workflow, accounts and reviews. The privacy text also identifies browser storage and the configured Google Analytics integration. It does not invent a delivery guarantee, fixed returns period, legal entity address or governing jurisdiction. Review the wording against the company's actual identity, contact details, retention practices, service providers and applicable requirements before production use.

Privacy-notice reference: https://ico.org.uk/for-organisations/uk-gdpr-guidance-and-resources/individual-rights/the-right-to-be-informed/what-privacy-information-should-we-provide/
