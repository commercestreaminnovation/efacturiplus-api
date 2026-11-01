#!/bin/bash
# Ruleaza testul comun pe toate clientii, doar pe API-ul local. Vezi tests/README.md.
# Cere: EFP_KEY (cheia firmei de test), PHP_BIN, GO_BIN, DOTNET_BIN, RUBY_BIN (optionale).
cd "$(dirname "$0")"
export EFP_URL=${EFP_URL:-http://127.0.0.1:8811/api/parteneri/v1} EFP_MOCK=http://127.0.0.1:8899 EFP_RUN=$(date +%s)
case "$EFP_URL" in http://127.0.0.1*|http://localhost*) ;; *) echo "doar local"; exit 2;; esac
PHP_BIN=${PHP_BIN:-php}; GO_BIN=${GO_BIN:-go}; DOTNET_BIN=${DOTNET_BIN:-dotnet}; RUBY_BIN=${RUBY_BIN:-ruby}
start() { python3 mock_server.py 8899 & M=$!; sleep 0.5; }
stop() { kill $M 2>/dev/null; wait $M 2>/dev/null; }
t() { start; printf '%-12s' "$1"; shift; "$@" 2>&1 | tail -4; stop; }
OUT=$(mktemp -d)
t php "$PHP_BIN" smoke.php
t javascript node smoke.mjs
t python python3 smoke.py
t ruby "$RUBY_BIN" smoke.rb
t go bash -c "cd smoke-go && $GO_BIN run ."
t csharp bash -c "cd smoke-cs && $DOTNET_BIN run -v q --nologo"
t java bash -c "javac -encoding UTF-8 -d $OUT ../sdks/java/src/ro/efacturiplus/EFacturiPlus.java smoke-java/Smoke.java && java -Dfile.encoding=UTF-8 -cp $OUT Smoke"
