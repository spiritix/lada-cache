.PHONY: test test-sqlite test-mysql test-pgsql test-matrix fuzz concurrency build down

DOCKER_COMPOSE ?= docker compose
RUN = $(DOCKER_COMPOSE) run --rm -T --user "$(shell id -u):$(shell id -g)"
PHPUNIT = vendor/bin/phpunit --no-coverage --do-not-cache-result
ARGS ?=

# Usage:
#   make test                          full suite on SQLite with phpredis
#   make test-mysql / test-pgsql       full suite on MySQL / PostgreSQL
#   make test-matrix                   every database with every Redis client
#   make fuzz LADA_FUZZ_ITERATIONS=5000 [LADA_FUZZ_SEED=123]
#   make concurrency                   multi-process suite on MySQL and PostgreSQL
#   ARGS="--filter Foo" narrows any target

test: test-sqlite

test-sqlite:
	$(RUN) -e DB_CONNECTION=sqlite app $(PHPUNIT) $(ARGS)

test-mysql:
	$(RUN) -e DB_CONNECTION=mysql -e DB_HOST=mysql app $(PHPUNIT) $(ARGS)

test-pgsql:
	$(RUN) -e DB_CONNECTION=pgsql -e DB_HOST=postgres app $(PHPUNIT) $(ARGS)

test-matrix:
	@set -e; for client in phpredis predis; do \
		for target in "sqlite:" "mysql:mysql" "pgsql:postgres"; do \
			driver=$${target%%:*}; host=$${target#*:}; \
			echo "== $$driver / $$client"; \
			$(RUN) -e DB_CONNECTION="$$driver" -e DB_HOST="$$host" -e REDIS_CLIENT="$$client" app $(PHPUNIT) $(ARGS); \
		done; \
	done

fuzz:
	@set -e; for target in "sqlite:" "mysql:mysql" "pgsql:postgres"; do \
		driver=$${target%%:*}; host=$${target#*:}; \
		echo "== fuzz $$driver"; \
		$(RUN) -e DB_CONNECTION="$$driver" -e DB_HOST="$$host" app $(PHPUNIT) --testsuite Fuzz $(ARGS); \
	done

concurrency:
	@set -e; for target in "mysql:mysql" "pgsql:postgres"; do \
		driver=$${target%%:*}; host=$${target#*:}; \
		echo "== concurrency $$driver"; \
		$(RUN) -e DB_CONNECTION="$$driver" -e DB_HOST="$$host" app $(PHPUNIT) --testsuite Concurrency $(ARGS); \
	done

build:
	$(DOCKER_COMPOSE) build app

down:
	$(DOCKER_COMPOSE) down -v
