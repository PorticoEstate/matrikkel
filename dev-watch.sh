#!/bin/bash
# Development watch mode - clears cache when files change

echo "🔍 Starting development watch mode..."
echo "This will automatically clear cache when PHP files change."

# Install inotify-tools if not present
if ! command -v inotifywait &> /dev/null; then
    echo "Installing inotify-tools..."
    sudo apt-get install -y inotify-tools
fi

# Watch for changes in src/ and config/ directories
while inotifywait -r -e modify,create,delete src/ config/ 2>/dev/null; do
    echo "📝 Files changed, clearing cache..."
    docker compose exec app php bin/console cache:clear --env=dev --no-warmup
    echo "✅ Cache cleared at $(date)"
done