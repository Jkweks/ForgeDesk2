<?php

declare(strict_types=1);

/**
 * Escape a string for safe output in HTML contexts.
 */
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/**
 * Resolve the URL for a navigation item, defaulting to an in-page anchor when no explicit href is provided.
 *
 * @param array{label:string,href?:string} $item
 */
function nav_href(array $item): string
{
    if (!empty($item['href'])) {
        return $item['href'];
    }

    $anchor = strtolower(str_replace(' ', '-', $item['label']));

    return '#' . $anchor;
}

/**
 * Render a nested storage location hierarchy with grouped checkboxes.
 *
 * @param list<array{id:int,label:string,location_ids:list<int>,racks:list<array{label:string,location_ids:list<int>,shelves:list<array{label:string,location_ids:list<int>,bins:list<array{id:int,label:string,bin:?string}>}>}>}> $locationHierarchy
 * @param list<int> $selectedLocationIds
 */
function renderLocationHierarchy(array $locationHierarchy, array $selectedLocationIds = [], ?string $inputName = null): void
{
    echo '<div class="location-hierarchy" data-location-hierarchy>';

    foreach ($locationHierarchy as $aisle) {
        $aisleIds = implode(',', $aisle['location_ids']);
        echo '<div class="location-branch" data-level="aisle">';
        echo '<label class="checkbox-option">';
        echo '<input type="checkbox" data-location-group data-child-ids="' . e($aisleIds) . '">';
        echo '<span>' . e($aisle['label']) . '</span>';
        echo '</label>';

        foreach ($aisle['racks'] as $rack) {
            $rackIds = implode(',', $rack['location_ids']);
            echo '<div class="location-branch" data-level="rack">';
            echo '<label class="checkbox-option">';
            echo '<input type="checkbox" data-location-group data-child-ids="' . e($rackIds) . '">';
            echo '<span>' . e($rack['label']) . '</span>';
            echo '</label>';

            foreach ($rack['shelves'] as $shelf) {
                $shelfIds = implode(',', $shelf['location_ids']);
                $hasRealBins = array_filter($shelf['bins'], static function ($bin): bool {
                    return isset($bin['bin']) && $bin['bin'] !== null && trim((string) $bin['bin']) !== '';
                });
                $showShelfGroup = $hasRealBins !== [] || count($shelf['bins']) > 1;

                echo '<div class="location-branch" data-level="shelf">';
                if ($showShelfGroup) {
                    echo '<label class="checkbox-option">';
                    echo '<input type="checkbox" data-location-group data-child-ids="' . e($shelfIds) . '">';
                    echo '<span>' . e($shelf['label']) . '</span>';
                    echo '</label>';
                }

                echo '<div class="location-branch" data-level="bin">';
                foreach ($shelf['bins'] as $bin) {
                    $isChecked = in_array($bin['id'], $selectedLocationIds, true);
                    $attributes = [
                        'type' => 'checkbox',
                        'value' => (string) $bin['id'],
                        'data-location-node' => 'bin',
                    ];

                    if ($inputName !== null && trim($inputName) !== '') {
                        $attributes['name'] = $inputName;
                    }

                    if ($isChecked) {
                        $attributes['checked'] = 'checked';
                    }

                    $attributeString = '';
                    foreach ($attributes as $attribute => $value) {
                        $attributeString .= ' ' . $attribute . '="' . e((string) $value) . '"';
                    }

                    echo '<label class="checkbox-option">';
                    echo '<input' . $attributeString . ' />';
                    echo '<span class="location-leaf__label">' . e($bin['label']) . '</span>';
                    echo '</label>';
                }
                echo '</div>';
                echo '</div>';
            }

            echo '</div>';
        }

        echo '</div>';
    }

    echo '</div>';
}
