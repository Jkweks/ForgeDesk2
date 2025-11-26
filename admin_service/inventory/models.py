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
    on_order_qty = models.DecimalField(max_digits=18, decimal_places=6, default=0)
    safety_stock = models.DecimalField(max_digits=18, decimal_places=6, default=0)
    min_order_qty = models.DecimalField(max_digits=18, decimal_places=6, default=0)
    order_multiple = models.DecimalField(max_digits=18, decimal_places=6, default=0)
    pack_size = models.DecimalField(max_digits=18, decimal_places=6, default=0)
    purchase_uom = models.CharField(max_length=255, blank=True, null=True)
    stock_uom = models.CharField(max_length=255, blank=True, null=True)
    status = models.CharField(max_length=255)
    supplier = models.CharField(max_length=255)
    supplier_ref = models.ForeignKey(
        "Supplier",
        on_delete=models.SET_NULL,
        db_column="supplier_id",
        related_name="items",
        blank=True,
        null=True,
    )
    supplier_contact = models.CharField(max_length=255, blank=True, null=True)
    supplier_sku = models.CharField(max_length=255, blank=True, null=True)
    reorder_point = models.IntegerField()
    lead_time_days = models.IntegerField()
    average_daily_use = models.DecimalField(max_digits=12, decimal_places=4, blank=True, null=True)

    class Meta:
        managed = False
        db_table = "inventory_items"
        ordering = ["item"]
        verbose_name = "Inventory item"
        verbose_name_plural = "Inventory items"

    def __str__(self) -> str:  # pragma: no cover - trivial
        return f"{self.item} ({self.sku})"


class StorageLocation(models.Model):
    """Warehouse storage location with hierarchical components."""

    id = models.AutoField(primary_key=True)
    name = models.TextField()
    description = models.TextField(blank=True, null=True)
    is_active = models.BooleanField(default=True)
    sort_order = models.IntegerField(default=0)
    aisle = models.CharField(max_length=255, blank=True, null=True)
    rack = models.CharField(max_length=255, blank=True, null=True)
    shelf = models.CharField(max_length=255, blank=True, null=True)
    bin = models.CharField(max_length=255, blank=True, null=True)
    created_at = models.DateTimeField()
    updated_at = models.DateTimeField()

    class Meta:
        managed = False
        db_table = "storage_locations"
        ordering = ["sort_order", "aisle", "rack", "shelf", "bin", "name"]
        verbose_name = "Storage location"
        verbose_name_plural = "Storage locations"

    def __str__(self) -> str:  # pragma: no cover - trivial
        parts = [
            value
            for value in [self.aisle, self.rack, self.shelf, self.bin]
            if value not in (None, "")
        ]
        if parts:
            return ".".join(parts)
        return self.name


class InventoryItemLocation(models.Model):
    """Join table for mapping items to storage locations with quantities."""

    id = models.AutoField(primary_key=True)
    inventory_item = models.ForeignKey(
        InventoryItem,
        on_delete=models.CASCADE,
        db_column="inventory_item_id",
        related_name="location_assignments",
    )
    storage_location = models.ForeignKey(
        StorageLocation,
        on_delete=models.CASCADE,
        db_column="storage_location_id",
        related_name="item_assignments",
    )
    quantity = models.IntegerField(default=0)

    class Meta:
        managed = False
        db_table = "inventory_item_locations"
        unique_together = ("inventory_item", "storage_location")
        verbose_name = "Inventory item location"
        verbose_name_plural = "Inventory item locations"

    def __str__(self) -> str:  # pragma: no cover - trivial
        return f"{self.inventory_item} → {self.storage_location}"


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
    is_skipped = models.BooleanField(default=False)

    class Meta:
        managed = False
        db_table = "cycle_count_lines"
        unique_together = ("session", "sequence")
        ordering = ["session", "sequence"]
        verbose_name = "Cycle count line"
        verbose_name_plural = "Cycle count lines"

    def __str__(self) -> str:  # pragma: no cover - trivial
        return f"{self.session.name} - {self.inventory_item.sku}"


class InventoryTransaction(models.Model):
    """A posted stock movement for audit tracking."""

    id = models.AutoField(primary_key=True)
    reference = models.CharField(max_length=255)
    notes = models.TextField(blank=True, null=True)
    created_at = models.DateTimeField()

    class Meta:
        managed = False
        db_table = "inventory_transactions"
        ordering = ["-created_at"]
        verbose_name = "Inventory transaction"
        verbose_name_plural = "Inventory transactions"

    def __str__(self) -> str:  # pragma: no cover - trivial
        return self.reference


