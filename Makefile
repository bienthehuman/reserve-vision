# Shortcuts for the dev stack. Everything PHP runs inside the `app` container.
.PHONY: setup up down logs shell migrate places seed fresh test lint analyse check vitest types e2e

EXEC = docker compose exec -T app

setup: ## From a clean clone to a running stack (idempotent)
	bin/setup

up:
	docker compose up -d --wait

down:
	docker compose down

logs:
	docker compose logs -f --tail=100

shell:
	docker compose exec app bash

migrate: ## Migrations always run as the `migrator` DB user
	$(EXEC) composer migrate

places: ## Import the PSGC provinces, cities and barangays (idempotent)
	$(EXEC) php artisan places:import

seed:
	$(EXEC) php artisan db:seed --force

fresh: ## Drop all tables, migrate and seed the dev schema
	$(EXEC) php artisan migrate:fresh --database=mysql_migrator --force
	$(EXEC) php artisan places:import
	$(EXEC) php artisan db:seed --force

test:
	$(EXEC) composer test

lint:
	$(EXEC) composer lint

analyse:
	$(EXEC) composer analyse

vitest:
	docker compose exec -T ssr npm run test

types:
	docker compose exec -T ssr npm run types

check: lint analyse test vitest types

e2e: ## Runs on the host (needs Node 24 + `npx playwright install chromium`)
	npm run e2e
