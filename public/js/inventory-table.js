(function () {
  function initTables() {
    const containers = Array.from(document.querySelectorAll('[data-inventory-table]'));
    if (containers.length === 0) {
      return;
    }

    containers.forEach((container, containerIndex) => {
      const table = container.querySelector('table');
      if (!(table instanceof HTMLTableElement)) {
        return;
      }

      const tbody = table.querySelector('tbody');
      if (!(tbody instanceof HTMLTableSectionElement)) {
        return;
      }

      const rowElements = Array.from(tbody.querySelectorAll('tr')).filter((row) => row.dataset.item || row.dataset.itemId);
      if (rowElements.length === 0) {
        return;
      }

      const headers = Array.from(table.querySelectorAll('thead th.sortable'));
      const headerMeta = headers.map((header) => {
        header.setAttribute('tabindex', '0');
        return {
          element: header,
          key: header.dataset.sortKey || '',
          type: header.dataset.sortType || 'string',
        };
      });

      const filters = Array.from(container.querySelectorAll('.column-filter'));
      const locationFilters = Array.from(container.querySelectorAll('[data-location-filter]'));
      const pagination = container.querySelector('[data-pagination]');
      const prevButton = pagination instanceof HTMLElement ? pagination.querySelector('[data-pagination-prev]') : null;
      const nextButton = pagination instanceof HTMLElement ? pagination.querySelector('[data-pagination-next]') : null;
      const statusElement = pagination instanceof HTMLElement ? pagination.querySelector('[data-pagination-status]') : null;
      const pageSizeRaw = container.dataset.pageSize || '50';
      const pageSize = Math.max(1, parseInt(pageSizeRaw, 10));

      const allRows = rowElements.map((element, index) => ({
        element,
        originalIndex: index,
      }));

      let sortKey = '';
      let sortDirection = 'asc';
      let filteredRows = [...allRows];
      let currentPage = 1;

      const tableId = table.id || container.id || '';
      const storageKey = tableId
        ? `inventoryTableState:${tableId}`
        : `inventoryTableState:index-${containerIndex}`;

      function loadState() {
        if (typeof window === 'undefined' || !('localStorage' in window)) {
          return null;
        }

        try {
          const raw = window.localStorage.getItem(storageKey);
          if (!raw) {
            return null;
          }

          const parsed = JSON.parse(raw);
          return typeof parsed === 'object' && parsed !== null ? parsed : null;
        } catch (error) {
          return null;
        }
      }

      function persistState() {
        if (typeof window === 'undefined' || !('localStorage' in window)) {
          return;
        }

        try {
          const data = {
            sortKey,
            sortDirection,
            currentPage,
            filters: filters.map((input, index) => ({
              key: input.dataset.key || '',
              index,
              value: input instanceof HTMLInputElement ? input.value : '',
            })),
          };

          const hasFilterValues = data.filters.some((entry) => entry.value.trim() !== '');
          const shouldStore = hasFilterValues || data.sortKey !== '' || data.currentPage > 1;

          if (!shouldStore) {
            window.localStorage.removeItem(storageKey);
            return;
          }

          window.localStorage.setItem(storageKey, JSON.stringify(data));
        } catch (error) {
          // Ignore storage errors.
        }
      }

      function restoreFilters(savedFilters) {
        if (!Array.isArray(savedFilters)) {
          return;
        }

        savedFilters.forEach((saved) => {
          if (!saved || typeof saved !== 'object') {
            return;
          }

          const target = filters.find((input, index) => {
            const key = input.dataset.key || '';
            if (saved.key && saved.key === key) {
              return true;
            }

            if (typeof saved.index === 'number' && saved.index === index) {
              return true;
            }

            return false;
          });

          if (target instanceof HTMLInputElement) {
            target.value = typeof saved.value === 'string' ? saved.value : '';
          }
        });
      }

      function syncLocationFilterFromInput(filterContainer) {
        if (!(filterContainer instanceof HTMLElement)) {
          return;
        }

        const input = filterContainer.querySelector('.column-filter[data-filter-type="tokens"]');
        const toggle = filterContainer.querySelector('[data-location-filter-toggle]');
        const label = filterContainer.querySelector('.location-filter__label');
        const modal = filterContainer.querySelector('[data-location-filter-modal]');
        const binCheckboxes = input
          ? Array.from((modal || filterContainer).querySelectorAll('input[type="checkbox"][data-location-node="bin"]'))
          : [];
        const groupCheckboxes = input
          ? Array.from((modal || filterContainer).querySelectorAll('input[type="checkbox"][data-location-group]'))
          : [];

        if (!(input instanceof HTMLInputElement)) {
          return;
        }

        const tokens = input.value
          .split(',')
          .map((token) => token.trim())
          .filter((token) => token !== '');

        binCheckboxes.forEach((bin) => {
          bin.checked = tokens.includes(bin.value);
        });

        function getChildIds(node) {
          const raw = node.dataset.childIds;
          if (!raw) {
            return [];
          }

          return raw
            .split(',')
            .map((value) => value.trim())
            .filter((value) => value !== '');
        }

        function updateGroupStates() {
          groupCheckboxes.forEach((group) => {
            const childIds = getChildIds(group);
            const matchingBins = binCheckboxes.filter((bin) => childIds.includes(bin.value));
            const checkedCount = matchingBins.filter((bin) => bin.checked).length;

            group.checked = checkedCount === matchingBins.length && matchingBins.length > 0;
            group.indeterminate = checkedCount > 0 && checkedCount < matchingBins.length;
          });
        }

        function updateLabel() {
          const active = binCheckboxes.filter((bin) => bin.checked).length;
          const text = active === 0 ? 'All locations' : `${active} selected`;
          if (label instanceof HTMLElement) {
            label.textContent = text;
          } else if (toggle instanceof HTMLButtonElement) {
            toggle.textContent = text;
          }
        }

        updateGroupStates();
        updateLabel();
      }

      function initLocationFilter(filterContainer) {
        if (!(filterContainer instanceof HTMLElement)) {
          return;
        }

        const toggle = filterContainer.querySelector('[data-location-filter-toggle]');
        const label = filterContainer.querySelector('.location-filter__label');
        const modal = filterContainer.querySelector('[data-location-filter-modal]');
        const backdrop = filterContainer.querySelector('[data-location-filter-backdrop]');
        const closeButton = filterContainer.querySelector('[data-location-filter-close]');
        const applyButton = filterContainer.querySelector('[data-location-filter-apply]');
        const clearButton = filterContainer.querySelector('[data-location-filter-clear]');
        const input = filterContainer.querySelector('.column-filter[data-filter-type="tokens"]');
        const binCheckboxes = input
          ? Array.from(filterContainer.querySelectorAll('input[type="checkbox"][data-location-node="bin"]'))
          : [];
        const groupCheckboxes = input
          ? Array.from(filterContainer.querySelectorAll('input[type="checkbox"][data-location-group]'))
          : [];

        if (
          !(toggle instanceof HTMLButtonElement)
          || !(modal instanceof HTMLElement)
          || !(input instanceof HTMLInputElement)
        ) {
          return;
        }

        function getChildIds(node) {
          const raw = node.dataset.childIds;
          if (!raw) {
            return [];
          }

          return raw
            .split(',')
            .map((value) => value.trim())
            .filter((value) => value !== '');
        }

        function updateGroupStates() {
          groupCheckboxes.forEach((group) => {
            const childIds = getChildIds(group);
            const matchingBins = binCheckboxes.filter((bin) => childIds.includes(bin.value));
            const checkedCount = matchingBins.filter((bin) => bin.checked).length;

            group.checked = checkedCount === matchingBins.length && matchingBins.length > 0;
            group.indeterminate = checkedCount > 0 && checkedCount < matchingBins.length;
          });
        }

        function updateLabel() {
          const active = binCheckboxes.filter((bin) => bin.checked).length;
          const text = active === 0 ? 'All locations' : `${active} selected`;
          if (label instanceof HTMLElement) {
            label.textContent = text;
          } else {
            toggle.textContent = text;
          }
        }

        function syncInputFromSelection() {
          const selected = binCheckboxes
            .filter((bin) => bin.checked)
            .map((bin) => bin.value)
            .filter((value) => value !== '');

          input.value = selected.join(',');
          updateGroupStates();
          updateLabel();
          applyFilters({ preservePage: true });
          persistState();
        }

        function setModal(open) {
          if (open) {
            modal.removeAttribute('hidden');
            toggle.setAttribute('aria-expanded', 'true');
          } else {
            modal.setAttribute('hidden', 'hidden');
            toggle.setAttribute('aria-expanded', 'false');
          }
        }

        function clearSelections() {
          binCheckboxes.forEach((bin) => {
            bin.checked = false;
          });
          syncInputFromSelection();
        }

        toggle.addEventListener('click', (event) => {
          event.preventDefault();
          const isOpen = toggle.getAttribute('aria-expanded') === 'true';
          setModal(!isOpen);
        });

        if (closeButton instanceof HTMLElement) {
          closeButton.addEventListener('click', (event) => {
            event.preventDefault();
            setModal(false);
          });
        }

        if (backdrop instanceof HTMLElement) {
          backdrop.addEventListener('click', () => {
            setModal(false);
          });
        }

        if (applyButton instanceof HTMLElement) {
          applyButton.addEventListener('click', (event) => {
            event.preventDefault();
            setModal(false);
            syncInputFromSelection();
          });
        }

        if (clearButton instanceof HTMLElement) {
          clearButton.addEventListener('click', (event) => {
            event.preventDefault();
            clearSelections();
          });
        }

        groupCheckboxes.forEach((group) => {
          group.addEventListener('change', (event) => {
            const target = event.target;
            if (!(target instanceof HTMLInputElement)) {
              return;
            }

            const childIds = getChildIds(target);
            const shouldCheck = target.checked;

            binCheckboxes.forEach((bin) => {
              if (childIds.includes(bin.value)) {
                bin.checked = shouldCheck;
              }
            });

            updateGroupStates();
            updateLabel();
          });
        });

        binCheckboxes.forEach((bin) => {
          bin.addEventListener('change', () => {
            updateGroupStates();
            updateLabel();
          });
        });

        syncLocationFilterFromInput(filterContainer);
      }

      const savedState = loadState();
      if (savedState) {
        if (typeof savedState.sortKey === 'string') {
          sortKey = savedState.sortKey;
        }

        if (savedState.sortDirection === 'asc' || savedState.sortDirection === 'desc') {
          sortDirection = savedState.sortDirection;
        }

        if (typeof savedState.currentPage === 'number' && Number.isFinite(savedState.currentPage)) {
          currentPage = Math.max(1, Math.floor(savedState.currentPage));
        }

        restoreFilters(savedState.filters);
      }

      locationFilters.forEach((filter) => {
        syncLocationFilterFromInput(filter);
        initLocationFilter(filter);
      });

      function getDatasetValue(element, key) {
        if (!key) {
          return '';
        }

        const raw = element.dataset[key];
        return typeof raw === 'string' ? raw : '';
      }

      function updateHeaderAria() {
        headerMeta.forEach(({ element, key }) => {
          if (!key) {
            element.setAttribute('aria-sort', 'none');
            return;
          }

          if (key === sortKey) {
            element.setAttribute('aria-sort', sortDirection);
          } else {
            element.setAttribute('aria-sort', 'none');
          }
        });
      }

      function sortFilteredRows() {
        if (!sortKey) {
          filteredRows.sort((a, b) => a.originalIndex - b.originalIndex);
          return;
        }

        const header = headerMeta.find((meta) => meta.key === sortKey);
        const sortType = header?.type === 'number' ? 'number' : 'string';
        const directionMultiplier = sortDirection === 'asc' ? 1 : -1;

        filteredRows.sort((a, b) => {
          const aValue = getDatasetValue(a.element, sortKey);
          const bValue = getDatasetValue(b.element, sortKey);

          if (sortType === 'number') {
            const aNum = parseFloat(aValue);
            const bNum = parseFloat(bValue);
            const safeANum = Number.isNaN(aNum) ? 0 : aNum;
            const safeBNum = Number.isNaN(bNum) ? 0 : bNum;
            if (safeANum !== safeBNum) {
              return (safeANum - safeBNum) * directionMultiplier;
            }
          } else {
            const comparison = aValue.toLowerCase().localeCompare(bValue.toLowerCase(), undefined, {
              numeric: true,
              sensitivity: 'base',
            });
            if (comparison !== 0) {
              return comparison * directionMultiplier;
            }
          }

          return a.originalIndex - b.originalIndex;
        });

        filteredRows.forEach(({ element }) => {
          tbody.appendChild(element);
        });
      }

      function renderPage() {
        const totalRows = filteredRows.length;
        const totalPages = totalRows === 0 ? 1 : Math.ceil(totalRows / pageSize);
        currentPage = Math.min(Math.max(currentPage, 1), totalPages);

        allRows.forEach(({ element }) => {
          element.style.display = 'none';
        });

        if (totalRows === 0) {
          if (statusElement) {
            statusElement.textContent = 'No results';
          }
          if (prevButton instanceof HTMLButtonElement) {
            prevButton.disabled = true;
          }
          if (nextButton instanceof HTMLButtonElement) {
            nextButton.disabled = true;
          }
          persistState();
          return;
        }

        const startIndex = (currentPage - 1) * pageSize;
        const endIndex = Math.min(startIndex + pageSize, totalRows);
        for (let index = startIndex; index < endIndex; index += 1) {
          const row = filteredRows[index];
          if (row && row.element) {
            row.element.style.display = '';
          }
        }

        if (statusElement) {
          statusElement.textContent = `Page ${currentPage} of ${totalPages}`;
        }
        if (prevButton instanceof HTMLButtonElement) {
          prevButton.disabled = currentPage <= 1;
        }
        if (nextButton instanceof HTMLButtonElement) {
          nextButton.disabled = currentPage >= totalPages;
        }

        persistState();
      }

      function applyFilters(options) {
        filteredRows = allRows.filter(({ element }) => {
          return filters.every((input) => {
            const rawValue = input instanceof HTMLInputElement ? input.value : '';
            const value = rawValue.trim();
            if (value === '') {
              return true;
            }

            const keys = [];
            if (input.dataset.key) {
              keys.push(input.dataset.key);
            }
            if (input.dataset.altKeys) {
              input.dataset.altKeys.split(',').forEach((alt) => {
                const trimmed = alt.trim();
                if (trimmed !== '') {
                  keys.push(trimmed);
                }
              });
            }

            if (keys.length === 0) {
              return true;
            }

            const filterType = input.dataset.filterType || 'text';
            if (filterType === 'tokens') {
              const tokens = value
                .split(',')
                .map((token) => token.trim().toLowerCase())
                .filter((token) => token !== '');

              if (tokens.length === 0) {
                return true;
              }

              return keys.some((key) => {
                const datasetValue = getDatasetValue(element, key);
                const rowTokens = datasetValue
                  .split(',')
                  .map((token) => token.trim().toLowerCase())
                  .filter((token) => token !== '');

                if (rowTokens.length === 0) {
                  return false;
                }

                return tokens.some((token) => rowTokens.includes(token));
              });
            }

            const normalizedValue = value.toLowerCase();

            return keys.some((key) => {
              const datasetValue = getDatasetValue(element, key);
              return datasetValue.toLowerCase().includes(normalizedValue);
            });
          });
        });

        const preservePage = typeof options === 'object' && options !== null && options.preservePage === true;

        if (!preservePage) {
          currentPage = 1;
        }

        sortFilteredRows();
        renderPage();
      }

      headers.forEach((header) => {
        header.addEventListener('click', () => {
          const key = header.dataset.sortKey || '';
          if (!key) {
            return;
          }

          if (sortKey === key) {
            sortDirection = sortDirection === 'asc' ? 'desc' : 'asc';
          } else {
            sortKey = key;
            sortDirection = 'asc';
          }

          updateHeaderAria();
          sortFilteredRows();
          renderPage();
        });

        header.addEventListener('keydown', (event) => {
          if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            header.click();
          }
        });
      });

      filters.forEach((input) => {
        input.addEventListener('input', () => {
          applyFilters();
        });
      });

      if (prevButton instanceof HTMLButtonElement) {
        prevButton.addEventListener('click', () => {
          if (currentPage > 1) {
            currentPage -= 1;
            renderPage();
          }
        });
      }

      if (nextButton instanceof HTMLButtonElement) {
        nextButton.addEventListener('click', () => {
          const totalPages = filteredRows.length === 0 ? 1 : Math.ceil(filteredRows.length / pageSize);
          if (currentPage < totalPages) {
            currentPage += 1;
            renderPage();
          }
        });
      }

      updateHeaderAria();

      if (savedState) {
        applyFilters({ preservePage: true });
      } else {
        sortFilteredRows();
        renderPage();
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initTables);
  } else {
    initTables();
  }
})();
