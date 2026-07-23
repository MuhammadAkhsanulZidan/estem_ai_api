#!/bin/bash

# Base directory path
BASE_PATH="/var/www/html/estem_ai_api/sql/tables"

# Build array of full file paths from arguments
FILES=()
for file in "$@"; do
    FILES+=("${BASE_PATH}/${file}.sql")
done

# Combine and execute in one connection
cat "${FILES[@]}" | psql -U rspad -d estem_ai_db
