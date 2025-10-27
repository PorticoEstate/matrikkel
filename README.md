# Matrikkel API Client

A Symfony-based client for accessing the MatrikkelAPI from Kartverket (Norwegian Cadastre). This project provides both SOAP API integration and local database import capabilities for Norwegian address and property data.

## 🚀 Quick Start with Docker

The easiest way to get started is using Docker:

```bash
# 1. Clone and navigate to the project
git clone <repository-url>
cd matrikkel

# 2. Set up your API credentials (see Configuration section below)
cp .env.example .env
# Edit .env with your actual Matrikkel API credentials

# 3. Run the setup script
./docker-setup.sh

# 4. Access the application
# Web interface: http://localhost:8083
# Console commands: docker compose exec app php bin/console list
```

## 📋 Requirements

### Docker Setup (Recommended)

- Docker
- Docker Compose

### Manual Setup

- PHP 8.2+
- Required PHP extensions:
  - `ext-soap` (for SOAP API)
  - `ext-sqlite3` (for local database)
  - `ext-pgsql` (for PostgreSQL support)
  - `ext-ctype`
  - `ext-iconv`
  - `ext-zip`
- Composer

## ⚙️ Configuration

### API Credentials

You need valid Matrikkel API credentials from Kartverket. Configure them in `.env`:

```bash
# Matrikkel API Configuration
MATRIKKELAPI_LOGIN=your_actual_login
MATRIKKELAPI_PASSWORD=your_actual_password
MATRIKKELAPI_ENVIRONMENT=prod  # or 'test' for testing
```

### Environment Variables

- `MATRIKKELAPI_LOGIN` - Your Matrikkel API username
- `MATRIKKELAPI_PASSWORD` - Your Matrikkel API password  
- `MATRIKKELAPI_ENVIRONMENT` - API environment (`prod` or `test`)

## 🔧 Installation

### Docker Installation (Recommended)

```bash
# Build and start containers
docker compose up -d

# View logs
docker compose logs -f

# Run console commands
docker compose exec app php bin/console matrikkel:ping
```

### Manual Installation

```bash
# Install dependencies
composer install

# Install PHP SOAP extension (Ubuntu/Debian)
sudo apt-get install php-soap

# Clear cache
php bin/console cache:clear

# Test the connection
php bin/console matrikkel:ping
```

## 📖 Usage

### Available Console Commands

**Test API connection:**

```bash
php bin/console matrikkel:ping
```

**Import data:**

```bash
# Complete import (both phases combined)
php bin/console matrikkel:import --kommune=4627 --organisasjonsnummer=964338442

# With limit for testing
php bin/console matrikkel:import --kommune=4627 --organisasjonsnummer=964338442 --limit=10

# Skip Phase 1 (only import buildings and addresses)
php bin/console matrikkel:import --kommune=4627 --skip-phase1

# Skip Phase 2 (only import properties and owners)
php bin/console matrikkel:import --kommune=4627 --skip-phase2
```

**Alternative: Two-phase approach (if needed):**

```bash
# Phase 1: Import base data (kommune, matrikkelenheter, personer, eierforhold)
php bin/console matrikkel:phase1-import --kommune=4627 --organisasjonsnummer=964338442

# Phase 2: Import building data (veger, bygninger, bruksenheter, adresser)
php bin/console matrikkel:phase2-import --kommune=4627 --organisasjonsnummer=964338442
```

**Debug commands:**

```bash
# Debug matrikkelenhet structure from API
php bin/console matrikkel:debug-matrikkelenhet

# Test NedlastningClient bulk downloads
php bin/console matrikkel:test-nedlastning
```

**Note**: For searching individual addresses, property units, cadastral units, municipalities, or code lists, use the REST API endpoints below instead of console commands.

### REST API Endpoints

The project now includes a comprehensive REST API that provides JSON access to all Matrikkel functionality:

**Base URL**: `http://localhost:8083/api/v1`

