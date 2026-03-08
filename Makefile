.PHONY: env certs tenant-cert up down logs sh test fmt lint

env:
	@test -f .env || cp .env.example .env

certs:
	./scripts/gen-certs.sh

tenant-cert:
	@if [ -z "$(host)" ]; then \
		echo "Usage: make tenant-cert host=<tenant-host> [extra=\"alt1 alt2\"]" >&2; \
		exit 1; \
	fi
	./scripts/gencerts.sh $(host) $(extra)

up:
	docker compose -f docker-compose.modern.yml up --build

down:
	docker compose -f docker-compose.modern.yml down -v

logs:
	docker compose -f docker-compose.modern.yml logs -f app

sh:
	docker compose -f docker-compose.modern.yml exec app /bin/sh

test:
	docker compose -f docker-compose.modern.yml exec -e APP_ENV=test -e APP_DEBUG=1 -e SYMFONY_DEPRECATIONS_HELPER=weak app ./vendor/bin/simple-phpunit

fmt:
	docker compose -f docker-compose.modern.yml exec app ./vendor/bin/phpcbf

lint:
	docker compose -f docker-compose.modern.yml exec app ./vendor/bin/phpcs
