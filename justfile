composer := "composer"

phpunit := "vendor/bin/phpunit --no-coverage --do-not-cache-result"

# Host ports of the database services. They are not the container ports, so that a database
# already running on the machine does not clash with them; see docker-compose.yml.
mysql_port := "13306"
pgsql_port := "15432"
redis_port := "16379"

# The environment every suite runs with. DB_HOST reaches the services through the published
# host ports; the container names in docker-compose.yml are only used by the services themselves.
run_db := "DB_HOST=127.0.0.1 REDIS_HOST=127.0.0.1 REDIS_PORT=" + redis_port

# Print the exports a shell needs to run the suite by hand.
env:
    @echo 'export DB_HOST=127.0.0.1 REDIS_PORT={{redis_port}}'
    @echo 'export DB_PORT={{mysql_port}} DB_CONNECTION=mysql   # or DB_PORT={{pgsql_port}} DB_CONNECTION=pgsql'

# Run the whole suite on SQLite with phpredis, the default target.
default: test

# Install the PHP dependencies from the lock file.
deps:
    {{composer}} install --no-interaction --prefer-dist --no-progress

# Start Redis, MySQL and PostgreSQL and wait until they accept connections.
services:
    docker compose up -d --wait

# Stop the database services and delete their volumes.
down:
    docker compose down -v

# Run the suite on SQLite. Flags go to PHPUnit: `just test --filter=RowKeysTest`.
test *args: deps services
    {{run_db}} {{phpunit}} {{args}}

# Run the suite on MySQL.
test-mysql *args: deps services
    {{run_db}} DB_CONNECTION=mysql DB_PORT={{mysql_port}} {{phpunit}} {{args}}

# Run the suite on PostgreSQL.
test-pgsql *args: deps services
    {{run_db}} DB_CONNECTION=pgsql DB_PORT={{pgsql_port}} {{phpunit}} {{args}}

# Run every database with both Redis clients.
test-matrix *args: deps services
    #!/usr/bin/env bash
    set -euo pipefail
    for client in phpredis predis; do
        for target in "sqlite:{{redis_port}}" "mysql:{{mysql_port}}" "pgsql:{{pgsql_port}}"; do
            driver="${target%%:*}"
            port="${target#*:}"
            echo "== $driver / $client"
            DB_CONNECTION="$driver" DB_HOST=127.0.0.1 DB_PORT="$port" REDIS_HOST=127.0.0.1 \
                REDIS_PORT={{redis_port}} REDIS_CLIENT="$client" {{phpunit}} {{args}}
        done
    done

# Run the differential fuzz suite on every database, e.g. `just fuzz` with LADA_FUZZ_ITERATIONS set.
fuzz *args: deps services
    #!/usr/bin/env bash
    set -euo pipefail
    for target in "sqlite:{{redis_port}}" "mysql:{{mysql_port}}" "pgsql:{{pgsql_port}}"; do
        driver="${target%%:*}"
        port="${target#*:}"
        echo "== fuzz $driver"
        DB_CONNECTION="$driver" DB_HOST=127.0.0.1 DB_PORT="$port" REDIS_HOST=127.0.0.1 \
            {{phpunit}} --testsuite Fuzz {{args}}
    done

# Run the multi-process suite, like several pods sharing one database and Redis.
concurrency *args: deps services
    #!/usr/bin/env bash
    set -euo pipefail
    for target in "mysql:{{mysql_port}}" "pgsql:{{pgsql_port}}"; do
        driver="${target%%:*}"
        port="${target#*:}"
        echo "== concurrency $driver"
        DB_CONNECTION="$driver" DB_HOST=127.0.0.1 DB_PORT="$port" REDIS_HOST=127.0.0.1 \
            {{phpunit}} --testsuite Concurrency {{args}}
    done

# Report cached versus uncached read throughput and what a cached read costs in Redis commands.
bench: deps services
    {{run_db}} DB_CONNECTION=mysql DB_PORT={{mysql_port}} {{phpunit}} tests/Benchmark

# Run the code formatter over src, which rewrites files.
format:
    vendor/bin/pint src

# Check the code formatter without changing anything.
lint:
    vendor/bin/pint --test src

# Run PHPStan over src.
analyse:
    vendor/bin/phpstan analyse --memory-limit=1G
