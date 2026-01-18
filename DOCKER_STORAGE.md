# Docker Storage Configuration for ForgeDesk2

This guide explains how to configure storage permissions for ForgeDesk2 when running in Docker containers on a separate server.

## Overview

ForgeDesk2 requires writable storage directories for:
- **EZ Estimate Templates**: Uploaded Excel templates stored in `storage/ez-estimate/`
- **Estimate Uploads**: Temporary processing of uploaded estimates in `storage/estimate-uploads/`

When running in Docker, these directories must have proper permissions so the container can read and write files.

## Quick Start

### 1. Run the Setup Script

On your Docker host server, navigate to your ForgeDesk2 directory and run:

```bash
./docker-storage-setup.sh
```

This script will:
- Create the required storage directories
- Set proper ownership (UID 33, GID 33 for www-data)
- Set permissions to 770 (rwxrwx---)
- Verify the directories are writable

### 2. Update Your docker-compose.yml

Add volume mounts to the `web` service in your docker-compose.yml:

```yaml
services:
  web:
    image: ghcr.io/jkweks/forgedesk-web:v0.4.3
    ports: ["8046:80"]
    env_file: [.env.prod]
    volumes:
      # Mount storage directories for persistent data
      - ./storage/ez-estimate:/var/www/html/storage/ez-estimate
      - ./storage/estimate-uploads:/var/www/html/storage/estimate-uploads
    depends_on:
      postgres:
        condition: service_healthy
    restart: unless-stopped
```

### 3. Restart Your Containers

```bash
docker-compose down
docker-compose up -d
```

### 4. Verify Permissions

Test that the web container can write to the storage:

```bash
docker-compose exec web touch /var/www/html/storage/ez-estimate/test.txt
docker-compose exec web ls -l /var/www/html/storage/ez-estimate/
docker-compose exec web rm /var/www/html/storage/ez-estimate/test.txt
```

If these commands succeed without errors, your permissions are configured correctly!

## Understanding Docker Permissions

### Why Permissions Matter

Docker containers run processes with specific user IDs. The PHP/Apache container typically runs as the `www-data` user, which has:
- **UID**: 33
- **GID**: 33

When the container tries to write to a mounted volume, the host filesystem checks permissions using these IDs. If the host directories are owned by a different user or have restrictive permissions, writes will fail.

### Directory Permissions

The setup script creates directories with:
- **Owner**: UID 33 (www-data in container)
- **Group**: GID 33 (www-data in container)
- **Permissions**: 770 (rwxrwx---)
  - Owner can read, write, execute
  - Group can read, write, execute
  - Others have no access

## Complete docker-compose.yml Example

Here's a full example showing your production configuration with storage volumes:

```yaml
services:
  web:
    image: ghcr.io/jkweks/forgedesk-web:v0.4.3
    ports: ["8046:80"]
    env_file: [.env.prod]
    volumes:
      # Storage volumes for file uploads and templates
      - ./storage/ez-estimate:/var/www/html/storage/ez-estimate
      - ./storage/estimate-uploads:/var/www/html/storage/estimate-uploads
    depends_on:
      postgres:
        condition: service_healthy
    restart: unless-stopped

  admin:
    image: ghcr.io/jkweks/forgedesk-admin:v0.4.3
    ports: ["8002:8000"]
    environment:
      DJANGO_SETTINGS_MODULE: forge_admin.settings
      SECRET_KEY: "django-insecure-admin-service"
      DEBUG: "1"
      DB_HOST: postgres
      DB_PORT: 5432
      DB_DATABASE: forge_desk
      DB_USERNAME: forge
      DB_PASSWORD: forgepass
      ALLOWED_HOSTS: "fab.kweks.co,localhost,127.0.0.1"
      CSRF_TRUSTED_ORIGINS: "https://fab.kweks.co,https://fab.vosdb.kweks.co"
      SECURE_PROXY_SSL_HEADER: "HTTP_X_FORWARDED_PROTO,https"
    depends_on:
      postgres:
        condition: service_healthy
    restart: unless-stopped
    healthcheck:
      test: ["CMD-SHELL", "python - <<'PY'\nimport socket,sys\ns=socket.socket();\nsys.exit(0) if not s.connect_ex(('127.0.0.1',8000)) else sys.exit(1)\nPY"]
      interval: 15s
      timeout: 5s
      retries: 5
    command: ["/app/entrypoint.sh"]

  postgres:
    image: postgres:15-alpine
    container_name: forgedesk-postgres
    environment:
      POSTGRES_DB: forge_desk
      POSTGRES_USER: forge
      POSTGRES_PASSWORD: forgepass
    volumes:
      - ./postgres:/var/lib/postgresql/data
      - ./database/migrations:/migrations:ro
    ports: ["5432:5432"]
    restart: unless-stopped
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U $$POSTGRES_USER -d $$POSTGRES_DB"]
      interval: 10s
      timeout: 5s
      retries: 10
```

## Troubleshooting

### Permission Denied Errors

If you see "Permission denied" errors when uploading files:

1. **Check directory ownership on host:**
   ```bash
   ls -lan storage/
   ```
   The directories should be owned by UID 33 and GID 33.

2. **Check container user:**
   ```bash
   docker-compose exec web id
   ```
   Should show `uid=33(www-data) gid=33(www-data)`.

3. **Re-run the setup script:**
   ```bash
   ./docker-storage-setup.sh
   ```

4. **If using sudo is required:**
   ```bash
   sudo ./docker-storage-setup.sh
   ```

### Storage Not Persisting

If uploaded files disappear after container restart:

1. **Verify volume mounts** in docker-compose.yml
2. **Check that volumes are mounted** inside the container:
   ```bash
   docker-compose exec web df -h | grep storage
   ```

### Alternative: Run Setup Manually

If you prefer not to use the script, create directories manually:

```bash
# Create directories
mkdir -p storage/ez-estimate storage/estimate-uploads

# Set ownership (may require sudo)
sudo chown -R 33:33 storage/

# Set permissions
chmod -R 770 storage/
```

## Advanced Configuration

### Custom User IDs

If your Docker image uses a different user ID, set the environment variable before running the script:

```bash
export DOCKER_USER_ID=1000
export DOCKER_GROUP_ID=1000
./docker-storage-setup.sh
```

### SELinux Considerations

If your host uses SELinux, you may need to set the appropriate context:

```bash
sudo chcon -R -t container_file_t storage/
```

Or add `:z` or `:Z` to the volume mounts:

```yaml
volumes:
  - ./storage/ez-estimate:/var/www/html/storage/ez-estimate:z
```

## Security Considerations

- **Permissions**: The 770 permissions ensure only the container user can access files
- **Ownership**: Setting ownership to UID 33 prevents other host users from accessing sensitive data
- **Backups**: Consider backing up the `storage/` directory regularly
- **Secrets**: Never store sensitive files like `.env` with overly permissive permissions

## Support

For issues related to Docker storage configuration, please check:
1. Docker logs: `docker-compose logs web`
2. Container filesystem: `docker-compose exec web ls -la /var/www/html/storage/`
3. Host filesystem: `ls -la storage/`

If problems persist, open an issue on the ForgeDesk2 GitHub repository with:
- Your docker-compose.yml configuration
- Output from `ls -lan storage/`
- Relevant error messages from logs
