"""Admin registrations for ForgeDesk data tables."""
from __future__ import annotations

from django.contrib import admin

from . import models


class InventoryItemLocationInline(admin.TabularInline):
    model = models.InventoryItemLocation
    extra = 0
    autocomplete_fields = ("storage_location",)


@admin.register(models.InventoryItem)
class InventoryItemAdmin(admin.ModelAdmin):
    list_display = (
        "item",
        "sku",
        "location",
        "stock",
        "committed_qty",
        "on_order_qty",
        "reorder_point",
        "safety_stock",
        "status",
        "average_daily_use",
        "supplier",
    )
    list_filter = ("status", "supplier", "supplier_ref")
    search_fields = (
        "item",
        "sku",
        "part_number",
        "location",
        "supplier",
        "supplier_ref__name",
        "supplier_sku",
    )
    ordering = ("item",)
    readonly_fields = ("average_daily_use",)
    autocomplete_fields = ("supplier_ref",)
    inlines = [InventoryItemLocationInline]


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
        "is_skipped",
    )
    list_filter = ("session", "is_skipped")
    search_fields = ("session__name", "inventory_item__item", "inventory_item__sku")
    raw_id_fields = ("session", "inventory_item")


class InventoryTransactionLineInline(admin.TabularInline):
    model = models.InventoryTransactionLine
    extra = 0
    autocomplete_fields = ("inventory_item",)
    readonly_fields = ("stock_before", "stock_after")


@admin.register(models.InventoryTransaction)
class InventoryTransactionAdmin(admin.ModelAdmin):
    list_display = ("reference", "created_at", "notes")
    search_fields = ("reference", "notes")
    ordering = ("-created_at",)
    date_hierarchy = "created_at"
    inlines = [InventoryTransactionLineInline]


@admin.register(models.InventoryTransactionLine)
class InventoryTransactionLineAdmin(admin.ModelAdmin):
    list_display = (
        "transaction",
        "inventory_item",
        "quantity_change",
        "stock_before",
        "stock_after",
    )
    search_fields = ("transaction__reference", "inventory_item__item", "inventory_item__sku")
    autocomplete_fields = ("transaction", "inventory_item")


@admin.register(models.Supplier)
class SupplierAdmin(admin.ModelAdmin):
    list_display = ("name", "contact_name", "contact_email", "default_lead_time_days")
    search_fields = ("name", "contact_name", "contact_email")
    list_filter = ("default_lead_time_days",)


@admin.register(models.StorageLocation)
class StorageLocationAdmin(admin.ModelAdmin):
    list_display = (
        "display_name",
        "aisle",
        "rack",
        "shelf",
        "bin",
        "is_active",
        "sort_order",
    )
    list_filter = ("is_active", "aisle", "rack", "shelf")
    search_fields = ("name", "description", "aisle", "rack", "shelf", "bin")
    ordering = ("sort_order", "aisle", "rack", "shelf", "bin", "name")

    @admin.display(description="Name")
    def display_name(self, obj):  # pragma: no cover - admin helper
        parts = [
            value
            for value in [obj.aisle, obj.rack, obj.shelf, obj.bin]
            if value not in (None, "")
        ]
        if parts:
            return ".".join(parts)
        return obj.name


@admin.register(models.InventoryItemLocation)
class InventoryItemLocationAdmin(admin.ModelAdmin):
    list_display = ("inventory_item", "storage_location", "quantity")
    search_fields = (
        "inventory_item__item",
        "inventory_item__sku",
        "storage_location__name",
        "storage_location__aisle",
        "storage_location__rack",
    )
    autocomplete_fields = ("inventory_item", "storage_location")


class PurchaseOrderLineInline(admin.TabularInline):
    model = models.PurchaseOrderLine
    extra = 0
    autocomplete_fields = ("inventory_item",)
    readonly_fields = (
        "quantity_received",
        "quantity_cancelled",
        "created_at",
        "updated_at",
    )


class PurchaseOrderReceiptInline(admin.TabularInline):
    model = models.PurchaseOrderReceipt
    extra = 0
    readonly_fields = ("reference", "inventory_transaction", "created_at", "total_received", "total_cancelled")
    can_delete = False
    show_change_link = True
    autocomplete_fields = ("inventory_transaction",)
    fields = ("reference", "inventory_transaction", "created_at", "total_received", "total_cancelled")

    @admin.display(description="Total received")
    def total_received(self, obj):  # pragma: no cover - admin helper
        return obj.total_received

    @admin.display(description="Total cancelled")
    def total_cancelled(self, obj):  # pragma: no cover - admin helper
        return obj.total_cancelled


