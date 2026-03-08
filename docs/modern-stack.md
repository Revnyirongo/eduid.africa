# Modern Single-Stack (Scaffold)

This is a **single modern stack** scaffold using:
1. `nginx` for TLS termination and routing
2. `php-fpm` for the Symfony app (also serves SimpleSAMLphp endpoints)
3. `postgres`
4. `redis`

The Symfony 6.4 upgrade lives under `app-modern/` and is the target for the modern stack build.

## Files Added
- `docker-compose.modern.yml`
- `docker/modern/nginx.conf`
- `docker/modern/app/Dockerfile`
- `docker/modern/app/php.ini`
- `docs/modernization-spec.md`

## Usage
1. Build and start:
   ```bash
   docker compose -f docker-compose.modern.yml up -d --build
   ```
2. Access:
   - App: `https://<host>/`
   - SimpleSAMLphp: `https://<host>/simplesaml/`

## Required Next Steps
1. Verify SP-initiated SSO and ACS flows with a test SP.
2. Replace demo credentials and seed values before production.
3. Configure real TLS certificates for each tenant hostname.
