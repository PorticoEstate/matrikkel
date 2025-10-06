#!/bin/bash
# Development helper script - COMPLETE CACHE DISABLE

echo "🧹 Clearing ALL Symfony caches..."
docker compose exec app php bin/console cache:clear --env=dev --no-warmup

echo "🔥 Removing ALL compiled container cache..."
docker compose exec app rm -rf var/cache/dev/ 2>/dev/null || true

echo "🗑️  Clearing additional cache files..."
docker compose exec app rm -rf var/cache/* 2>/dev/null || true

echo "💾 Clearing OPcache (if enabled)..."
docker compose exec app php -r "if (function_exists('opcache_reset')) opcache_reset();"

echo "🔄 Restarting container for clean state..."
docker compose restart app

echo "✅ ALL CACHING COMPLETELY DISABLED! Ready for development and debugging."