@admin.register(models.PurchaseOrder)
class PurchaseOrderAdmin(admin.ModelAdmin):
    list_display = ("display_number", "status", "supplier", "order_date", "expected_date", "total_cost")
    list_filter = ("status", "supplier")
    search_fields = ("order_number", "supplier__name")
    autocomplete_fields = ("supplier",)
    readonly_fields = ("created_at", "updated_at")
    inlines = [PurchaseOrderLineInline, PurchaseOrderReceiptInline]

    @admin.display(description="PO")
    def display_number(self, obj):  # pragma: no cover - admin helper
        return obj.order_number or f"#{obj.id}"


@admin.register(models.PurchaseOrderLine)
class PurchaseOrderLineAdmin(admin.ModelAdmin):
    list_display = (
        "purchase_order",
        "inventory_item",
        "quantity_ordered",
        "quantity_received",
        "quantity_cancelled",
        "packs_ordered",
        "pack_size",
        "unit_cost",
        "purchase_uom",
        "stock_uom",
    )
    search_fields = (
        "purchase_order__order_number",
        "purchase_order__id",
        "inventory_item__item",
        "inventory_item__sku",
        "supplier_sku",
    )
    autocomplete_fields = ("purchase_order", "inventory_item")
    readonly_fields = ("created_at", "updated_at")


class PurchaseOrderReceiptLineInline(admin.TabularInline):
    model = models.PurchaseOrderReceiptLine
    extra = 0
    readonly_fields = ("quantity_received", "quantity_cancelled")
    autocomplete_fields = ("purchase_order_line",)


@admin.register(models.PurchaseOrderReceipt)
class PurchaseOrderReceiptAdmin(admin.ModelAdmin):
    list_display = ("reference", "purchase_order", "created_at", "total_received", "total_cancelled")
    search_fields = ("reference", "purchase_order__order_number")
    autocomplete_fields = ("purchase_order", "inventory_transaction")
    readonly_fields = ("created_at", "total_received", "total_cancelled")
    inlines = [PurchaseOrderReceiptLineInline]

    @admin.display(description="Total received")
    def total_received(self, obj):  # pragma: no cover - admin helper
        return obj.total_received

    @admin.display(description="Total cancelled")
    def total_cancelled(self, obj):  # pragma: no cover - admin helper
        return obj.total_cancelled


@admin.register(models.PurchaseOrderReceiptLine)
class PurchaseOrderReceiptLineAdmin(admin.ModelAdmin):
    list_display = (
        "receipt",
        "purchase_order_line",
        "quantity_received",
        "quantity_cancelled",
    )
    search_fields = (
        "receipt__reference",
        "purchase_order_line__purchase_order__order_number",
        "purchase_order_line__inventory_item__sku",
    )
    autocomplete_fields = ("receipt", "purchase_order_line")


class MaintenanceTaskInline(admin.TabularInline):
    model = models.MaintenanceTask
    extra = 0
    autocomplete_fields = ("machine",)


class MaintenanceRecordInline(admin.TabularInline):
    model = models.MaintenanceRecord
    extra = 0
    autocomplete_fields = ("task",)
    readonly_fields = ("created_at",)


@admin.register(models.MaintenanceMachine)
class MaintenanceMachineAdmin(admin.ModelAdmin):
    list_display = ("name", "equipment_type", "manufacturer", "model", "location", "updated_at")
    search_fields = ("name", "equipment_type", "manufacturer", "model", "serial_number", "location")
    list_filter = ("equipment_type",)
    ordering = ("name",)
    inlines = [MaintenanceTaskInline, MaintenanceRecordInline]


@admin.register(models.MaintenanceTask)
class MaintenanceTaskAdmin(admin.ModelAdmin):
    list_display = (
        "title",
        "machine",
        "frequency",
        "interval_count",
        "interval_unit",
        "assigned_to",
        "status",
        "priority",
        "start_date",
        "last_completed_at",
        "updated_at",
    )
    search_fields = (
        "title",
        "machine__name",
        "assigned_to",
        "frequency",
        "status",
        "priority",
    )
    list_filter = ("frequency", "status", "priority")
    autocomplete_fields = ("machine",)
    ordering = ("machine", "title")


@admin.register(models.MaintenanceRecord)
class MaintenanceRecordAdmin(admin.ModelAdmin):
    list_display = (
        "machine",
        "task",
        "performed_by",
        "performed_at",
        "downtime_minutes",
        "labor_hours",
        "created_at",
    )
    search_fields = (
        "machine__name",
        "task__title",
        "performed_by",
        "notes",
        "parts_used",
    )
    list_filter = ("performed_at", "downtime_minutes")
    autocomplete_fields = ("machine", "task")
    date_hierarchy = "performed_at"
    ordering = ("-performed_at", "-created_at")
