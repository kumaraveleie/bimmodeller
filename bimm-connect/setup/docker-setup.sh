#!/usr/bin/env bash
# Run this inside WSL2 (Ubuntu) on Windows.
# Prerequisites: Docker Desktop with WSL2 backend enabled, Magento Marketplace credentials.
set -euo pipefail

MAGENTO_VERSION="2.4.7-p3"
PROJECT_DIR="$HOME/bimm-magento-dev"
MODULE_SRC="$(cd "$(dirname "$0")/.." && pwd)"   # absolute path to bimm-connect/

echo "==> Creating Magento $MAGENTO_VERSION project at $PROJECT_DIR"
mkdir -p "$PROJECT_DIR"
cd "$PROJECT_DIR"

# markshust one-line setup (prompts for Magento Marketplace credentials)
curl -s https://raw.githubusercontent.com/markshust/docker-magento/main/lib/onelinesetup \
    | bash -s -- magento.test community "$MAGENTO_VERSION"

echo "==> Mounting BIMM_Connect module"
mkdir -p src/app/code/BIMM
ln -sfn "$MODULE_SRC" src/app/code/BIMM/Connect

echo "==> Enabling module and running setup"
bin/magento module:enable BIMM_Connect
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush

echo ""
echo "==> Done. Magento is running at https://magento.test"
echo "    Test the health endpoint:"
echo "    curl -sk https://magento.test/rest/V1/bimm/health"