class InventoryTransactionLine(models.Model):
    """Line-level quantity adjustments associated with a transaction."""

    id = models.AutoField(primary_key=True)
    transaction = models.ForeignKey(
        InventoryTransaction,
        on_delete=models.CASCADE,
        db_column="transaction_id",
        related_name="lines",
    )
    inventory_item = models.ForeignKey(
        InventoryItem,
        on_delete=models.DO_NOTHING,
        db_column="inventory_item_id",
        related_name="transaction_lines",
    )
    quantity_change = models.IntegerField()
    note = models.TextField(blank=True, null=True)
    stock_before = models.IntegerField()
    stock_after = models.IntegerField()

    class Meta:
        managed = False
        db_table = "inventory_transaction_lines"
        ordering = ["-id"]
        verbose_name = "Inventory transaction line"
        verbose_name_plural = "Inventory transaction lines"

    def __str__(self) -> str:  # pragma: no cover - trivial
        return f"{self.transaction.reference} → {self.inventory_item.sku}"


class Supplier(models.Model):
    """Supplier metadata available in the admin."""

    id = models.AutoField(primary_key=True)
    name = models.CharField(max_length=255)
    contact_name = models.CharField(max_length=255, blank=True, null=True)
    contact_email = models.EmailField(max_length=255, blank=True, null=True)
    contact_phone = models.CharField(max_length=255, blank=True, null=True)
    default_lead_time_days = models.IntegerField(blank=True, null=True)
    notes = models.TextField(blank=True, null=True)
    created_at = models.DateTimeField()
    updated_at = models.DateTimeField()

    class Meta:
        managed = False
        db_table = "suppliers"
        ordering = ["name"]
        verbose_name = "Supplier"
        verbose_name_plural = "Suppliers"

    def __str__(self) -> str:  # pragma: no cover - trivial
        return self.name


class PurchaseOrder(models.Model):
    """Purchase order header record."""

    id = models.AutoField(primary_key=True)
    order_number = models.CharField(max_length=255, blank=True, null=True)
    supplier = models.ForeignKey(
        Supplier,
        on_delete=models.SET_NULL,
        db_column="supplier_id",
        related_name="purchase_orders",
        blank=True,
        null=True,
    )
    status = models.CharField(max_length=50)
    order_date = models.DateField(blank=True, null=True)
    expected_date = models.DateField(blank=True, null=True)
    total_cost = models.DecimalField(max_digits=18, decimal_places=6, default=0)
    notes = models.TextField(blank=True, null=True)
    created_at = models.DateTimeField()
    updated_at = models.DateTimeField()

    class Meta:
        managed = False
        db_table = "purchase_orders"
        ordering = ["-order_date", "-id"]
        verbose_name = "Purchase order"
        verbose_name_plural = "Purchase orders"

    def __str__(self) -> str:  # pragma: no cover - trivial
        if self.order_number:
            return f"PO {self.order_number}"
        return f"PO #{self.id}"


class PurchaseOrderLine(models.Model):
    """Line item belonging to a purchase order."""

    id = models.AutoField(primary_key=True)
    purchase_order = models.ForeignKey(
        PurchaseOrder,
        on_delete=models.CASCADE,
        db_column="purchase_order_id",
        related_name="lines",
    )
    inventory_item = models.ForeignKey(
        InventoryItem,
        on_delete=models.SET_NULL,
        db_column="inventory_item_id",
        related_name="purchase_order_lines",
        blank=True,
        null=True,
    )
    supplier_sku = models.CharField(max_length=255, blank=True, null=True)
    description = models.TextField(blank=True, null=True)
    quantity_ordered = models.DecimalField(max_digits=18, decimal_places=6)
    quantity_received = models.DecimalField(max_digits=18, decimal_places=6)
    quantity_cancelled = models.DecimalField(max_digits=18, decimal_places=6)
    unit_cost = models.DecimalField(max_digits=18, decimal_places=6)
    packs_ordered = models.DecimalField(max_digits=18, decimal_places=6, default=0)
    pack_size = models.DecimalField(max_digits=18, decimal_places=6, default=0)
    purchase_uom = models.CharField(max_length=255, blank=True, null=True)
    stock_uom = models.CharField(max_length=255, blank=True, null=True)
    expected_date = models.DateField(blank=True, null=True)
    created_at = models.DateTimeField()
    updated_at = models.DateTimeField()

    class Meta:
        managed = False
        db_table = "purchase_order_lines"
        ordering = ["purchase_order", "id"]
        verbose_name = "Purchase order line"
        verbose_name_plural = "Purchase order lines"

    def __str__(self) -> str:  # pragma: no cover - trivial
        return f"{self.purchase_order} line {self.id}"


