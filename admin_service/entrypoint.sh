#!/bin/sh
set -e

# Optional: wait for Postgres (simple retry loop)
if [ -n "$DB_HOST" ]; then
  echo "Waiting for Postgres at $DB_HOST:$DB_PORT..."
  for i in $(seq 1 30); do
    (echo > /dev/tcp/$DB_HOST/$DB_PORT) >/dev/null 2>&1 && break
    sleep 1
  done
fi

# Django env (set these in compose if your project/module name differs)
: "${DJANGO_SETTINGS_MODULE:=forgedesk_api.settings}"
export DJANGO_SETTINGS_MODULE

python manage.py migrate --noinput || true
python manage.py collectstatic --noinput || true

# Create superuser if missing
python - <<'PY'
import os
os.environ.setdefault("DJANGO_SETTINGS_MODULE", os.environ.get("DJANGO_SETTINGS_MODULE","forgedesk_api.settings"))
import django; django.setup()
from django.contrib.auth import get_user_model
User = get_user_model()
u = os.environ.get("DJANGO_SUPERUSER_USERNAME","admin")
e = os.environ.get("DJANGO_SUPERUSER_EMAIL","admin@example.com")
p = os.environ.get("DJANGO_SUPERUSER_PASSWORD","adminpass")
if not User.objects.filter(username=u).exists():
    User.objects.create_superuser(u, e, p)
    print("Created superuser:", u)
else:
    print("Superuser exists:", u)
PY

# Start server (gunicorn for prod; runserver when DEBUG=1 if you prefer)
if [ "${DEBUG}" = "1" ]; then
  exec python manage.py runserver 0.0.0.0:8000
else
  # Change module if your project isn't forgedesk_api
  exec gunicorn forgedesk_admin.wsgi:application --bind 0.0.0.0:8000 --workers 3
fi
