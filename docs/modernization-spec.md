# ManagedIdP Modernization Spec (Option 1)

**Goal**
Modernize the stack to PHP 8.2, Symfony 6.4 LTS, and SimpleSAMLphp 2.x, with production-grade containers and a clean separation between the management API and SimpleSAMLphp runtime within the same PHP-FPM container. SimpleSAMLphp stable requires PHP >= 8.1.0, Symfony 6.4 requires PHP >= 8.1, and Symfony 7 requires PHP >= 8.2.

**Architecture**
1. `nginx` reverse proxy terminates TLS and routes requests to `app`.
2. `app` service runs Symfony 6.4 (management UI + API + metadata generation) and serves SimpleSAMLphp endpoints.
3. `postgres` stores tenants, users, metadata, and operational data.
4. `redis` (optional but recommended) for sessions and queues.

**Services and Responsibilities**
1. `nginx`
2. `app` (Symfony 6.4 LTS + SimpleSAMLphp 2.x)
3. `postgres`
4. `redis`

**Container Build Targets**
1. `app` base image: `php:8.2-fpm`
2. `nginx` base image: `nginx:1.25-alpine`
3. `postgres` base image: `postgres:16-alpine`
4. `redis` base image: `redis:7-alpine`

**SimpleSAMLphp Runtime Requirements**
1. PHP >= 8.1.0
2. Required PHP extensions: `dom`, `fileinfo`, `filter`, `hash`, `json`, `libxml`, `mbstring`, `openssl`, `pcre`, `session`, `simplexml`, `sodium`, `SPL`, `zlib`
3. `module.enable` must be used to enable modules in 2.x (no `enable` files).

**Data Flow**
1. `app` writes tenant-specific metadata configuration to `conf/simplesamlphp/metadata/`.
2. SimpleSAMLphp reads configuration from `SIMPLESAMLPHP_CONFIG_DIR`.
3. Nginx routes:
4. `/` and `/admin` -> `app`
5. `/simplesaml/` -> `app` (SimpleSAMLphp endpoints)
6. `/saml2/idp/*` -> `app` (SAML IdP endpoints)

**Configuration Layout**
1. `conf/simplesamlphp/` mounted to `/var/www/conf/simplesamlphp` inside `app`.
2. `conf/simplesamlphp/config.php`
3. `conf/simplesamlphp/authsources.php`
4. `conf/simplesamlphp/metadata/saml20-idp-hosted.php`
5. `conf/simplesamlphp/metadata/saml20-sp-remote.php`
6. `certs/` mounted to `app` and `nginx` (read-only in nginx).
7. `/var/www/app/var` mounted for Symfony cache/logs.

**Security Requirements**
1. All secrets provided via environment variables or a secrets manager.
2. TLS termination at `nginx`.
3. `TRUSTED_PROXIES` set for the load balancer.
4. Non-root users in all containers.

**Migration Plan**
1. Upgrade Symfony from 3.4 to 6.4 LTS.
2. Upgrade all PHP libraries to PHP 8.2 compatible versions.
3. Replace deprecated Symfony APIs and bundle integrations.
4. Upgrade SimpleSAMLphp to 2.x and replace `enable` files with `module.enable`.
5. Validate metadata generation and SSO flows.

**Acceptance Criteria**
1. `/health` returns HTTP 200.
2. `https://<tenant>.<base>/saml2/idp/metadata.php` returns valid XML.
3. `https://attributes.<base>/simplesaml/module.php/saml/sp/metadata.php/default-sp` returns valid XML.
4. SP-initiated login shows IdP login and returns attributes.
