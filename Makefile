COMPOSE ?= docker compose

.PHONY: setup up down check test test-coverage benchmark keys logs

setup:
	$(COMPOSE) build backend
	$(COMPOSE) up -d --wait mysql_db redis
	$(COMPOSE) run --rm backend composer install --no-interaction --prefer-dist
	$(COMPOSE) run --rm backend php bin/console lexik:jwt:generate-keypair --skip-if-exists
	$(COMPOSE) run --rm backend php bin/console doctrine:migrations:migrate --no-interaction
	$(COMPOSE) up -d --wait backend nginx

up:
	$(COMPOSE) up -d --wait mysql_db redis backend nginx

down:
	$(COMPOSE) --profile test down

keys:
	$(COMPOSE) exec -T backend php bin/console lexik:jwt:generate-keypair --skip-if-exists

check:
	$(COMPOSE) exec -T backend composer check

test:
	$(COMPOSE) --profile test up -d --wait mysql_test redis_test
	$(COMPOSE) exec -T backend composer test

test-coverage:
	$(COMPOSE) --profile test up -d --wait mysql_test redis_test
	$(COMPOSE) exec -T backend composer test:coverage

logs:
	$(COMPOSE) logs --tail=100 backend nginx

benchmark:
	$(COMPOSE) --profile test up -d --wait mysql_test redis_test
	$(COMPOSE) exec -T backend composer test:prepare
	$(COMPOSE) exec -T backend php tests/benchmark-tasks.php --output=var/benchmarks/cache.json
