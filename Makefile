.PHONY: test test-sqlite test-mysql test-mariadb test-pgsql test-matrix fuzz concurrency rollout bench deps legacy-deps lint analyse format build down

DOCKER_COMPOSE ?= docker compose
RUN = $(DOCKER_COMPOSE) run --rm -T --user "$(shell id -u):$(shell id -g)"
PHPUNIT = vendor/bin/phpunit --no-coverage --do-not-cache-result
COMPOSER_INSTALL = composer install --no-interaction --prefer-dist --no-progress
REDIS_CLIENT ?= phpredis
ARGS ?=

DRIVERS = sqlite mysql mariadb pgsql
CLIENTS = phpredis predis

DB_sqlite = -e DB_CONNECTION=sqlite
DB_mysql = -e DB_CONNECTION=mysql -e DB_HOST=mysql -e DB_PORT=3306
DB_mariadb = -e DB_CONNECTION=mariadb -e DB_HOST=mariadb -e DB_PORT=3306
DB_pgsql = -e DB_CONNECTION=pgsql -e DB_HOST=postgres -e DB_PORT=5432

define suite
	@echo "== $(1) / $(2) $(3)"
	$(RUN) $(DB_$(1)) -e REDIS_CLIENT=$(2) app $(PHPUNIT) $(3) $(ARGS)

endef

test: test-sqlite

test-sqlite test-mysql test-mariadb test-pgsql: test-%: deps
	$(call suite,$*,$(REDIS_CLIENT),)

test-matrix: deps
	$(foreach client,$(CLIENTS),$(foreach driver,$(DRIVERS),$(call suite,$(driver),$(client),)))

fuzz: deps
	$(foreach driver,sqlite mysql pgsql,$(call suite,$(driver),$(REDIS_CLIENT),--testsuite Fuzz))

concurrency: deps
	$(foreach client,$(CLIENTS),$(foreach driver,mysql pgsql,$(call suite,$(driver),$(client),--testsuite Concurrency)))

rollout: deps legacy-deps
	$(foreach client,$(CLIENTS),$(foreach driver,mysql pgsql,$(call suite,$(driver),$(client),--testsuite Rollout)))

bench: deps
	$(call suite,mysql,$(REDIS_CLIENT),tests/Benchmark)

deps:
	$(RUN) --no-deps app $(COMPOSER_INSTALL)

legacy-deps:
	$(RUN) --no-deps app $(COMPOSER_INSTALL) -d tests/Rollout/legacy

lint: deps
	$(RUN) --no-deps app vendor/bin/pint --test

analyse: deps
	$(RUN) --no-deps app vendor/bin/phpstan analyse --memory-limit=1G

format: deps
	$(RUN) --no-deps app vendor/bin/pint

build:
	$(DOCKER_COMPOSE) build app

down:
	$(DOCKER_COMPOSE) down -v
