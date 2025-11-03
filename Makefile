.PHONY: test test-sqlite test-mysql test-all build build-mysql up down

# Service and tooling
DOCKER_COMPOSE ?= docker compose
APP_SERVICE ?= app
APP_SERVICE_MYSQL ?= app-mysql

# Usage:
#   make test                   # run full suite on SQLite (default)
#   make test-sqlite            # explicit SQLite run
#   make test-mysql             # run suite on MySQL service
#   make test-all               # run both SQLite and MySQL suites
#   make build                  # build app image
#   make up                     # start backing services (redis, mysql)
#   make down                   # stop all services

test:
	$(DOCKER_COMPOSE) run --rm $(APP_SERVICE) vendor/bin/phpunit --no-coverage $(ARGS)

test-sqlite:
	$(DOCKER_COMPOSE) run --rm $(APP_SERVICE) vendor/bin/phpunit --no-coverage $(ARGS)

test-mysql:
	$(DOCKER_COMPOSE) run --rm $(APP_SERVICE_MYSQL) vendor/bin/phpunit --no-coverage $(ARGS)

test-all:
	$(DOCKER_COMPOSE) run --rm $(APP_SERVICE) vendor/bin/phpunit --no-coverage $(ARGS)
	$(DOCKER_COMPOSE) run --rm $(APP_SERVICE_MYSQL) vendor/bin/phpunit --no-coverage $(ARGS)

build:
	$(DOCKER_COMPOSE) build $(APP_SERVICE)

up:
	$(DOCKER_COMPOSE) up -d redis mysql

down:
	$(DOCKER_COMPOSE) down -v
