"""ForgeDesk admin URL configuration."""
from __future__ import annotations

from django.contrib import admin
from django.urls import path

urlpatterns = [
    path("django-admin/", admin.site.urls),
    path("admin/", admin.site.urls),
]
