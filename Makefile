PROJECT_ROOT := $(shell cd ../../.. && pwd)
PLUGIN_DIR := $(shell pwd)
VENDOR_BIN := $(PROJECT_ROOT)/vendor/bin

.PHONY: test analyse baseline rector-dry rector phpmd pint lint all

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
	cd $(PROJECT_ROOT) && $(VENDOR_BIN)/phpmd $(PLUGIN_DIR)/classes,$(PLUGIN_DIR)/components,$(PLUGIN_DIR)/models,$(PLUGIN_DIR)/Plugin.php text $(PLUGIN_DIR)/phpmd.xml

pint:
	cd $(PROJECT_ROOT) && $(VENDOR_BIN)/pint $(PLUGIN_DIR) --config=$(PLUGIN_DIR)/pint.json

pint-test:
	cd $(PROJECT_ROOT) && $(VENDOR_BIN)/pint $(PLUGIN_DIR) --config=$(PLUGIN_DIR)/pint.json --test

all: pint-test analyse phpmd test