class PurchaseOrderReceipt(models.Model):
    """Receipt event for a purchase order."""

    id = models.AutoField(primary_key=True)
    purchase_order = models.ForeignKey(
        PurchaseOrder,
        on_delete=models.CASCADE,
        db_column="purchase_order_id",
        related_name="receipts",
    )
    inventory_transaction = models.ForeignKey(
        InventoryTransaction,
        on_delete=models.SET_NULL,
        db_column="inventory_transaction_id",
        related_name="purchase_order_receipts",
        blank=True,
        null=True,
    )
    reference = models.CharField(max_length=255)
    notes = models.TextField(blank=True, null=True)
    created_at = models.DateTimeField()

    class Meta:
        managed = False
        db_table = "purchase_order_receipts"
        ordering = ["-created_at"]
        verbose_name = "Purchase order receipt"
        verbose_name_plural = "Purchase order receipts"

    def __str__(self) -> str:  # pragma: no cover - trivial
        return self.reference

    @property
    def total_received(self) -> float:
        return sum(line.quantity_received for line in self.lines.all())

    @property
    def total_cancelled(self) -> float:
        return sum(line.quantity_cancelled for line in self.lines.all())


class PurchaseOrderReceiptLine(models.Model):
    """Line within a purchase order receipt."""

    id = models.AutoField(primary_key=True)
    receipt = models.ForeignKey(
        PurchaseOrderReceipt,
        on_delete=models.CASCADE,
        db_column="receipt_id",
        related_name="lines",
    )
    purchase_order_line = models.ForeignKey(
        PurchaseOrderLine,
        on_delete=models.CASCADE,
        db_column="purchase_order_line_id",
        related_name="receipt_lines",
    )
    quantity_received = models.DecimalField(max_digits=18, decimal_places=6)
    quantity_cancelled = models.DecimalField(max_digits=18, decimal_places=6)

    class Meta:
        managed = False
        db_table = "purchase_order_receipt_lines"
        ordering = ["-id"]
        verbose_name = "Purchase order receipt line"
        verbose_name_plural = "Purchase order receipt lines"

    def __str__(self) -> str:  # pragma: no cover - trivial
        return f"Receipt {self.receipt_id} line {self.purchase_order_line_id}"


class MaintenanceMachine(models.Model):
    """Machine/equipment master data for maintenance tracking."""

    id = models.BigAutoField(primary_key=True)
    name = models.TextField()
    equipment_type = models.TextField()
    manufacturer = models.TextField(blank=True, null=True)
    model = models.TextField(blank=True, null=True)
    serial_number = models.TextField(blank=True, null=True)
    location = models.TextField(blank=True, null=True)
    documents = models.JSONField(default=list)
    notes = models.TextField(blank=True, null=True)
    created_at = models.DateTimeField()
    updated_at = models.DateTimeField()

    class Meta:
        managed = False
        db_table = "maintenance_machines"
        ordering = ["name"]
        verbose_name = "Maintenance machine"
        verbose_name_plural = "Maintenance machines"

    def __str__(self) -> str:  # pragma: no cover - trivial
        return self.name


class MaintenanceTask(models.Model):
    """Preventative maintenance task definition for a machine."""

    id = models.BigAutoField(primary_key=True)
    machine = models.ForeignKey(
        MaintenanceMachine,
        on_delete=models.CASCADE,
        db_column="machine_id",
        related_name="tasks",
    )
    title = models.TextField()
    description = models.TextField(blank=True, null=True)
    frequency = models.TextField(blank=True, null=True)
    assigned_to = models.TextField(blank=True, null=True)
    interval_count = models.IntegerField(blank=True, null=True)
    interval_unit = models.TextField(blank=True, null=True)
    start_date = models.DateField(blank=True, null=True)
    last_completed_at = models.DateField(blank=True, null=True)
    status = models.TextField(default="active")
    priority = models.TextField(default="medium")
    created_at = models.DateTimeField()
    updated_at = models.DateTimeField()

    class Meta:
        managed = False
        db_table = "maintenance_tasks"
        ordering = ["machine", "title"]
        verbose_name = "Maintenance task"
        verbose_name_plural = "Maintenance tasks"

    def __str__(self) -> str:  # pragma: no cover - trivial
        return self.title


class MaintenanceRecord(models.Model):
    """Recorded maintenance work performed on a machine."""

    id = models.BigAutoField(primary_key=True)
    machine = models.ForeignKey(
        MaintenanceMachine,
        on_delete=models.CASCADE,
        db_column="machine_id",
        related_name="records",
    )
    task = models.ForeignKey(
        MaintenanceTask,
        on_delete=models.SET_NULL,
        db_column="task_id",
        related_name="records",
        blank=True,
        null=True,
    )
    performed_by = models.TextField(blank=True, null=True)
    performed_at = models.DateField(blank=True, null=True)
    notes = models.TextField(blank=True, null=True)
    attachments = models.JSONField(default=list)
    downtime_minutes = models.IntegerField(blank=True, null=True)
    labor_hours = models.DecimalField(max_digits=10, decimal_places=2, blank=True, null=True)
    parts_used = models.JSONField(default=list)
    created_at = models.DateTimeField()

    class Meta:
        managed = False
        db_table = "maintenance_records"
        ordering = ["-performed_at", "-created_at"]
        verbose_name = "Maintenance record"
        verbose_name_plural = "Maintenance records"

    def __str__(self) -> str:  # pragma: no cover - trivial
        return f"{self.machine} maintenance"
