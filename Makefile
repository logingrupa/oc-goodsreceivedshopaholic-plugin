PROJECT_ROOT := $(shell cd ../../.. && pwd)
PLUGIN_DIR := $(shell pwd)
VENDOR_BIN := $(PROJECT_ROOT)/vendor/bin

.PHONY: test analyse baseline rector-dry rector phpmd pint pint-test lint-settings-accessor coverage all

test:
	cd $(PROJECT_ROOT) && $(VENDOR_BIN)/pest --configuration $(PLUGIN_DIR)/phpunit.xml

# OPS-05 (plan 05-04) — coverage gate.
#
# Threshold pinned at --min=75 per D-13 pragmatic guidance + D-05-04-02:
#   - Driver was UNAVAILABLE at plan-execution time on this host
#     (neither pcov nor xdebug loaded; verified `php -m | grep -iE pcov\|xdebug`
#     returned no rows). Baseline coverage % could NOT be measured this run, so
#     --min=75 is the best-guess floor from D-13 ("if measured 78%, set --min=75").
#   - Operator MUST install a coverage driver, re-measure, and adjust this --min
#     to (measured floor rounded DOWN to nearest 5) before relying on the gate.
#
# Driver install (operator action — requires sudo):
#   sudo pecl install pcov
#   sudo bash -c "echo 'extension=pcov.so' > /etc/php/8.3/cli/conf.d/20-pcov.ini"
#   php -m | grep -i pcov   # confirm loaded
#   make coverage            # measure actual %
#   # Then edit the --min=75 below to the measured floor (round DOWN to nearest 5)
#
# Driver choice: PCOV preferred (2-3x slowdown vs Xdebug 5-10x; PCOV is read-only
# instrumentation purpose-built for coverage). If only Xdebug is available,
# additionally set `xdebug.mode=coverage` in /etc/php/8.3/cli/conf.d/20-xdebug.ini.
#
# NOT wired into `make all` — see D-11 separate-target pattern: dev iteration
# `make test` stays fast, and the gate avoids hard-failing CI/local runs on the
# driver-availability dimension. Wire `coverage` into the `all:` target only
# AFTER the driver is installed and --min has been re-pinned to the measured
# floor.
coverage:
	cd $(PROJECT_ROOT) && $(VENDOR_BIN)/pest --configuration $(PLUGIN_DIR)/phpunit.xml --coverage --min=75

analyse:
	cd $(PROJECT_ROOT) && $(VENDOR_BIN)/phpstan analyse --configuration=$(PLUGIN_DIR)/phpstan.neon

baseline:
	cd $(PROJECT_ROOT) && $(VENDOR_BIN)/phpstan analyse --configuration=$(PLUGIN_DIR)/phpstan.neon --generate-baseline=$(PLUGIN_DIR)/phpstan-baseline.neon

rector-dry:
	cd $(PROJECT_ROOT) && $(VENDOR_BIN)/rector process --config=$(PLUGIN_DIR)/rector.php --dry-run

rector:
	cd $(PROJECT_ROOT) && $(VENDOR_BIN)/rector process --config=$(PLUGIN_DIR)/rector.php

phpmd:
	cd $(PROJECT_ROOT) && $(VENDOR_BIN)/phpmd $(PLUGIN_DIR)/classes,$(PLUGIN_DIR)/components,$(PLUGIN_DIR)/console,$(PLUGIN_DIR)/controllers,$(PLUGIN_DIR)/models,$(PLUGIN_DIR)/Plugin.php text $(PLUGIN_DIR)/phpmd.xml

pint:
	cd $(PROJECT_ROOT) && $(VENDOR_BIN)/pint $(PLUGIN_DIR) --config=$(PLUGIN_DIR)/pint.json

pint-test:
	cd $(PROJECT_ROOT) && $(VENDOR_BIN)/pint $(PLUGIN_DIR) --config=$(PLUGIN_DIR)/pint.json --test

# QA-09: Settings::get( must appear only in classes/support/SettingsAccessor.php.
# Mirrored by tests/unit/Support/SettingsAccessorIsSoleConsumerOfSettingsGetTest.php
# (defense-in-depth — either gate alone is sufficient; both together survive
# Makefile drift or removed CI steps).
lint-settings-accessor:
	@echo "==> QA-09 grep gate: Settings::get( must appear only in classes/support/SettingsAccessor.php"
	@if grep -rn 'Settings::get(' $(PLUGIN_DIR)/classes $(PLUGIN_DIR)/components $(PLUGIN_DIR)/console $(PLUGIN_DIR)/controllers $(PLUGIN_DIR)/models $(PLUGIN_DIR)/Plugin.php 2>/dev/null | grep -v 'classes/support/SettingsAccessor.php' | grep -q .; then \
		echo "QA-09 VIOLATION: Settings::get( found outside SettingsAccessor.php"; \
		grep -rn 'Settings::get(' $(PLUGIN_DIR)/classes $(PLUGIN_DIR)/components $(PLUGIN_DIR)/console $(PLUGIN_DIR)/controllers $(PLUGIN_DIR)/models $(PLUGIN_DIR)/Plugin.php 2>/dev/null | grep -v 'classes/support/SettingsAccessor.php'; \
		exit 1; \
	fi

all: pint-test lint-settings-accessor analyse phpmd test
