DRUPAL_PHPUNIT_ENV = SIMPLETEST_BASE_URL=http://127.0.0.1 SIMPLETEST_DB=pgsql://drupal:drupal@postgres/drupal BROWSERTEST_OUTPUT_BASE_URL=http://127.0.0.1:8080
DOCKER_COMPOSE_EXEC ?= docker compose exec -T

.PHONY: test test-symbol-engine test-drupal prepare-drupal-test check-sensitive-files check-repository-hygiene check-node-dependency-policy audit-drupal-dependencies build-symbol-engine-production smoke-symbol-engine-production drush-cr

test: test-symbol-engine test-drupal

check-sensitive-files:
	./scripts/check-sensitive-files.sh

check-repository-hygiene:
	./scripts/check-repository-hygiene.sh

check-node-dependency-policy:
	./scripts/check-node-dependency-policy.js

audit-drupal-dependencies:
	@set -eu; \
	for attempt in 1 2 3; do \
		if docker run --rm -v "$(PWD)/drupal:/app" -w /app composer:2@sha256:02062f7719ec9433a9d4256cfba1c792db96dc9db60a4a92e48264c9e166b877 composer audit --locked --no-interaction; then \
			exit 0; \
		fi; \
		if [ "$$attempt" -eq 3 ]; then \
			exit 1; \
		fi; \
		sleep 5; \
	done

test-symbol-engine:
	$(DOCKER_COMPOSE_EXEC) symbol-engine npm test

prepare-drupal-test:
	$(DOCKER_COMPOSE_EXEC) drupal sh -lc 'cd /opt/drupal && mkdir -p web/sites/default/files web/sites/simpletest .phpunit.cache && chmod -R 0777 web/sites/default/files web/sites/simpletest .phpunit.cache'

test-drupal: prepare-drupal-test
	$(DOCKER_COMPOSE_EXEC) drupal sh -lc 'cd /opt/drupal && runuser -u www-data -- env $(DRUPAL_PHPUNIT_ENV) vendor/bin/phpunit -c phpunit.xml.dist --group symbol_atomic_swap'

build-symbol-engine-production:
	docker build --target production -t symbol-engine:production-check ./symbol-engine

smoke-symbol-engine-production:
	@set -eu; \
	project=symbol_engine_prod_smoke; \
	compose="docker compose -p $$project -f docker-compose.production-smoke.yml"; \
	cleanup() { $$compose down -v; }; \
	trap cleanup EXIT; \
	$$compose up -d --build --wait

drush-cr:
	$(DOCKER_COMPOSE_EXEC) drupal sh -lc 'cd /opt/drupal && vendor/bin/drush cr'
