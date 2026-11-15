# Mirrors the composer scripts; run "composer list" for the descriptions.
.PHONY: install lint lint-fix stan rector taint test coverage types mutate arch schema audit deps demo demo-check phar phar-check check

install:
	composer install

lint:
	composer lint

lint-fix:
	composer lint:fix

stan:
	composer stan

rector:
	composer rector

taint:
	composer taint

test:
	composer test

coverage:
	composer coverage

types:
	composer types

mutate:
	composer mutate

arch:
	composer arch

schema:
	composer schema

audit:
	composer audit

deps:
	composer deps

demo:
	composer demo

demo-check:
	composer demo:check

phar:
	composer phar

phar-check:
	composer phar:check

check:
	composer check
