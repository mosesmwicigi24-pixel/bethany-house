#!/bin/bash
set -e

# Create extensions
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-EOSQL
    -- Enable UUID extension
    CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
    
    -- Enable pg_trgm for text search
    CREATE EXTENSION IF NOT EXISTS "pg_trgm";
    
    -- Enable hstore for key-value storage
    CREATE EXTENSION IF NOT EXISTS "hstore";
    
    -- Grant privileges
    GRANT ALL PRIVILEGES ON DATABASE $POSTGRES_DB TO $POSTGRES_USER;
EOSQL

echo "PostgreSQL initialization completed successfully"
