"""Database models mapping to the ForgeDesk operational schema."""
from __future__ import annotations

from django.db import models


class InventoryItem(models.Model):
    """Inventory item that can be edited through the admin."""

    id = models.AutoField(primary_key=True)
    item = models.CharField(max_length=255)
    sku = models.CharField(max_length=255, unique=True)
    part_number = models.CharField(max_length=255, blank=True)
    finish = models.CharField(max_length=255, blank=True, null=True)
    location = models.CharField(max_length=255)
    stock = models.IntegerField()
    committed_qty = models.IntegerField()
    status = models.CharField(max_length=255)
    supplier = models.CharField(max_length=255)
    supplier_contact = models.CharField(max_length=255, blank=True, null=True)
    reorder_point = models.IntegerField()
    lead_time_days = models.IntegerField()

    class Meta:
        managed = False
        db_table = "inventory_items"
        ordering = ["item"]
        verbose_name = "Inventory item"
        verbose_name_plural = "Inventory items"

    def __str__(self) -> str:  # pragma: no cover - trivial
        return f"{self.item} ({self.sku})"


class InventoryMetric(models.Model):
    """Key performance metrics displayed in the main dashboard."""

    id = models.AutoField(primary_key=True)
    label = models.CharField(max_length=255, unique=True)
    value = models.CharField(max_length=255)
    delta = models.CharField(max_length=255, blank=True, null=True)
    timeframe = models.CharField(max_length=255, blank=True, null=True)
    accent = models.BooleanField(default=False)
    sort_order = models.IntegerField(default=100)

    class Meta:
        managed = False
        db_table = "inventory_metrics"
        ordering = ["sort_order", "label"]
        verbose_name = "Inventory metric"
        verbose_name_plural = "Inventory metrics"

    def __str__(self) -> str:  # pragma: no cover - trivial
        return self.label


class JobReservation(models.Model):
    """Represents a reservation request for inventory."""

    id = models.AutoField(primary_key=True)
    job_number = models.CharField(max_length=255, unique=True)
    job_name = models.CharField(max_length=255)
    requested_by = models.CharField(max_length=255)
    needed_by = models.DateField(blank=True, null=True)
    status = models.CharField(max_length=50)
    notes = models.TextField(blank=True, null=True)
    created_at = models.DateTimeField()
    updated_at = models.DateTimeField()

    class Meta:
        managed = False
        db_table = "job_reservations"
        ordering = ["-created_at"]
        verbose_name = "Job reservation"
        verbose_name_plural = "Job reservations"

    def __str__(self) -> str:  # pragma: no cover - trivial
        return f"{self.job_number}: {self.job_name}"


class JobReservationItem(models.Model):
    """Line items associated with a job reservation."""

    id = models.AutoField(primary_key=True)
    reservation = models.ForeignKey(
        JobReservation,
        on_delete=models.DO_NOTHING,
        db_column="reservation_id",
        related_name="line_items",
    )
    inventory_item = models.ForeignKey(
        InventoryItem,
        on_delete=models.DO_NOTHING,
        db_column="inventory_item_id",
        related_name="reservations",
    )
    requested_qty = models.IntegerField()
    committed_qty = models.IntegerField()
    consumed_qty = models.IntegerField()

    class Meta:
        managed = False
        db_table = "job_reservation_items"
        unique_together = ("reservation", "inventory_item")
        verbose_name = "Job reservation line"
        verbose_name_plural = "Job reservation lines"

    def __str__(self) -> str:  # pragma: no cover - trivial
        return f"{self.reservation.job_number} - {self.inventory_item.sku}"


class CycleCountSession(models.Model):
    """Cycle count sessions for periodic inventory verification."""

    id = models.AutoField(primary_key=True)
    name = models.CharField(max_length=255)
    status = models.CharField(max_length=50)
    started_at = models.DateTimeField()
    completed_at = models.DateTimeField(blank=True, null=True)
    location_filter = models.CharField(max_length=255, blank=True, null=True)
    total_lines = models.IntegerField()
    completed_lines = models.IntegerField()

    class Meta:
        managed = False
        db_table = "cycle_count_sessions"
        ordering = ["-started_at"]
        verbose_name = "Cycle count session"
        verbose_name_plural = "Cycle count sessions"

    def __str__(self) -> str:  # pragma: no cover - trivial
        return self.name


class CycleCountLine(models.Model):
    """Individual counts that belong to a cycle count session."""

    id = models.AutoField(primary_key=True)
    session = models.ForeignKey(
        CycleCountSession,
        on_delete=models.CASCADE,
        db_column="session_id",
        related_name="lines",
    )
    inventory_item = models.ForeignKey(
        InventoryItem,
        on_delete=models.CASCADE,
        db_column="inventory_item_id",
        related_name="cycle_counts",
    )
    sequence = models.IntegerField()
    expected_qty = models.IntegerField()
    counted_qty = models.IntegerField(blank=True, null=True)
    variance = models.IntegerField(blank=True, null=True)
    counted_at = models.DateTimeField(blank=True, null=True)
    note = models.TextField(blank=True, null=True)

    class Meta:
        managed = False
        db_table = "cycle_count_lines"
        unique_together = ("session", "sequence")
        ordering = ["session", "sequence"]
        verbose_name = "Cycle count line"
        verbose_name_plural = "Cycle count lines"

    def __str__(self) -> str:  # pragma: no cover - trivial
        return f"{self.session.name} - {self.inventory_item.sku}"
