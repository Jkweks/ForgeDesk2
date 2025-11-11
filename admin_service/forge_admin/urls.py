"""ForgeDesk admin URL configuration."""
from __future__ import annotations

from django.contrib import admin
from django.urls import path
from django.views.generic import RedirectView

urlpatterns = [
    path("django-admin/", admin.site.urls),
    path(
        "admin/",
        RedirectView.as_view(pattern_name="admin:index", permanent=False),
        name="admin-redirect",
    ),
]
