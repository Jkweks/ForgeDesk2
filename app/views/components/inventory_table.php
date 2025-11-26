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

        if ($includeFilters) {
            $locationToggleId = ($tableId !== null ? $tableId . '-' : '') . 'location-filter';

            echo '<div class="location-filter location-filter--detached" data-location-filter data-filter-target="locationIds" data-location-filter-id="' . e($locationToggleId) . '">';
            echo '<input type="hidden" class="column-filter" data-key="locationIds" data-filter-type="tokens" />';
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
        }

        echo '<table class="table inventory-table"' . ($tableId !== null ? ' id="' . e($tableId) . '"' : '') . '>';
        echo '<thead>';
        echo '<tr>';
        echo '<th scope="col" class="sortable" data-sort-key="item" aria-sort="none">Item</th>';
        echo '<th scope="col" class="sortable" data-sort-key="sku" aria-sort="none">SKU</th>';
        echo '<th scope="col" class="sortable" data-sort-key="location" aria-sort="none">Location</th>';
        echo '<th scope="col" class="numeric sortable" data-sort-key="stock" data-sort-type="number" aria-sort="none">Stock</th>';
        echo '<th scope="col" class="numeric sortable" data-sort-key="committed" data-sort-type="number" aria-sort="none">Committed</th>';
        echo '<th scope="col" class="numeric sortable" data-sort-key="available" data-sort-type="number" aria-sort="none">Available</th>';
        echo '<th scope="col" class="numeric sortable" data-sort-key="leadTime" data-sort-type="number" aria-sort="none">Lead Time (days)</th>';
        echo '<th scope="col" class="numeric sortable" data-sort-key="averageDailyUse" data-sort-type="number" aria-sort="none">Avg Daily Use</th>';
        echo '<th scope="col" class="sortable" data-sort-key="status" aria-sort="none">Status</th>';
        echo '<th scope="col" class="sortable" data-sort-key="reservations" data-sort-type="number" aria-sort="none">Reservations</th>';
        if ($showActions) {
            echo '<th scope="col" class="actions">Actions</th>';
        }
        echo '</tr>';

        if ($includeFilters) {
            echo '<tr class="filter-row">';
            echo '<th><input type="search" class="column-filter" data-key="item" placeholder="Search items" aria-label="Filter by item"></th>';
            echo '<th><input type="search" class="column-filter" data-key="sku" data-alt-keys="partNumber" placeholder="Search SKU or part #" aria-label="Filter by SKU"></th>';
            echo '<th>';
            echo '<button type="button" class="location-filter__toggle location-filter__toggle--inline" id="' . e($locationToggleId) . '" data-location-filter-toggle data-location-filter-id="' . e($locationToggleId) . '" aria-expanded="false">';
            echo '<span class="location-filter__label">All locations</span>';
            echo '<span class="location-filter__chevron" aria-hidden="true">▾</span>';
            echo '</button>';
            echo '</th>';
            echo '<th><input type="search" class="column-filter" data-key="stock" placeholder="Search stock" aria-label="Filter by stock" inputmode="numeric"></th>';
            echo '<th><input type="search" class="column-filter" data-key="committed" placeholder="Search committed" aria-label="Filter by committed" inputmode="numeric"></th>';
            echo '<th><input type="search" class="column-filter" data-key="available" placeholder="Search available" aria-label="Filter by available" inputmode="numeric"></th>';
            echo '<th><input type="search" class="column-filter" data-key="leadTime" placeholder="Search lead time" aria-label="Filter by lead time" inputmode="numeric"></th>';
            echo '<th><input type="search" class="column-filter" data-key="averageDailyUse" placeholder="Search avg/day" aria-label="Filter by average daily use" inputmode="decimal"></th>';
            echo '<th><input type="search" class="column-filter" data-key="status" placeholder="Search status" aria-label="Filter by status"></th>';
            echo '<th><input type="search" class="column-filter" data-key="reservations" placeholder="Search reservations" aria-label="Filter by reservations" inputmode="numeric"></th>';
            if ($showActions) {
                echo '<th aria-hidden="true"></th>';
            }
            echo '</tr>';
        }

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
                $availableClass = ((int) $row['available_qty']) <= 0 ? 'danger' : 'success';

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
                    . ' data-finish="' . e($row['finish'] ?? '') . '"'
                    . ' data-item-id="' . e((string) $row['id']) . '"'
                    . '>';

                echo '<td class="item">' . e((string) $row['item']) . '</td>';
                echo '<td class="sku"><span class="sku-badge">' . e((string) $row['sku']) . '</span></td>';
                echo '<td>' . e((string) $row['location']) . '</td>';
                echo '<td class="numeric"><span class="quantity-pill">' . e(inventoryFormatQuantity((int) $row['stock'])) . '</span></td>';
                echo '<td class="numeric"><span class="quantity-pill brand">' . e(inventoryFormatQuantity((int) $row['committed_qty'])) . '</span></td>';
                echo '<td class="numeric"><span class="quantity-pill ' . $availableClass . '">' . e(inventoryFormatQuantity((int) $row['available_qty'])) . '</span></td>';
                echo '<td class="numeric">' . e((string) $row['lead_time_days']) . '</td>';
                echo '<td class="numeric"><span class="quantity-pill">' . e($dailyUseDisplay) . '<span class="muted">/day</span></span></td>';
                echo '<td><span class="status" data-level="' . e((string) $row['status']) . '">' . e((string) $row['status']) . '</span></td>';

                echo '<td class="reservations">';
                if ((int) $row['active_reservations'] > 0) {
                    $reservationText = (int) $row['active_reservations'] === 1
                        ? '1 active job'
                        : $row['active_reservations'] . ' active jobs';
                    echo '<a class="reservation-link" href="/admin/job-reservations.php?inventory_id=' . e((string) $row['id']) . '">' . e((string) $reservationText) . '</a>';
                } else {
                    echo '<span class="reservation-link muted">None</span>';
                }
                echo '</td>';

                if ($showActions) {
                    echo '<td class="actions"><a class="button ghost" href="inventory.php?id=' . e((string) $row['id']) . '">Edit</a></td>';
                }

                echo '</tr>';
            }
        }

        echo '</tbody>';
        echo '</table>';

        echo '<div class="table-pagination" data-pagination role="navigation" aria-label="Inventory table pagination">';
        echo '<div class="pagination-controls">';
        echo '<button type="button" class="button ghost" data-pagination-prev disabled>Previous</button>';
        echo '<span class="pagination-status" data-pagination-status>Page 1 of 1</span>';
        echo '<button type="button" class="button ghost" data-pagination-next disabled>Next</button>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
    }
}
