(function () {
  function parseQuantity(value) {
    const number = Number.parseFloat(value);
    return Number.isFinite(number) ? number : 0;
  }

  function setRowEnabled(row, enabled) {
    const hasOutstanding = row.getAttribute('data-has-outstanding') === '1';
    const inputs = Array.from(row.querySelectorAll('[data-backorder]'));

    inputs.forEach((input) => {
      const baseDisabled = input.getAttribute('data-base-disabled') === '1';
      const shouldDisable = !enabled || baseDisabled || !hasOutstanding;
      input.disabled = shouldDisable;

      if (shouldDisable) {
        input.classList.remove('is-invalid');
        input.setCustomValidity('');
      }
    });

    if (!enabled) {
      row.classList.remove('over-allocated');
    }
  }

  function initSearch(root) {
    const searchInput = document.querySelector('[data-receiving-search]');
    if (!searchInput) {
      return;
    }

    const orders = Array.from(root.querySelectorAll('[data-order]'));
    if (orders.length === 0) {
      return;
    }

    function applyFilter() {
      const query = searchInput.value.trim().toLowerCase();

      orders.forEach((order) => {
        const title = (order.getAttribute('data-order-title') || '').toLowerCase();
        const supplier = (order.getAttribute('data-order-supplier') || '').toLowerCase();
        const text = `${title} ${supplier}`;

        if (query === '' || text.includes(query)) {
          order.removeAttribute('hidden');
        } else {
          order.setAttribute('hidden', '');
        }
      });
    }

    searchInput.addEventListener('input', applyFilter);
  }

  function initForm(form) {
    const rows = Array.from(form.querySelectorAll('[data-line-id]'));
    const table = form.querySelector('[data-receiving-lines]');
    const selectAll = table ? table.querySelector('[data-table-select-all]') : null;

    function updateRow(row, changedInput) {
      const outstanding = parseQuantity(row.getAttribute('data-outstanding-value') || '0');
      const backorderInput = row.querySelector('[data-backorder]');
      const receiveNowField = row.querySelector('[data-receive-now]');
      const checkbox = row.querySelector('[data-row-checkbox]');
      const isActive = !(checkbox instanceof HTMLInputElement) || checkbox.checked;

      if (!isActive) {
        setRowEnabled(row, false);
        return;
      }

      const backorder = backorderInput ? parseQuantity(backorderInput.value) : 0;
      const receiveNow = Math.max(outstanding - backorder, 0);
      const limit = outstanding + 0.0005;
      const message = backorder > limit ? 'Backordered quantity exceeds outstanding amount.' : '';

      if (backorderInput) {
        backorderInput.setCustomValidity(message);
        if (changedInput === backorderInput && message !== '') {
          backorderInput.classList.add('is-invalid');
        } else {
          backorderInput.classList.remove('is-invalid');
        }
      }

      if (receiveNowField) {
        receiveNowField.textContent = receiveNow.toFixed(3).replace(/\.?0+$/, '');
      }

      row.setAttribute('data-receive-now', receiveNow.toString());

      if (message !== '') {
        row.classList.add('over-allocated');
      } else {
        row.classList.remove('over-allocated');
      }
    }

    function syncSelectAllState() {
      if (!(selectAll instanceof HTMLInputElement)) {
        return;
      }

      const checkboxes = rows
        .map((row) => row.querySelector('[data-row-checkbox]'))
        .filter((box) => box instanceof HTMLInputElement);

      if (checkboxes.length === 0) {
        selectAll.checked = false;
        selectAll.indeterminate = false;
        return;
      }

      const checkedCount = checkboxes.filter((box) => box.checked).length;
      selectAll.checked = checkedCount === checkboxes.length;
      selectAll.indeterminate = checkedCount > 0 && checkedCount < checkboxes.length;
    }

    rows.forEach((row) => {
      const backorderInput = row.querySelector('[data-backorder]');
      const checkbox = row.querySelector('[data-row-checkbox]');
      const outstanding = parseQuantity(row.getAttribute('data-outstanding-value') || '0');

      setRowEnabled(row, !(checkbox instanceof HTMLInputElement) || checkbox.checked);

      if (checkbox instanceof HTMLInputElement) {
        checkbox.addEventListener('change', () => {
          setRowEnabled(row, checkbox.checked);
          syncSelectAllState();
          updateRow(row, null);
        });
      }

      if (backorderInput) {
        backorderInput.addEventListener('focus', () => {
          if (backorderInput.value.trim() === '' && outstanding > 0) {
            backorderInput.value = '0';
            updateRow(row, backorderInput);
          }
        });
        backorderInput.addEventListener('input', () => updateRow(row, backorderInput));
      }

      updateRow(row, null);
    });

    if (selectAll instanceof HTMLInputElement) {
      selectAll.addEventListener('change', () => {
        rows.forEach((row) => {
          const checkbox = row.querySelector('[data-row-checkbox]');
          if (checkbox instanceof HTMLInputElement) {
            checkbox.checked = selectAll.checked;
          }

          setRowEnabled(row, selectAll.checked);
          updateRow(row, null);
        });

        syncSelectAllState();
      });
    }

    syncSelectAllState();

    form.addEventListener('submit', (event) => {
      let hasChanges = false;
      rows.forEach((row) => {
        const backorderInput = row.querySelector('[data-backorder]');
        const checkbox = row.querySelector('[data-row-checkbox]');

        if (checkbox instanceof HTMLInputElement && !checkbox.checked) {
          updateRow(row, null);
          return;
        }

        const outstanding = parseQuantity(row.getAttribute('data-outstanding-value') || '0');
        const backorder = backorderInput ? parseQuantity(backorderInput.value) : 0;
        const receiveNow = Math.max(outstanding - backorder, 0);

        if (receiveNow > 0) {
          hasChanges = true;
        }

        updateRow(row, null);
      });

      if (!hasChanges) {
        event.preventDefault();
        window.alert('Enter backordered quantities that leave received material before submitting.');
      }
    });
  }

  function init() {
    const root = document.querySelector('[data-receiving]');
    if (!root) {
      return;
    }

    initSearch(root);

    const form = root.querySelector('[data-receiving-form]');
    if (form instanceof HTMLFormElement) {
      initForm(form);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
