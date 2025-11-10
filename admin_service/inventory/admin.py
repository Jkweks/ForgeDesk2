"""Admin registrations for ForgeDesk data tables."""
from __future__ import annotations

from django.contrib import admin

from . import models


@admin.register(models.InventoryItem)
class InventoryItemAdmin(admin.ModelAdmin):
    list_display = ("item", "sku", "location", "stock", "status", "supplier")
    list_filter = ("status", "supplier")
    search_fields = ("item", "sku", "location", "supplier")
    ordering = ("item",)


@admin.register(models.InventoryMetric)
class InventoryMetricAdmin(admin.ModelAdmin):
    list_display = ("label", "value", "delta", "timeframe", "accent", "sort_order")
    list_editable = ("value", "delta", "timeframe", "accent", "sort_order")
    search_fields = ("label",)
    ordering = ("sort_order", "label")


class JobReservationItemInline(admin.TabularInline):
    model = models.JobReservationItem
    extra = 0
    autocomplete_fields = ("inventory_item",)
    readonly_fields = ("consumed_qty",)


@admin.register(models.JobReservation)
class JobReservationAdmin(admin.ModelAdmin):
    list_display = ("job_number", "job_name", "requested_by", "needed_by", "status")
    list_filter = ("status", "requested_by")
    search_fields = ("job_number", "job_name", "requested_by")
    inlines = [JobReservationItemInline]


class CycleCountLineInline(admin.TabularInline):
    model = models.CycleCountLine
    extra = 0
    autocomplete_fields = ("inventory_item",)


@admin.register(models.CycleCountSession)
class CycleCountSessionAdmin(admin.ModelAdmin):
    list_display = ("name", "status", "started_at", "completed_at", "completed_lines", "total_lines")
    list_filter = ("status",)
    search_fields = ("name", "location_filter")
    date_hierarchy = "started_at"
    inlines = [CycleCountLineInline]


@admin.register(models.CycleCountLine)
class CycleCountLineAdmin(admin.ModelAdmin):
    list_display = (
        "session",
        "inventory_item",
        "sequence",
        "expected_qty",
        "counted_qty",
        "variance",
        "counted_at",
    )
    list_filter = ("session",)
    search_fields = ("session__name", "inventory_item__item", "inventory_item__sku")
    raw_id_fields = ("session", "inventory_item")
