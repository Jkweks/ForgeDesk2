#!/bin/bash
set -e

# Ensure storage directory exists and is writable by www-data
mkdir -p /var/www/html/storage
chown -R www-data:www-data /var/www/html/storage
chmod -R 770 /var/www/html/storage

# Execute the main command (Apache)
exec apache2-foreground
