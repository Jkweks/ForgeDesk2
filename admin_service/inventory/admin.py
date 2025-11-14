"""Admin registrations for ForgeDesk data tables."""
from __future__ import annotations

from django.contrib import admin

from . import models


@admin.register(models.InventoryItem)
class InventoryItemAdmin(admin.ModelAdmin):
    list_display = ("item", "sku", "location", "stock", "status", "average_daily_use", "supplier")
    list_filter = ("status", "supplier")
    search_fields = ("item", "sku", "part_number", "location", "supplier")
    ordering = ("item",)
    readonly_fields = ("average_daily_use",)


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
        "unit_cost",
    )
    search_fields = (
        "purchase_order__order_number",
        "purchase_order__id",
        "inventory_item__item",
        "inventory_item__sku",
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
