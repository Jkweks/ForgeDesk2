<?php
declare(strict_types=1);

use function htmlspecialchars as e;

if (!function_exists('renderInventoryTable')) {
    /**
     * Render an inventory table with optional filters and pagination.
     *
     * @param list<array<string,mixed>> $rows
     * @param array{
     *   includeFilters?:bool,
     *   emptyMessage?:string,
     *   id?:string,
     *   pageSize?:int,
     *   showActions?:bool,
     *   locationHierarchy?:array
     * } $options
     */
    function renderInventoryTable(array $rows, array $options = []): void
    {
        $includeFilters = $options['includeFilters'] ?? true;
        $emptyMessage = $options['emptyMessage'] ?? 'No inventory items found.';
        $tableId = $options['id'] ?? null;
        $pageSize = isset($options['pageSize']) ? max(1, (int) $options['pageSize']) : 50;
        $showActions = $options['showActions'] ?? true;
        $locationHierarchy = $options['locationHierarchy'] ?? [];

        $statusBadgeClasses = static function (string $status): string {
            $normalized = strtolower(trim($status));

            $map = [
                'critical' => 'badge bg-red-lt text-red',
                'out of stock' => 'badge bg-red-lt text-red',
                'low' => 'badge bg-orange-lt text-orange',
                'warning' => 'badge bg-orange-lt text-orange',
                'delayed' => 'badge bg-orange-lt text-orange',
                'healthy' => 'badge bg-green-lt text-green',
                'available' => 'badge bg-green-lt text-green',
                'balanced' => 'badge bg-green-lt text-green',
                'on order' => 'badge bg-blue-lt text-blue',
                'committed' => 'badge bg-indigo-lt text-indigo',
                'reserved' => 'badge bg-indigo-lt text-indigo',
            ];

            if (isset($map[$normalized])) {
                return $map[$normalized];
            }

            return 'badge badge-outline text-secondary';
        };

        $containerAttributes = ['class' => 'inventory-table-container', 'data-inventory-table' => 'true'];
        $containerAttributes['data-page-size'] = (string) $pageSize;

        if ($tableId !== null) {
            $containerAttributes['id'] = $tableId . '-container';
        }

        $attributesString = '';
        foreach ($containerAttributes as $attribute => $value) {
            if ($value === null) {
                continue;
            }

            if ($attribute === 'class') {
                $attributesString .= ' class="' . e((string) $value) . '"';
                continue;
            }

            $attributesString .= ' ' . $attribute . '="' . e((string) $value) . '"';
        }

        echo '<div' . $attributesString . '>';
        echo '<div class="card card-stacked inventory-table-card">';

        if ($includeFilters) {
            $locationToggleId = ($tableId !== null ? $tableId . '-' : '') . 'location-filter';
            $statusSelectId = ($tableId !== null ? $tableId . '-' : '') . 'status-filter';

            echo '<div class="card-body pb-0">';
            echo '<div class="inventory-filters row g-2 align-items-end" role="search">';
            echo '<div class="col-12 col-md-3">';
            echo '<label class="form-label" for="' . e($tableId . '-filter-item') . '">Item</label>';
            echo '<div class="input-icon">';
            echo '<input type="search" class="form-control column-filter" id="' . e($tableId . '-filter-item') . '" data-key="item" placeholder="Search items" aria-label="Filter by item">';
            echo '<span class="input-icon-addon" aria-hidden="true"><i class="ti ti-search"></i></span>';
            echo '</div>';
            echo '</div>';

            echo '<div class="col-12 col-md-3">';
            echo '<label class="form-label" for="' . e($tableId . '-filter-sku') . '">SKU / Part #</label>';
            echo '<input type="search" class="form-control column-filter" id="' . e($tableId . '-filter-sku') . '" data-key="sku" data-alt-keys="partNumber" placeholder="Search SKU or part #" aria-label="Filter by SKU">';
            echo '</div>';

            echo '<div class="col-12 col-md-3">';
            echo '<label class="form-label" for="' . e($locationToggleId) . '">Location</label>';
            echo '<div class="location-filter location-filter--detached" data-location-filter data-filter-target="locationIds" data-location-filter-id="' . e($locationToggleId) . '">';
            echo '<input type="hidden" class="column-filter" data-key="locationIds" data-filter-type="tokens" />';
            echo '<button type="button" class="btn btn-outline-secondary w-100 d-flex justify-content-between align-items-center" id="' . e($locationToggleId) . '" data-location-filter-toggle data-location-filter-id="' . e($locationToggleId) . '" aria-expanded="false">';
            echo '<span class="location-filter__label">All locations</span>';
            echo '<span class="location-filter__chevron" aria-hidden="true">▾</span>';
            echo '</button>';
            echo '<div class="location-filter__modal" data-location-filter-modal hidden>';
            echo '<div class="modal-backdrop" data-location-filter-backdrop></div>';
            echo '<div class="location-filter__dialog" role="dialog" aria-modal="true" aria-label="Select storage locations">';
            echo '<div class="location-filter__dialog-header">';
            echo '<h3>Select locations</h3>';
            echo '<button type="button" class="button ghost icon-only" data-location-filter-close aria-label="Close location filter">&times;</button>';
            echo '</div>';
            echo '<div class="location-filter__dialog-body">';
            if ($locationHierarchy === []) {
                echo '<p class="small">No storage locations configured yet. Add them from the admin dashboard to filter inventory.</p>';
            } else {
                renderLocationHierarchy($locationHierarchy);
            }
            echo '</div>';
            echo '<div class="location-filter__actions">';
            echo '<button type="button" class="button ghost" data-location-filter-clear>Clear</button>';
            echo '<button type="button" class="button primary" data-location-filter-apply>Apply</button>';
            echo '</div>';
            echo '</div>';
            echo '</div>';
            echo '</div>';
            echo '</div>';

            echo '<div class="col-12 col-md-3">';
            echo '<label class="form-label" for="' . e($statusSelectId) . '">Status</label>';
            echo '<select class="form-select column-filter" id="' . e($statusSelectId) . '" data-key="status" aria-label="Filter by status">';
            echo '<option value="">All statuses</option>';
            echo '<option value="Healthy">Healthy</option>';
            echo '<option value="Low">Low</option>';
            echo '<option value="Critical">Critical</option>';
            echo '<option value="On order">On order</option>';
            echo '<option value="Committed">Committed</option>';
            echo '</select>';
            echo '</div>';

            echo '<div class="col-12 col-md-3">';
            echo '<label class="form-label" for="' . e($tableId . '-filter-availability') . '">Availability</label>';
            echo '<div class="input-group input-group-flat">';
            echo '<input type="search" class="form-control column-filter" id="' . e($tableId . '-filter-availability') . '" data-key="available" placeholder="Search available" aria-label="Filter by available" inputmode="numeric">';
            echo '<span class="input-group-text">Qty</span>';
            echo '</div>';
            echo '</div>';

            echo '<div class="col-12 col-md-3">';
            echo '<label class="form-label" for="' . e($tableId . '-filter-lead') . '">Lead time</label>';
            echo '<input type="search" class="form-control column-filter" id="' . e($tableId . '-filter-lead') . '" data-key="leadTime" placeholder="Lead time (days)" aria-label="Filter by lead time" inputmode="numeric">';
            echo '</div>';

            echo '<div class="col-12 col-md-3">';
            echo '<label class="form-label" for="' . e($tableId . '-filter-reservations') . '">Reservations</label>';
            echo '<div class="input-group input-group-flat">';
            echo '<input type="search" class="form-control column-filter" id="' . e($tableId . '-filter-reservations') . '" data-key="reservations" placeholder="Active jobs" aria-label="Filter by reservations" inputmode="numeric">';
            echo '<span class="input-group-text">Jobs</span>';
            echo '</div>';
            echo '</div>';

            echo '</div>';
            echo '</div>';
        }

        echo '<div class="card-body p-0">';
        echo '<div class="table-responsive">';
        echo '<table class="table table-vcenter table-hover text-nowrap inventory-table"' . ($tableId !== null ? ' id="' . e($tableId) . '"' : '') . '>';
        echo '<thead>';
        echo '<tr>';
        echo '<th scope="col" class="sortable" data-sort-key="item" aria-sort="none">Item</th>';
        echo '<th scope="col" class="sortable" data-sort-key="sku" aria-sort="none">SKU</th>';
        echo '<th scope="col" class="sortable" data-sort-key="location" aria-sort="none">Location</th>';
        echo '<th scope="col" class="text-end sortable" data-sort-key="stock" data-sort-type="number" aria-sort="none">Stock</th>';
        echo '<th scope="col" class="text-end sortable" data-sort-key="committed" data-sort-type="number" aria-sort="none">Committed</th>';
        echo '<th scope="col" class="text-end sortable" data-sort-key="available" data-sort-type="number" aria-sort="none">Available</th>';
        echo '<th scope="col" class="text-end sortable" data-sort-key="leadTime" data-sort-type="number" aria-sort="none">Lead Time (days)</th>';
        echo '<th scope="col" class="text-end sortable" data-sort-key="averageDailyUse" data-sort-type="number" aria-sort="none">Avg Daily Use</th>';
        echo '<th scope="col" class="sortable" data-sort-key="status" aria-sort="none">Status</th>';
        echo '<th scope="col" class="sortable" data-sort-key="reservations" data-sort-type="number" aria-sort="none">Reservations</th>';
        if ($showActions) {
            echo '<th scope="col" class="text-end">Actions</th>';
        }
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';

        if ($rows === []) {
            $columnCount = $showActions ? 11 : 10;
            echo '<tr>';
            echo '<td colspan="' . e((string) $columnCount) . '" class="small">' . e($emptyMessage) . '</td>';
            echo '</tr>';
        } else {
            foreach ($rows as $index => $row) {
                $dailyUseRaw = isset($row['average_daily_use']) ? (float) $row['average_daily_use'] : 0.0;
                $dailyUseAttr = number_format($dailyUseRaw, 4, '.', '');
                $dailyUseDisplay = inventoryFormatDailyUse($dailyUseRaw);
                $availableClass = ((int) $row['available_qty']) <= 0 ? 'bg-red-lt text-red' : 'bg-green-lt text-green';
                $reservationDetails = $row['reservation_details'] ?? [];
                $reservationData = '[]';

                $statusLabel = (string) $row['status'];
                $statusBadgeClass = $statusBadgeClasses($statusLabel);

                try {
                    $reservationData = json_encode($reservationDetails, JSON_THROW_ON_ERROR);
                } catch (\JsonException $exception) {
                    $reservationData = '[]';
                }

                echo '<tr'
                    . ' data-index="' . e((string) $index) . '"'
                    . ' data-item="' . e((string) $row['item']) . '"'
                    . ' data-sku="' . e((string) $row['sku']) . '"'
                    . ' data-part-number="' . e((string) $row['part_number']) . '"'
                    . ' data-location="' . e((string) $row['location']) . '"'
                    . ' data-location-ids="' . e(implode(',', $row['location_ids'] ?? [])) . '"'
                    . ' data-stock="' . e((string) $row['stock']) . '"'
                    . ' data-committed="' . e((string) $row['committed_qty']) . '"'
                    . ' data-available="' . e((string) $row['available_qty']) . '"'
                    . ' data-lead-time="' . e((string) $row['lead_time_days']) . '"'
                    . ' data-average-daily-use="' . e($dailyUseAttr) . '"'
                    . ' data-status="' . e((string) $row['status']) . '"'
                    . ' data-reservations="' . e((string) $row['active_reservations']) . '"'
                    . ' data-reservation-details="' . e($reservationData) . '"'
                    . ' data-finish="' . e($row['finish'] ?? '') . '"'
                    . ' data-item-id="' . e((string) $row['id']) . '"'
                    . '>';

                echo '<td class="item fw-semibold">' . e((string) $row['item']) . '</td>';
                echo '<td class="sku"><span class="badge bg-gray-lt text-uppercase letter-spacing-wide">' . e((string) $row['sku']) . '</span></td>';
                echo '<td>' . e((string) $row['location']) . '</td>';
                echo '<td class="text-end"><span class="badge bg-gray-100 text-body">' . e(inventoryFormatQuantity((int) $row['stock'])) . '</span></td>';
                echo '<td class="text-end">'
                    . '<button type="button" class="btn btn-link px-0 text-decoration-none" data-reservation-trigger="committed"'
                    . ' aria-label="View committed jobs for ' . e((string) $row['item']) . '">' . e(inventoryFormatQuantity((int) $row['committed_qty'])) . '</button>'
                    . '</td>';
                echo '<td class="text-end"><span class="badge ' . $availableClass . '">' . e(inventoryFormatQuantity((int) $row['available_qty'])) . '</span></td>';
                echo '<td class="text-end">' . e((string) $row['lead_time_days']) . '</td>';
                echo '<td class="text-end"><span class="badge bg-gray-100 text-body">' . e($dailyUseDisplay) . '<span class="text-secondary">/day</span></span></td>';
                echo '<td><span class="' . e($statusBadgeClass) . '" data-level="' . e($statusLabel) . '">' . e($statusLabel) . '</span></td>';

                echo '<td class="reservations">';
                if ((int) $row['active_reservations'] > 0) {
                    $reservationText = (int) $row['active_reservations'] === 1
                        ? '1 active job'
                        : $row['active_reservations'] . ' active jobs';
                    echo '<button type="button" class="btn btn-link px-0 reservation-link" data-reservation-trigger="reservations"'
                        . ' aria-label="View active jobs for ' . e((string) $row['item']) . '">' . e((string) $reservationText) . '</button>';
                } else {
                    echo '<span class="reservation-link text-secondary">None</span>';
                }
                echo '</td>';

                if ($showActions) {
                    echo '<td class="text-end"><a class="btn btn-outline-secondary" href="inventory.php?id=' . e((string) $row['id']) . '">Edit</a></td>';
                }

                echo '</tr>';
            }
        }

        echo '</tbody>';
        echo '</table>';
        echo '</div>';
        echo '</div>';

        echo '<div class="card-footer d-flex align-items-center justify-content-between flex-wrap gap-2" data-pagination role="navigation" aria-label="Inventory table pagination">';
        echo '<div class="text-secondary" data-pagination-status>Page 1 of 1</div>';
        echo '<div class="btn-list">';
        echo '<button type="button" class="btn btn-outline-secondary" data-pagination-prev disabled>Previous</button>';
        echo '<button type="button" class="btn btn-outline-secondary" data-pagination-next disabled>Next</button>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
        echo '</div>';

        static $renderedReservationModal = false;
        if (!$renderedReservationModal) {
            echo '<div class="modal" id="reservation-breakdown-modal" role="dialog" aria-modal="true" aria-hidden="true" hidden data-reservation-modal>';
            echo '<div class="modal-dialog">';
            echo '<header>';
            echo '<div>';
            echo '<p class="muted small" data-reservation-modal-subtitle>Committed jobs</p>';
            echo '<h2 id="reservation-breakdown-title" data-reservation-modal-title>Job commitments</h2>';
            echo '<p class="muted" data-reservation-modal-meta></p>';
            echo '</div>';
            echo '<button type="button" class="modal-close" data-reservation-modal-close aria-label="Close job commitments">&times;</button>';
            echo '</header>';
            echo '<div class="modal-body" data-reservation-modal-body></div>';
            echo '</div>';
            echo '</div>';
            $renderedReservationModal = true;
        }
    }
}
