#!/bin/bash
# Docker Storage Setup for ForgeDesk2
# This script configures storage directories with proper permissions for Docker containers

set -e

echo "🔧 ForgeDesk2 Docker Storage Setup"
echo "=================================="
echo ""

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Storage directories
STORAGE_DIR="./storage"
EZ_ESTIMATE_DIR="./storage/ez-estimate"
ESTIMATE_UPLOADS_DIR="./storage/estimate-uploads"

# Docker web service typically runs as www-data (UID 33, GID 33)
# If you're running a different setup, adjust these values
DOCKER_USER_ID=${DOCKER_USER_ID:-33}
DOCKER_GROUP_ID=${DOCKER_GROUP_ID:-33}

echo "Configuration:"
echo "  - Docker User ID: ${DOCKER_USER_ID}"
echo "  - Docker Group ID: ${DOCKER_GROUP_ID}"
echo ""

# Function to create directory with proper permissions
create_storage_dir() {
    local dir=$1
    local description=$2

    echo -n "Setting up ${description}... "

    # Create directory if it doesn't exist
    if [ ! -d "${dir}" ]; then
        mkdir -p "${dir}"
        echo -e "${GREEN}created${NC}"
    else
        echo -e "${YELLOW}exists${NC}"
    fi

    # Set ownership to Docker user/group
    # This allows the container to write to these directories
    if command -v chown &> /dev/null; then
        sudo chown -R "${DOCKER_USER_ID}:${DOCKER_GROUP_ID}" "${dir}" 2>/dev/null || {
            echo -e "${YELLOW}Warning: Could not set ownership. You may need to run with sudo.${NC}"
        }
    fi

    # Set permissions: rwxrwx--- (770)
    # Owner (container) and group can read/write/execute
    chmod -R 770 "${dir}"

    # Verify directory is writable
    if [ -w "${dir}" ]; then
        echo "  ✓ Permissions verified for ${dir}"
    else
        echo -e "  ${RED}✗ Warning: Directory may not be writable${NC}"
    fi
}

echo "Creating storage directories..."
echo ""

# Create main storage directory
create_storage_dir "${STORAGE_DIR}" "Main storage directory"

# Create EZ Estimate template storage
create_storage_dir "${EZ_ESTIMATE_DIR}" "EZ Estimate template storage"

# Create estimate uploads temporary storage
create_storage_dir "${ESTIMATE_UPLOADS_DIR}" "Estimate uploads storage"

echo ""
echo -e "${GREEN}✓ Storage setup complete!${NC}"
echo ""
echo "Next steps:"
echo "1. Ensure your docker-compose.yml includes volume mounts (see DOCKER_STORAGE.md)"
echo "2. Start your containers: docker-compose up -d"
echo "3. Verify the web container can write to storage:"
echo "   docker-compose exec web touch /var/www/html/storage/ez-estimate/test.txt"
echo ""
echo "Directory structure created:"
tree -L 2 ${STORAGE_DIR} 2>/dev/null || ls -lah ${STORAGE_DIR}
echo ""
