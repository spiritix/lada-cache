.PHONY: test

# Service and tooling
DOCKER_COMPOSE ?= docker compose
APP_SERVICE ?= app

# Usage:
#   make test                   # run full suite
#   make test ARGS="--filter SomeTest"  # pass extra phpunit args

test:
	$(DOCKER_COMPOSE) run --rm $(APP_SERVICE) vendor/bin/phpunit --no-coverage $(ARGS)
