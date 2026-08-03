#!/bin/bash

# Database configuration
DB_HOST="127.0.0.1"
DB_PORT="5432"
DB_USER="rspad"
DB_NAME="estemai_db"
DB_PASS="Rsp@d12345"

echo "=== Applying stored procedure_adverse_event.sql ==="
PGPASSWORD=$DB_PASS psql -h $DB_HOST -p $DB_PORT -U $DB_USER -d $DB_NAME -f "$(dirname "$0")/procedure_adverse_event.sql"
echo "=== Done ==="
