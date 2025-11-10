#!/bin/sh
set -euo pipefail

python manage.py migrate --noinput

if [ "${DJANGO_SUPERUSER_USERNAME:-}" != "" ] && [ "${DJANGO_SUPERUSER_PASSWORD:-}" != "" ]; then
    python manage.py shell <<'PYTHON'
from __future__ import annotations
import os
from django.contrib.auth import get_user_model

username = os.environ.get("DJANGO_SUPERUSER_USERNAME")
password = os.environ.get("DJANGO_SUPERUSER_PASSWORD")
email = os.environ.get("DJANGO_SUPERUSER_EMAIL") or ""
User = get_user_model()
if username and password and not User.objects.filter(username=username).exists():
    User.objects.create_superuser(username=username, email=email, password=password)
PYTHON
fi

python manage.py runserver 0.0.0.0:8000
