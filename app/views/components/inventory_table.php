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

        $locationOptions = [];
        $collectLocationOptions = static function (array $tree) use (&$locationOptions, &$collectLocationOptions): void {
            foreach ($tree as $aisle) {
                foreach ($aisle['racks'] ?? [] as $rack) {
                    foreach ($rack['shelves'] ?? [] as $shelf) {
                        foreach ($shelf['bins'] ?? [] as $bin) {
                            $label = $bin['path_label'] ?? ($bin['label'] ?? 'Bin');
                            $locationOptions[] = [
                                'id' => (int) $bin['id'],
                                'label' => (string) $label,
                            ];
                        }
                    }
                }
            }
        };

        $collectLocationOptions($locationHierarchy);

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
            $statusSelectId = ($tableId !== null ? $tableId . '-' : '') . 'status-filter';
            $searchInputId = ($tableId !== null ? $tableId . '-' : '') . 'search-filter';
            $locationSelectId = ($tableId !== null ? $tableId . '-' : '') . 'location-filter';

            echo '<div class="card-header">';
            echo '<div class="row g-2 align-items-end">';
            echo '<div class="col-12 col-md-4">';
            echo '<label class="form-label" for="' . e($searchInputId) . '">Search</label>';
            echo '<div class="input-icon">';
            echo '<input type="search" class="form-control" id="' . e($searchInputId) . '" data-filter-search placeholder="Search item, SKU, or location" aria-label="Search inventory">';
            echo '<span class="input-icon-addon"><i class="ti ti-search" aria-hidden="true"></i></span>';
            echo '</div>';
            echo '</div>';

            echo '<div class="col-12 col-md-4">';
            echo '<label class="form-label" for="' . e($locationSelectId) . '">Location</label>';
            echo '<select class="form-select" id="' . e($locationSelectId) . '" data-filter-location aria-label="Filter by location">';
            echo '<option value="">All locations</option>';
            foreach ($locationOptions as $option) {
                echo '<option value="' . e((string) $option['id']) . '">' . e($option['label']) . '</option>';
            }
            echo '</select>';
            echo '</div>';

            echo '<div class="col-12 col-md-4">';
            echo '<label class="form-label" for="' . e($statusSelectId) . '">Status</label>';
            echo '<select class="form-select" id="' . e($statusSelectId) . '" data-filter-status aria-label="Filter by status">';
            echo '<option value="">All statuses</option>';
            echo '<option value="Healthy">Healthy</option>';
            echo '<option value="Low">Low</option>';
            echo '<option value="Critical">Critical</option>';
            echo '<option value="On order">On order</option>';
            echo '<option value="Committed">Committed</option>';
            echo '</select>';
            echo '</div>';
            echo '</div>';
            echo '</div>';
        }

        echo '<div class="card-body p-0">';
        echo '<div class="table-responsive">';
        echo '<table class="table table-vcenter card-table table-hover text-nowrap datatable"'
            . ' data-datatable="inventory"'
            . ' data-default-page-size="' . e((string) $pageSize) . '"'
            . ($tableId !== null ? ' id="' . e($tableId) . '"' : '')
            . '>';
        echo '<thead>';
        echo '<tr>';
        echo '<th scope="col" data-sort-key="item">Item</th>';
        echo '<th scope="col" data-sort-key="sku">SKU</th>';
        echo '<th scope="col" data-sort-key="location">Location</th>';
        echo '<th scope="col" class="text-end" data-sort-key="stock">Stock</th>';
        echo '<th scope="col" class="text-end" data-sort-key="committed">Committed</th>';
        echo '<th scope="col" class="text-end" data-sort-key="available">Available</th>';
        echo '<th scope="col" class="text-end" data-sort-key="leadTime">Lead Time (days)</th>';
        echo '<th scope="col" class="text-end" data-sort-key="averageDailyUse">Avg Daily Use</th>';
        echo '<th scope="col" data-sort-key="status">Status</th>';
        echo '<th scope="col" data-sort-key="reservations">Reservations</th>';
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

                echo '<td class="item fw-semibold" data-order="' . e((string) $row['item']) . '">' . e((string) $row['item']) . '</td>';
                echo '<td class="sku" data-order="' . e((string) $row['sku']) . '"><span class="badge bg-gray-lt text-uppercase letter-spacing-wide">' . e((string) $row['sku']) . '</span></td>';
                echo '<td data-order="' . e((string) $row['location']) . '">' . e((string) $row['location']) . '</td>';
                echo '<td class="text-end" data-order="' . e((string) $row['stock']) . '"><span class="badge bg-gray-100 text-body">' . e(inventoryFormatQuantity((int) $row['stock'])) . '</span></td>';
                echo '<td class="text-end" data-order="' . e((string) $row['committed_qty']) . '">'
                    . '<button type="button" class="btn btn-link px-0 text-decoration-none" data-reservation-trigger="committed"'
                    . ' aria-label="View committed jobs for ' . e((string) $row['item']) . '">' . e(inventoryFormatQuantity((int) $row['committed_qty'])) . '</button>'
                    . '</td>';
                echo '<td class="text-end" data-order="' . e((string) $row['available_qty']) . '"><span class="badge ' . $availableClass . '">' . e(inventoryFormatQuantity((int) $row['available_qty'])) . '</span></td>';
                echo '<td class="text-end" data-order="' . e((string) $row['lead_time_days']) . '">' . e((string) $row['lead_time_days']) . '</td>';
                echo '<td class="text-end" data-order="' . e($dailyUseAttr) . '"><span class="badge bg-gray-100 text-body">' . e($dailyUseDisplay) . '<span class="text-secondary">/day</span></span></td>';
                echo '<td data-order="' . e($statusLabel) . '"><span class="' . e($statusBadgeClass) . '" data-level="' . e($statusLabel) . '">' . e($statusLabel) . '</span></td>';

                echo '<td class="reservations" data-order="' . e((string) $row['active_reservations']) . '">';
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

        echo '<div class="card-footer border-0 pt-0">';
        echo '<div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2" data-pagination role="navigation" aria-label="Inventory table pagination">';
        echo '<div class="text-secondary small" data-pagination-status>Showing 0 of 0 items</div>';
        echo '<ul class="pagination mb-0" data-pagination-pages>';
        echo '<li class="page-item disabled"><button type="button" class="page-link" data-pagination-prev aria-label="Previous page">Previous</button></li>';
        echo '<li class="page-item disabled"><button type="button" class="page-link" data-pagination-next aria-label="Next page">Next</button></li>';
        echo '</ul>';
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
