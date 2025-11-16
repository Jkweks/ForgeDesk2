(function () {
  function formatNumber(value) {
    if (!Number.isFinite(value)) {
      return '0';
    }

    const absolute = Math.abs(value);
    const minimumFractionDigits = absolute >= 1 ? 0 : 2;

    return value.toLocaleString(undefined, {
      minimumFractionDigits,
      maximumFractionDigits: 3,
    });
  }

  function normalizeUnit(value) {
    return value === 'pack' ? 'pack' : 'each';
  }

  function parsePackSize(row) {
    if (!row) {
      return 0;
    }

    const attr = row.getAttribute('data-pack-size') || '0';
    const parsed = Number.parseFloat(attr);
    return Number.isFinite(parsed) && parsed > 0 ? parsed : 0;
  }

  function convertToEach(quantity, unit, packSize) {
    if (!Number.isFinite(quantity) || quantity <= 0) {
      return 0;
    }

    return unit === 'pack' && packSize > 0 ? quantity * packSize : quantity;
  }

  function convertFromEach(quantityEach, unit, packSize) {
    if (!Number.isFinite(quantityEach) || quantityEach <= 0) {
      return 0;
    }

    return unit === 'pack' && packSize > 0 ? quantityEach / packSize : quantityEach;
  }

  function formatInputQuantity(value) {
    if (!Number.isFinite(value) || value <= 0) {
      return '';
    }

    let formatted = value.toFixed(3);
    formatted = formatted.replace(/0+$/, '').replace(/\.$/, '');
    return formatted;
  }

  function getRowUnit(row) {
    if (!row) {
      return 'each';
    }

    return normalizeUnit(row.getAttribute('data-order-unit') || 'each');
  }

  function setRowUnit(row, unit) {
    if (!row) {
      return;
    }

    const normalized = normalizeUnit(unit);
    row.setAttribute('data-order-unit', normalized);
    const input = row.querySelector('.js-quantity-input');
    if (input) {
      input.setAttribute('data-order-unit', normalized);
    }
  }

  function updateRowQuantity(row) {
    if (!row) {
      return 0;
    }

    const input = row.querySelector('.js-quantity-input');
    if (!input) {
      row.removeAttribute('data-order-qty');
      return 0;
    }

    const packSize = parsePackSize(row);
    const unit = getRowUnit(row);
    const numericValue = Number.parseFloat(input.value || '0');

    if (!Number.isFinite(numericValue) || numericValue <= 0) {
      row.removeAttribute('data-order-qty');
      return 0;
    }

    const quantityEach = convertToEach(numericValue, unit, packSize);
    if (quantityEach > 0) {
      row.setAttribute('data-order-qty', quantityEach.toFixed(3));
      return quantityEach;
    }

    row.removeAttribute('data-order-qty');
    return 0;
  }

  function getRowQuantityEach(row) {
    if (!row) {
      return 0;
    }

    const attr = row.getAttribute('data-order-qty');
    if (attr) {
      const parsed = Number.parseFloat(attr);
      if (Number.isFinite(parsed)) {
        return parsed;
      }
    }

    return updateRowQuantity(row);
  }

  function fillRecommended(row, input) {
    if (!row || !input) {
      return;
    }

    const attr = input.getAttribute('data-recommended-each') || '';
    const recommendedEach = Number.parseFloat(attr);
    if (!Number.isFinite(recommendedEach) || recommendedEach <= 0) {
      return;
    }

    const packSize = parsePackSize(row);
    const unit = getRowUnit(row);
    const converted = convertFromEach(recommendedEach, unit, packSize);
    const formatted = formatInputQuantity(converted) || formatInputQuantity(recommendedEach);
    input.value = formatted;
    updateRowQuantity(row);
  }

  function initTabs() {
    const containers = document.querySelectorAll('[data-replenishment-tabs]');

    containers.forEach((container) => {
      const tabButtons = Array.from(container.querySelectorAll('[role="tab"]'));
      if (tabButtons.length === 0) {
        return;
      }

      const panels = new Map();
      tabButtons.forEach((button) => {
        const panelId = button.getAttribute('data-replenishment-target');
        if (panelId) {
          const panel = document.getElementById(panelId);
          if (panel) {
            panels.set(button, panel);
          }
        }
      });

      function activate(button) {
        if (!button || !panels.has(button)) {
          return;
        }

        tabButtons.forEach((tab) => {
          const panel = panels.get(tab);
          const isActive = tab === button;
          tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
          tab.setAttribute('tabindex', isActive ? '0' : '-1');
          if (panel) {
            if (isActive) {
              panel.removeAttribute('hidden');
            } else {
              panel.setAttribute('hidden', '');
            }
          }
        });

        button.focus();
      }

      tabButtons.forEach((button, index) => {
        button.addEventListener('click', () => activate(button));
        button.addEventListener('keydown', (event) => {
          if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') {
            return;
          }

          event.preventDefault();
          const direction = event.key === 'ArrowLeft' ? -1 : 1;
          const nextIndex = (index + direction + tabButtons.length) % tabButtons.length;
          activate(tabButtons[nextIndex]);
        });
      });

      const initiallyActive = tabButtons.find((button) => button.getAttribute('aria-selected') === 'true');
      if (initiallyActive) {
        activate(initiallyActive);
      }
    });
  }

  function initForm(form) {
    const summaryContainer = form.querySelector('[data-summary]');
    const selectedCountEl = summaryContainer ? summaryContainer.querySelector('[data-selected-count]') : null;
    const orderTotalEl = summaryContainer ? summaryContainer.querySelector('[data-selected-quantity]') : null;
    const checkboxes = Array.from(form.querySelectorAll('.js-line-select'));
    const quantityInputs = Array.from(form.querySelectorAll('.js-quantity-input'));
    const unitCostInputs = Array.from(form.querySelectorAll('[data-unit-cost-input]'));
    const unitSelectors = Array.from(form.querySelectorAll('select[data-quantity-unit]'));

    function getQuantityInput(lineId) {
      return form.querySelector('.js-quantity-input[data-line-id="' + lineId + '"]');
    }

    function updateSummary() {
      let selectedCount = 0;
      let orderTotal = 0;

      checkboxes.forEach((checkbox) => {
        const lineId = checkbox.getAttribute('data-line-id') || checkbox.value;
        const quantityInput = getQuantityInput(lineId);
        const row = quantityInput ? quantityInput.closest('tr') : null;
        if (checkbox.checked) {
          selectedCount += 1;

          if (quantityInput) {
            const quantityEach = getRowQuantityEach(row);
            if (quantityEach > 0) {
              orderTotal += quantityEach;
            }
          }
        }
      });

      if (selectedCountEl) {
        selectedCountEl.textContent = selectedCount.toString();
      }
      if (orderTotalEl) {
        orderTotalEl.textContent = formatNumber(orderTotal) + ' ea';
      }
    }

    checkboxes.forEach((checkbox) => {
      const lineId = checkbox.getAttribute('data-line-id') || checkbox.value;
      const quantityInput = getQuantityInput(lineId);
      const row = quantityInput ? quantityInput.closest('tr') : null;

      if (checkbox.checked && quantityInput && quantityInput.value.trim() === '' && row) {
        fillRecommended(row, quantityInput);
      }

      checkbox.addEventListener('change', () => {
        if (!quantityInput) {
          updateSummary();
          return;
        }

        if (checkbox.checked && quantityInput.value.trim() === '' && row) {
          fillRecommended(row, quantityInput);
        }

        updateSummary();
      });
    });

    quantityInputs.forEach((input) => {
      const row = input.closest('tr');
      updateRowQuantity(row);

      input.addEventListener('input', () => {
        if (input.value.trim() === '') {
          input.removeAttribute('aria-invalid');
          updateRowQuantity(row);
          updateSummary();
          return;
        }

        const numericValue = Number.parseFloat(input.value);
        if (!Number.isFinite(numericValue) || numericValue < 0) {
          input.setAttribute('aria-invalid', 'true');
        } else {
          input.removeAttribute('aria-invalid');
        }

        if (row) {
          updateRowQuantity(row);
        }

        updateSummary();
      });
    });

    unitSelectors.forEach((select) => {
      select.addEventListener('change', () => {
        const row = select.closest('tr');
        if (!row) {
          updateSummary();
          return;
        }

        const packSize = parsePackSize(row);
        let nextUnit = normalizeUnit(select.value);
        if (nextUnit === 'pack' && packSize <= 0) {
          nextUnit = 'each';
          select.value = 'each';
        }

        const currentEach = getRowQuantityEach(row);
        setRowUnit(row, nextUnit);

        const input = row.querySelector('.js-quantity-input');
        if (input) {
          const converted = convertFromEach(currentEach, nextUnit, packSize);
          input.value = formatInputQuantity(converted);
          updateRowQuantity(row);
        }

        updateSummary();
      });
    });

    unitCostInputs.forEach((input) => {
      input.addEventListener('input', () => {
        const row = input.closest('tr');
        if (row) {
          row.setAttribute('data-unit-cost', input.value.trim());
        }
      });
    });

    form.addEventListener('submit', (event) => {
      const selected = checkboxes.filter((checkbox) => checkbox.checked);
      if (selected.length === 0) {
        event.preventDefault();
        window.alert('Select at least one inventory item before generating an order.');
        return;
      }

      let hasInvalid = false;
      selected.forEach((checkbox) => {
        const lineId = checkbox.getAttribute('data-line-id') || checkbox.value;
        const quantityInput = getQuantityInput(lineId);
        if (!quantityInput) {
          hasInvalid = true;
          return;
        }

        const numericValue = Number.parseFloat(quantityInput.value);
        if (!Number.isFinite(numericValue) || numericValue <= 0) {
          quantityInput.setAttribute('aria-invalid', 'true');
          hasInvalid = true;
        }
      });

      if (hasInvalid) {
        event.preventDefault();
        window.alert('Enter a positive order quantity for each selected item.');
      }
    });

    updateSummary();
  }

  function initTable(form) {
    const table = form.querySelector('[data-replenishment-table]');
    if (!(table instanceof HTMLTableElement)) {
      return;
    }

    const tbody = table.querySelector('tbody');
    if (!(tbody instanceof HTMLTableSectionElement)) {
      return;
    }

    const rows = Array.from(tbody.querySelectorAll('tr'));
    if (rows.length === 0) {
      return;
    }

    const originalRows = rows.slice();

    const headers = Array.from(table.querySelectorAll('thead th.sortable'));
    headers.forEach((header) => header.setAttribute('tabindex', '0'));

    let sortKey = '';
    let sortDirection = 'asc';

    function updateHeaderState() {
      headers.forEach((header) => {
        const key = header.getAttribute('data-sort-key');
        if (!key) {
          header.setAttribute('aria-sort', 'none');
          return;
        }

        if (key === sortKey) {
          header.setAttribute('aria-sort', sortDirection);
        } else {
          header.setAttribute('aria-sort', 'none');
        }
      });
    }

    function sortRows() {
      if (!sortKey) {
        rows.sort((a, b) => originalRows.indexOf(a) - originalRows.indexOf(b));
        return;
      }

      const header = headers.find((item) => item.getAttribute('data-sort-key') === sortKey);
      const sortType = header && header.getAttribute('data-sort-type') === 'number' ? 'number' : 'string';
      const multiplier = sortDirection === 'desc' ? -1 : 1;

      rows.sort((a, b) => {
        const aValue = a.getAttribute('data-' + sortKey) || '';
        const bValue = b.getAttribute('data-' + sortKey) || '';

        if (sortType === 'number') {
          const fallback = sortDirection === 'asc' ? Number.POSITIVE_INFINITY : Number.NEGATIVE_INFINITY;
          const aParsed = Number.parseFloat(aValue);
          const bParsed = Number.parseFloat(bValue);
          const aNumber = Number.isNaN(aParsed) ? fallback : aParsed;
          const bNumber = Number.isNaN(bParsed) ? fallback : bParsed;
          if (aNumber === bNumber) {
            return 0;
          }
          return aNumber > bNumber ? multiplier : -multiplier;
        }

        return aValue.toLowerCase().localeCompare(bValue.toLowerCase()) * multiplier;
      });
    }

    function renderRows() {
      rows.forEach((row) => tbody.appendChild(row));
    }

    function applyFilter() {
      const filterInput = form.querySelector('[data-replenishment-filter]');
      const query = filterInput ? filterInput.value.trim().toLowerCase() : '';

      rows.forEach((row) => {
        if (!query) {
          row.removeAttribute('hidden');
          return;
        }

        const item = (row.getAttribute('data-item') || '').toLowerCase();
        const sku = (row.getAttribute('data-sku') || '').toLowerCase();
        const uom = (row.getAttribute('data-uom') || '').toLowerCase();
        const description = row.textContent ? row.textContent.toLowerCase() : '';
        const combined = `${item} ${sku} ${uom} ${description}`;

        if (combined.includes(query)) {
          row.removeAttribute('hidden');
        } else {
          row.setAttribute('hidden', '');
        }
      });
    }

    function setSort(key) {
      if (sortKey === key) {
        sortDirection = sortDirection === 'asc' ? 'desc' : 'asc';
      } else {
        sortKey = key;
        sortDirection = 'asc';
      }

      updateHeaderState();
      sortRows();
      renderRows();
      applyFilter();
    }

    headers.forEach((header) => {
      const key = header.getAttribute('data-sort-key');
      if (!key) {
        return;
      }

      header.addEventListener('click', () => setSort(key));
      header.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault();
          setSort(key);
        }
      });
    });

    const filterInput = form.querySelector('[data-replenishment-filter]');
    if (filterInput instanceof HTMLInputElement) {
      filterInput.addEventListener('input', applyFilter);
    }

    sortRows();
    renderRows();
    applyFilter();
    updateHeaderState();
  }

  function initForms() {
    const forms = document.querySelectorAll('.js-replenishment-form');
    forms.forEach((form) => {
      initForm(form);
      initTable(form);
    });
  }

  function init() {
    initTabs();
    initForms();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
