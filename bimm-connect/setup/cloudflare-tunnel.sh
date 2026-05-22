#!/usr/bin/env bash
# Run after docker-setup.sh. Sets up the Cloudflare Tunnel for a public staging URL.
# Prerequisites: cloudflared installed, Cloudflare account with your domain.
set -euo pipefail

TUNNEL_NAME="bimm-dev"
DOMAIN="bimm-dev.yourdomain.com"   # <-- replace with your actual domain

echo "==> Logging in to Cloudflare (opens browser)"
cloudflared tunnel login

echo "==> Creating tunnel: $TUNNEL_NAME"
cloudflared tunnel create "$TUNNEL_NAME"

TUNNEL_ID=$(cloudflared tunnel list --output json | python3 -c "
import json,sys
tunnels = json.load(sys.stdin)
print(next(t['id'] for t in tunnels if t['name'] == '$TUNNEL_NAME'))
")

echo "==> Routing DNS $DOMAIN -> $TUNNEL_NAME"
cloudflared tunnel route dns "$TUNNEL_NAME" "$DOMAIN"

CONFIG_FILE="$HOME/.cloudflared/config.yml"
cat > "$CONFIG_FILE" <<EOF
tunnel: $TUNNEL_ID
credentials-file: $HOME/.cloudflared/$TUNNEL_ID.json
ingress:
  - hostname: $DOMAIN
    service: https://magento.test
    originRequest:
      noTLSVerify: true
  - service: http_status:404
EOF

echo "==> Config written to $CONFIG_FILE"
echo "==> Starting tunnel (keep this terminal open)"
echo "    Once running, set Magento base URL:"
echo "    cd ~/bimm-magento-dev && bin/magento config:set web/secure/base_url https://$DOMAIN/"
echo "    bin/magento config:set web/unsecure/base_url https://$DOMAIN/"
echo "    bin/magento cache:flush"
echo ""
cloudflared tunnel run "$TUNNEL_NAME"