**Available Endpoints**:

```bash
# API Documentation
GET /api/v1/endpoints          # List all available endpoints
GET /api/v1/ping               # API health check

# Address Services
GET /api/v1/address/{id}                    # Get address by ID
GET /api/v1/address/search?q={query}       # Search addresses via API
GET /api/v1/address/search/db?q={query}    # Search addresses in local DB
GET /api/v1/address/postal/{postnummer}    # Get postal area

# Municipality Services  
GET /api/v1/municipality/{id}              # Get municipality by ID
GET /api/v1/municipality/number/{number}   # Get municipality by number

# Property Unit Services
GET /api/v1/property-unit/{id}                    # Get property unit by ID
GET /api/v1/property-unit/address/{addressId}     # Get units for address

# Cadastral Unit Services
GET /api/v1/cadastral-unit/{id}                        # Get by ID
GET /api/v1/cadastral-unit/{knr}/{gnr}/{bnr}           # Get by matrikkel number
GET /api/v1/cadastral-unit/{knr}/{gnr}/{bnr}/{fnr}     # With festenummer
GET /api/v1/cadastral-unit/{knr}/{gnr}/{bnr}/{fnr}/{snr} # With section

# Code Lists
GET /api/v1/codelist           # Get all code lists
GET /api/v1/codelist/{id}      # Get specific code list with codes

# General Search
GET /api/v1/search?q={query}&source=api&limit={number}&offset={start}    # Search via Matrikkel API (pagination support)
GET /api/v1/search?q={query}&source=db     # Search via local database
```

**Example API Usage**:

```bash
# Test API health
curl http://localhost:8083/api/v1/ping

# Search for addresses
curl "http://localhost:8083/api/v1/address/search?q=Bergen"

# Get municipality data  
curl http://localhost:8083/api/v1/municipality/4601

# Search with pagination - get results beyond 100
curl "http://localhost:8083/api/v1/search?q=oslo&source=api&limit=50&offset=0"    # First 50 results
curl "http://localhost:8083/api/v1/search?q=oslo&source=api&limit=50&offset=50"   # Next 50 results  
curl "http://localhost:8083/api/v1/search?q=oslo&source=api&limit=50&offset=100"  # Results 101-150

# Search with local database
curl "http://localhost:8083/api/v1/search?q=Oslo&source=db"

```

All endpoints return JSON with this structure:

```json
{
  "data": { ... },
  "timestamp": "2025-10-03T13:57:21+00:00", 
  "status": "success"
}
```

### Docker Commands

```bash
# Start containers
docker compose up -d

# Stop containers  
docker compose down

# View logs
docker compose logs -f

# Run console commands
docker compose exec app php bin/console <command>

# Access container shell
docker compose exec app bash

# Rebuild containers
docker compose build --no-cache
```

## 💾 Local Database Import

### Import Commands

The project provides a unified import command that handles all data import in two phases:

**Phase 1** (Base Data):
- Kommune (municipality)
- Matrikkelenheter (cadastral units/properties)
- Personer (owners - physical and legal persons)
- Eierforhold (ownership relations)

**Phase 2** (Building Data):
- Veger (roads/streets)
- Bruksenheter (property units)
- Bygninger (buildings)
- Adresser (addresses)

**Basic Usage**:

```bash
# Full import for a municipality with owner filter
php bin/console matrikkel:import --kommune=4627 --organisasjonsnummer=964338442

# Test with limited data
php bin/console matrikkel:import --kommune=4627 --organisasjonsnummer=964338442 --limit=10

# Import only Phase 1 (base data)
php bin/console matrikkel:import --kommune=4627 --skip-phase2

# Import only Phase 2 (building data)
php bin/console matrikkel:import --kommune=4627 --skip-phase1
```

**Available Options**:

