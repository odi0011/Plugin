# Analytics Snippet

Consent-aware Google Analytics 4 integration for the CMS frontend.

## Contract

- Only a GA4 measurement ID matching `G-[A-Z0-9]{10}` is accepted.
- Settings are declared in `plugin.json`; the core settings UI, public
  `/api/v1/plugin-settings/{slug}` route, and Agent gateway use one validator.
- Basic Consent Mode does not load Google code before consent.
- Advanced Consent Mode initializes storage as denied and updates it when the
  visitor changes the site privacy preference.
- Revoking consent sends a denied update and expires common first-party GA and
  Google Ads linker cookies that are visible to JavaScript.
- Successful inquiry forms and inquiry carts emit a consent-gated GA4
  `generate_lead` event containing only the form/cart channel and whether the
  conversion came from product context; no submitted fields or PII are sent.
- `GET /api/v1/ext/analytics-snippet/status` exposes bounded configuration
  status through the same API and Agent execution path.

Universal Analytics (`UA-*`) and Google Tag Manager (`GTM-*`) identifiers are
intentionally rejected.
