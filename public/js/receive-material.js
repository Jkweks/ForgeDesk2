(function () {
  function parseQuantity(value) {
    const number = Number.parseFloat(value);
    return Number.isFinite(number) ? number : 0;
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

    function updateRow(row, changedInput) {
      const outstanding = parseQuantity(row.getAttribute('data-outstanding-value') || '0');
      const receiveInput = row.querySelector('[data-receive]');
      const cancelInput = row.querySelector('[data-cancel]');
      const receive = receiveInput ? parseQuantity(receiveInput.value) : 0;
      const cancel = cancelInput ? parseQuantity(cancelInput.value) : 0;
      const total = receive + cancel;
      const limit = outstanding + 0.0005;
      const message = total > limit ? 'Quantities exceed outstanding amount.' : '';

      if (receiveInput) {
        receiveInput.setCustomValidity(message);
        if (changedInput === receiveInput && message !== '') {
          receiveInput.classList.add('is-invalid');
        } else {
          receiveInput.classList.remove('is-invalid');
        }
      }
      if (cancelInput) {
        cancelInput.setCustomValidity(message);
        if (changedInput === cancelInput && message !== '') {
          cancelInput.classList.add('is-invalid');
        } else {
          cancelInput.classList.remove('is-invalid');
        }
      }

      if (message !== '') {
        row.classList.add('over-allocated');
      } else {
        row.classList.remove('over-allocated');
      }
    }

    rows.forEach((row) => {
      const receiveInput = row.querySelector('[data-receive]');
      const cancelInput = row.querySelector('[data-cancel]');
      const outstanding = parseQuantity(row.getAttribute('data-outstanding-value') || '0');

      if (receiveInput) {
        receiveInput.addEventListener('focus', () => {
          if (receiveInput.value.trim() === '' && outstanding > 0) {
            receiveInput.value = outstanding.toString();
            updateRow(row, receiveInput);
          }
        });
        receiveInput.addEventListener('input', () => updateRow(row, receiveInput));
      }

      if (cancelInput) {
        cancelInput.addEventListener('input', () => updateRow(row, cancelInput));
      }

      updateRow(row, null);
    });

    form.addEventListener('submit', (event) => {
      let hasChanges = false;
      rows.forEach((row) => {
        const receiveInput = row.querySelector('[data-receive]');
        const cancelInput = row.querySelector('[data-cancel]');
        const receive = receiveInput ? parseQuantity(receiveInput.value) : 0;
        const cancel = cancelInput ? parseQuantity(cancelInput.value) : 0;

        if (receive > 0 || cancel > 0) {
          hasChanges = true;
        }

        updateRow(row, null);
      });

      if (!hasChanges) {
        event.preventDefault();
        window.alert('Enter a quantity to receive or cancel before submitting.');
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
