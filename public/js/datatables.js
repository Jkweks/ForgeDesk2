(function () {
  function parseNumber(value) {
    const parsed = Number.parseFloat(value);
    return Number.isFinite(parsed) ? parsed : 0;
  }

  function normalize(value) {
    return String(value ?? '').trim();
  }

  class DataTable {
    constructor(table, options = {}) {
      this.table = table;
      this.tbody = table.querySelector('tbody');
      this.rows = Array.from(this.tbody?.querySelectorAll('tr') ?? []);
      this.pageSize = Math.max(1, Number.parseInt(options.pageSize ?? table.dataset.defaultPageSize ?? '25', 10));
      this.currentPage = 1;
      this.columns = options.columns || {};
      this.sortKey = options.defaultSortKey || null;
      this.sortDirection = 'asc';
      this.searchTerm = '';
      this.filters = {};
      this.columnFilters = {};
      this.pagination = options.pagination || {};
      this.getValue = options.getValue || this.defaultAccessor.bind(this);
      this.searchAccessor = options.search || this.defaultSearch.bind(this);

      if (options.searchInput) {
        this.attachSearchInput(options.searchInput);
      }

      if (options.columnFilters) {
        Object.entries(options.columnFilters).forEach(([key, input]) => {
          this.attachColumnFilter(input, key);
        });
      }

      if (options.pagination) {
        this.attachPaginationControls(options.pagination.prev, options.pagination.next, options.pagination.status);
      }
      this.wrapResponsive(table, options.responsive);

      this.headers = Array.from(table.querySelectorAll('thead th[data-sort-key]'));
      this.headers.forEach((header) => {
        header.setAttribute('tabindex', '0');
        const key = header.dataset.sortKey || null;
        if (!key) {
          return;
        }

        header.addEventListener('click', () => this.setSort(key));
        header.addEventListener('keydown', (event) => {
          if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            this.setSort(key);
          }
        });
      });

      this.refresh();
    }

    wrapResponsive(table, responsiveFlag) {
      if (responsiveFlag === false) {
        return;
      }

      const wrapper = table.closest('.table-responsive');
      if (!wrapper) {
        const responsive = document.createElement('div');
        responsive.className = 'table-responsive';
        table.parentNode?.insertBefore(responsive, table);
        responsive.appendChild(table);
      }
    }

    defaultAccessor(row, key) {
      if (!row) {
        return '';
      }

      if (key && row.dataset && Object.prototype.hasOwnProperty.call(row.dataset, key)) {
        return normalize(row.dataset[key]);
      }

      const headerIndex = this.headerIndexForKey(key);
      if (headerIndex !== null && row.cells[headerIndex]) {
        const cell = row.cells[headerIndex];
        const ordered = cell.dataset.order ?? cell.textContent;
        return normalize(ordered);
      }

      return normalize(row.textContent);
    }

    defaultSearch(row) {
      return normalize(row.textContent).toLowerCase();
    }

    headerIndexForKey(key) {
      if (!key) {
        return null;
      }

      const header = this.headers.find((item) => (item.dataset.sortKey || '') === key);
      if (!header) {
        return null;
      }

      const headers = Array.from(header.parentElement?.children ?? []);
      return headers.indexOf(header);
    }

    attachSearchInput(input) {
      if (!(input instanceof HTMLInputElement)) {
        return;
      }

      input.addEventListener('input', () => {
        this.setSearchTerm(input.value);
      });
    }

    attachColumnFilter(input, key) {
      if (!(input instanceof HTMLInputElement || input instanceof HTMLSelectElement) || !key) {
        return;
      }

      const handler = () => {
        const value = input instanceof HTMLSelectElement
          ? input.value
          : input.value;
        this.setColumnFilter(key, value);
      };

      input.addEventListener('input', handler);
      input.addEventListener('change', handler);
      handler();
    }

    attachPaginationControls(prev, next, status) {
      this.pagination = {
        prev,
        next,
        status,
      };

      if (prev instanceof HTMLButtonElement) {
        prev.addEventListener('click', () => this.previousPage());
      }

      if (next instanceof HTMLButtonElement) {
        next.addEventListener('click', () => this.nextPage());
      }

      this.updatePagination(this.rows.length, this.rows.length, this.rows.length);
    }

    setSearchTerm(term) {
      this.searchTerm = normalize(term).toLowerCase();
      this.currentPage = 1;
      this.refresh();
    }

    setColumnFilter(key, value) {
      const normalized = normalize(value).toLowerCase();
      if (normalized === '') {
        delete this.columnFilters[key];
      } else {
        this.columnFilters[key] = normalized;
      }

      this.currentPage = 1;
      this.refresh();
    }

    setFilter(name, callback) {
      if (typeof callback === 'function') {
        this.filters[name] = callback;
      } else {
        delete this.filters[name];
      }

      this.currentPage = 1;
      this.refresh();
    }

    setSort(key) {
      if (!key) {
        return;
      }

      if (this.sortKey === key) {
        this.sortDirection = this.sortDirection === 'asc' ? 'desc' : 'asc';
      } else {
        this.sortKey = key;
        this.sortDirection = 'asc';
      }

      this.currentPage = 1;
      this.updateHeaderIndicators();
      this.refresh();
    }

    updateHeaderIndicators() {
      this.headers.forEach((header) => {
        const key = header.dataset.sortKey || '';
        if (key && key === this.sortKey) {
          header.setAttribute('data-sort-direction', this.sortDirection);
          header.setAttribute('aria-sort', this.sortDirection);
        } else if (key) {
          header.removeAttribute('data-sort-direction');
          header.setAttribute('aria-sort', 'none');
        }
      });
    }

    previousPage() {
      if (this.currentPage > 1) {
        this.currentPage -= 1;
        this.refresh();
      }
    }

    nextPage() {
      const filteredLength = this.filteredRowsCache?.length || this.rows.length;
      const totalPages = Math.max(1, Math.ceil(filteredLength / this.pageSize));
      if (this.currentPage < totalPages) {
        this.currentPage += 1;
        this.refresh();
      }
    }

    matchesFilters(row) {
      const searchValue = this.searchAccessor(row);
      if (this.searchTerm && !searchValue.includes(this.searchTerm)) {
        return false;
      }

      const filterKeys = Object.keys(this.columnFilters);
      if (filterKeys.length > 0) {
        const matchesColumns = filterKeys.every((key) => {
          const value = normalize(this.getValue(row, key, this.headerIndexForKey(key))).toLowerCase();
          return value.includes(this.columnFilters[key]);
        });

        if (!matchesColumns) {
          return false;
        }
      }

      const customFilters = Object.values(this.filters);
      return customFilters.every((filter) => (typeof filter === 'function' ? filter(row) : true));
    }

    sortedRows(rows) {
      const { sortKey } = this;
      if (!sortKey) {
        return [...rows];
      }

      const column = this.columns[sortKey] || {};
      const sortType = column.type === 'number' ? 'number' : 'string';
      const multiplier = this.sortDirection === 'desc' ? -1 : 1;

      return [...rows].sort((a, b) => {
        const aValue = this.getValue(a, sortKey, this.headerIndexForKey(sortKey));
        const bValue = this.getValue(b, sortKey, this.headerIndexForKey(sortKey));

        if (sortType === 'number') {
          return (parseNumber(aValue) - parseNumber(bValue)) * multiplier;
        }

        return aValue.toLowerCase().localeCompare(bValue.toLowerCase(), undefined, {
          numeric: true,
          sensitivity: 'base',
        }) * multiplier;
      });
    }

    updatePagination(totalRows, startIndex, visibleCount) {
      const status = this.pagination.status;
      const prev = this.pagination.prev;
      const next = this.pagination.next;

      const startDisplay = totalRows === 0 ? 0 : startIndex + 1;
      const endDisplay = totalRows === 0 ? 0 : startIndex + visibleCount;

      if (status instanceof HTMLElement) {
        const label = totalRows === 0
          ? 'No matching records'
          : `Showing ${startDisplay}–${endDisplay} of ${totalRows}`;
        status.textContent = label;
      }

      const onFirstPage = this.currentPage <= 1;
      const onLastPage = startIndex + visibleCount >= totalRows;

      if (prev instanceof HTMLButtonElement) {
        prev.disabled = onFirstPage;
        prev.closest('.page-item')?.classList.toggle('disabled', onFirstPage);
      }

      if (next instanceof HTMLButtonElement) {
        next.disabled = onLastPage;
        next.closest('.page-item')?.classList.toggle('disabled', onLastPage);
      }
    }

    refresh() {
      if (!(this.tbody instanceof HTMLTableSectionElement)) {
        return;
      }

      const filtered = this.rows.filter((row) => this.matchesFilters(row));
      this.filteredRowsCache = filtered;
      const sorted = this.sortedRows(filtered);
      const startIndex = (this.currentPage - 1) * this.pageSize;
      const pageRows = sorted.slice(startIndex, startIndex + this.pageSize);

      this.tbody.innerHTML = '';
      pageRows.forEach((row) => this.tbody.appendChild(row));
      this.updatePagination(sorted.length, startIndex, pageRows.length);
    }
  }

  const registry = new WeakMap();

  const ForgeDataTables = {
    create(table, options = {}) {
      if (!(table instanceof HTMLTableElement)) {
        return null;
      }

      const instance = new DataTable(table, options);
      registry.set(table, instance);
      return instance;
    },
    get(table) {
      return registry.get(table) || null;
    },
  };

  window.ForgeDataTables = ForgeDataTables;
})();
