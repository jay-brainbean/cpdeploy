#!/bin/sh
# Fake npm for cpdeploy scenario tests (plan §16.2).
#   CPD_FAKE_NPM=fail  → the build fails
#   CPD_FAKE_NPM=oom   → the build runs out of memory (V8 heap message)
case "$1" in
  ci|install)
    mkdir -p node_modules/.bin
    echo "added 120 packages in 2s"
    ;;
  run)
    if [ "$CPD_FAKE_NPM" = "fail" ]; then
      echo "vite v6.0.0 building for production..."
      echo "error during build: [vite]: Rollup failed to resolve import \"missing\""
      exit 1
    fi
    if [ "$CPD_FAKE_NPM" = "oom" ]; then
      echo "FATAL ERROR: Reached heap limit Allocation failed - JavaScript heap out of memory"
      exit 134
    fi
    mkdir -p public/build
    echo '{"resources/js/app.js":{"file":"assets/app.js"}}' > public/build/manifest.json
    if [ -e .env ]; then envfile=yes; else envfile=no; fi
    echo "node=$(node -v) env=$envfile options=$NODE_OPTIONS nodeenv=${NODE_ENV:-unset} ci=${CI:-unset}" > public/build/info.txt
    echo "built in 1.2s"
    ;;
  -v|--version)
    echo "10.9.0"
    ;;
esac
