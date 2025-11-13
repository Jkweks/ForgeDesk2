(function () {
  function initTables() {
    const containers = Array.from(document.querySelectorAll('[data-inventory-table]'));
    if (containers.length === 0) {
      return;
    }

    containers.forEach((container) => {
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
      }

      function applyFilters() {
        filteredRows = allRows.filter(({ element }) => {
          return filters.every((input) => {
            const rawValue = input instanceof HTMLInputElement ? input.value : '';
            const value = rawValue.trim().toLowerCase();
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

            return keys.some((key) => {
              const datasetValue = getDatasetValue(element, key);
              return datasetValue.toLowerCase().includes(value);
            });
          });
        });

        currentPage = 1;
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
      sortFilteredRows();
      renderPage();
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initTables);
  } else {
    initTables();
  }
})();
