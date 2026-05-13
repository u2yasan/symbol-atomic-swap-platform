DRUPAL_PHPUNIT_ENV = SIMPLETEST_BASE_URL=http://127.0.0.1 SIMPLETEST_DB=pgsql://drupal:drupal@postgres/drupal BROWSERTEST_OUTPUT_BASE_URL=http://127.0.0.1:8080
DOCKER_COMPOSE_EXEC ?= docker compose exec -T

.PHONY: test test-symbol-engine test-drupal drush-cr

test: test-symbol-engine test-drupal

test-symbol-engine:
	$(DOCKER_COMPOSE_EXEC) symbol-engine npm test

test-drupal:
	$(DOCKER_COMPOSE_EXEC) drupal sh -lc 'cd /opt/drupal && $(DRUPAL_PHPUNIT_ENV) vendor/bin/phpunit -c phpunit.xml.dist --group symbol_atomic_swap'

drush-cr:
	$(DOCKER_COMPOSE_EXEC) drupal sh -lc 'cd /opt/drupal && vendor/bin/drush cr'
