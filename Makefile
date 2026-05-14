DRUPAL_PHPUNIT_ENV = SIMPLETEST_BASE_URL=http://127.0.0.1 SIMPLETEST_DB=pgsql://drupal:drupal@postgres/drupal BROWSERTEST_OUTPUT_BASE_URL=http://127.0.0.1:8080
DOCKER_COMPOSE_EXEC ?= docker compose exec -T

.PHONY: test test-symbol-engine test-drupal prepare-drupal-test check-sensitive-files build-symbol-engine-production drush-cr

test: test-symbol-engine test-drupal

check-sensitive-files:
	./scripts/check-sensitive-files.sh

test-symbol-engine:
	$(DOCKER_COMPOSE_EXEC) symbol-engine npm test

prepare-drupal-test:
	$(DOCKER_COMPOSE_EXEC) drupal sh -lc 'cd /opt/drupal && mkdir -p web/sites/default/files web/sites/simpletest .phpunit.cache && chmod -R 0777 web/sites/default/files web/sites/simpletest .phpunit.cache'

test-drupal: prepare-drupal-test
	$(DOCKER_COMPOSE_EXEC) drupal sh -lc 'cd /opt/drupal && runuser -u www-data -- env $(DRUPAL_PHPUNIT_ENV) vendor/bin/phpunit -c phpunit.xml.dist --group symbol_atomic_swap'

build-symbol-engine-production:
	docker build --target production -t symbol-engine:production-check ./symbol-engine

drush-cr:
	$(DOCKER_COMPOSE_EXEC) drupal sh -lc 'cd /opt/drupal && vendor/bin/drush cr'
