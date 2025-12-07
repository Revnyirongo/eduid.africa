# Keycloak-based IdP stack (multi-tenant)

This provides a clean Keycloak deployment with automatic Let's Encrypt certificates for `auth.eduid.africa` (or whatever hostname you set). Model each IdP tenant as a Keycloak realm; per-tenant SAML endpoints live under `/realms/<realm>/protocol/saml`.

## Prereqs
- DNS: `KC_HOSTNAME` must resolve publicly to this host (HTTP-01 challenge on 80/443).
- Ports: free 80/443 (or temporarily set `PROXY_HTTP_PORT/PROXY_HTTPS_PORT` in `.env.keycloak` but use 80/443 for real LE issuance).
- Docker + docker-compose.

## Quick start
1) Copy env template and edit values:
   ```bash
   cd samlidp_eduid/keycloak
   cp .env.keycloak.example .env.keycloak
   # set KC_HOSTNAME, admin creds, DB creds, LETSENCRYPT_EMAIL
   ```
2) Bring up the stack:
   ```bash
   docker compose -f docker-compose.keycloak.yml --env-file .env.keycloak up -d
   ```
   nginx-proxy will terminate TLS with Let's Encrypt; Keycloak runs on 8080 internally.

3) Access admin console at `https://<KC_HOSTNAME>/` and log in with `KEYCLOAK_ADMIN` / `KEYCLOAK_ADMIN_PASSWORD`.

## Model tenants (realms)
- Create one realm per IdP tenant (e.g., `ubuntunet`, `eduid`).
- Realm SAML endpoints:
  - Metadata: `https://<KC_HOSTNAME>/realms/<realm>/protocol/saml/descriptor`
  - SSO: `https://<KC_HOSTNAME>/realms/<realm>/protocol/saml`
- Upload/import existing tenant signing certs if you have them, or let Keycloak generate new keys per realm.
- Create clients for each SP (set ACS/EntityID, upload SP certs) and add attribute mappers.
- Import users into each realm (CSV/admin API). If you have hashes, use user import with temporary password+reset or a custom SPI.

## Notes for migration from current Symfony/SSP app
- Existing DB has tenants (IdPs) keyed by hostname; map each hostname to a realm.
- Users: export per-IdP users and import into the matching realm.
- SPs: for each SP entry, create a Keycloak client in that realm with the SP's ACS/EntityID and cert.
- Themes: drop a theme directory into `/opt/keycloak/themes` via a volume if you want to match the current UI (not included here).

## Backups
- Postgres data is in the `kc-data` volume. Use `pg_dump` for regular backups.

## Optional: run on non-80/443 for testing
- Set `PROXY_HTTP_PORT` / `PROXY_HTTPS_PORT` in `.env.keycloak` to avoid port conflicts, but Let's Encrypt requires 80/443 reachable for real certs.
