PROJECT_ROOT := $(shell cd ../../.. && pwd)
PLUGIN_DIR := $(shell pwd)
VENDOR_BIN := $(PROJECT_ROOT)/vendor/bin

.PHONY: test analyse baseline rector-dry rector phpmd pint pint-test lint-settings-accessor all

test:
	cd $(PROJECT_ROOT) && $(VENDOR_BIN)/pest --configuration $(PLUGIN_DIR)/phpunit.xml

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
