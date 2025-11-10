FROM python:3.11-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1

WORKDIR /app

# System deps if you need them later (commented)
# RUN apt-get update && apt-get install -y --no-install-recommends ca-certificates curl \
#   && rm -rf /var/lib/apt/lists/*

COPY requirements.txt ./
RUN pip install --no-cache-dir -r requirements.txt

# Copy app code
COPY . /app

# Install the entrypoint OUTSIDE the bind mount and make it executable, LF-only
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/entrypoint.sh && chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 8000

# Use ENTRYPOINT so compose can't accidentally override with CMD
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
