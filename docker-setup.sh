#!/bin/bash

echo "Setting up Matrikkel Docker environment..."

# Check if .env.local exists, if not create it
if [ ! -f .env.local ]; then
    echo "Creating .env.local file..."
    if [ -f .env.example ]; then
        cp .env.example .env.local
        echo "Created .env.local from .env.example template"
    else
        cat > .env.local << EOF
# Local environment overrides
APP_ENV=dev
APP_DEBUG=1

# Add your Matrikkel API credentials here:
# MATRIKKELAPI_LOGIN=your_actual_login
# MATRIKKELAPI_PASSWORD=your_actual_password
# MATRIKKELAPI_ENVIRONMENT=test
EOF
        echo "Created .env.local with basic template"
    fi
    echo "⚠️  Please edit .env.local with your actual API credentials before using the application"
fi

# Build and start the containers
echo "Building Docker containers..."
docker-compose build

echo "Starting containers..."
docker-compose up -d

echo "Waiting for containers to be ready..."
sleep 10

# Check if the application is running
if curl -s http://localhost:8083 > /dev/null; then
    echo "✅ Application is running at http://localhost:8083"
else
    echo "❌ Application may not be ready yet. Check logs with: docker-compose logs"
fi

echo "To view logs: docker-compose logs -f"
echo "To stop: docker-compose down"