- `--kommune=XXXX` - Required: 4-digit municipality number
- `--organisasjonsnummer=XXXXXX` - Filter by organization number (owner)
- `--limit=N` - Limit number of matrikkelenheter to import (for testing)
- `--skip-phase1` - Skip Phase 1, only run Phase 2
- `--skip-phase2` - Skip Phase 2, only run Phase 1

**Example: Full Import for Askøy Kommune**:

```bash
php bin/console matrikkel:import --kommune=4627 --organisasjonsnummer=964338442
```

This will import:
- 1 kommune
- 693 matrikkelenheter
- 442 personer
- 329 veger
- 879 bruksenheter
- 692 bygninger
- 263 adresser

**Performance**:
- Phase 1: ~12 seconds (base data)
- Phase 2: ~23 seconds (building data)
- Total: ~35 seconds for complete dataset

### Legacy Commands

The individual phase commands are still available if you need more control:

```bash
# Run Phase 1 only
php bin/console matrikkel:phase1-import --kommune=4627 --organisasjonsnummer=964338442

# Run Phase 2 only
php bin/console matrikkel:phase2-import --kommune=4627 --organisasjonsnummer=964338442
```

### Database Schema

The project uses PostgreSQL to store imported data. The complete schema includes 7 primary tables:

- **`matrikkel_kommuner`** - Norwegian municipalities
- **`matrikkel_matrikkelenheter`** - Cadastral units (properties)
- **`matrikkel_personer`** - Property owners (physical and legal persons)
- **`matrikkel_eierforhold`** - Ownership records (junction table)
- **`matrikkel_veger`** - Roads/streets
- **`matrikkel_bygninger`** - Buildings with detailed property data
- **`matrikkel_bruksenheter`** - Property units (apartments, etc.)
- **`matrikkel_adresser`** - Addresses

For the complete database schema, see `migrations/V1__baseline_schema.sql`.

## 🔗 API Reference

### MatrikkelAPI Documentation

- **Production API**: <https://prodtest.matrikkel.no/matrikkelapi/wsapi/v1/dokumentasjon/index.html>
- **Test Environment**: Available through Kartverket

### SOAP Services Available

- **AdresseClient** - Address lookup and search
- **BruksenhetClient** - Property units
- **KommuneClient** - Municipality data
- **KodelisteClient** - Code lists and references
- **MatrikkelenhetClient** - Cadastral units
- **MatrikkelsokClient** - General cadastre search

## 🐛 Troubleshooting

### Common Issues

**SOAP Extension Missing**:

```bash
# Ubuntu/Debian
sudo apt-get install php-soap

# CentOS/RHEL
sudo yum install php-soap
```

**Permission Issues with Docker**:

```bash
# Fix file permissions
sudo chown -R $USER:$USER ./var
chmod -R 775 ./var
```

**API Connection Issues**:

1. Verify your credentials in `.env`
2. Test with: `php bin/console matrikkel:ping`
3. Check if you're using the correct environment (`prod` vs `test`)

**Cache Issues**:

```bash
# Clear Symfony cache
php bin/console cache:clear

# With Docker
docker compose exec app php bin/console cache:clear
```

**Coordinate Transformation Notice**:

The current implementation includes a basic stub for coordinate transformations (UTM ↔ Lat/Long). For production use requiring precise coordinate transformations, consider implementing a proper coordinate transformation library or service.

## 📝 Development

### Project Structure

```text
src/
├── Client/          # SOAP client implementations
├── Console/         # Symfony console commands  
├── Entity/          # Data entities
├── LocalDb/         # Local database services
└── Service/         # Business logic services
```

### Adding New Commands

Extend `AbstractCommand` class and place in `src/Console/` directory.

### Service Configuration

Services are configured in `config/services.yaml` using the factory pattern for SOAP clients.

## 📄 License

GPL-3.0-or-later

## 🤝 Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Add tests if applicable
5. Submit a pull request

---

For more information about the Norwegian Cadastre system, visit [Kartverket](https://www.kartverket.no/).
