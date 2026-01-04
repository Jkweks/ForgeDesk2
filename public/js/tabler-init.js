(function () {
  document.addEventListener('DOMContentLoaded', function () {
    const bootstrapLib = window.bootstrap;
    if (!bootstrapLib) {
      return;
    }

    document.querySelectorAll('[data-tabler-autoshow="modal"]').forEach((modalEl) => {
      const modal = new bootstrapLib.Modal(modalEl, { backdrop: true, focus: true });
      modal.show();

      const closeUrl = modalEl.getAttribute('data-tabler-close-url') || modalEl.getAttribute('data-close-url');
      if (closeUrl) {
        modalEl.addEventListener('hidden.bs.modal', () => {
          window.location.href = closeUrl;
        });
      }
    });

    document.querySelectorAll('[data-tabler-toggle="drawer"]').forEach((trigger) => {
      const targetId = trigger.getAttribute('data-tabler-target');
      if (!targetId) {
        return;
      }
      const drawerEl = document.querySelector(targetId);
      if (!drawerEl) {
        return;
      }
      const drawer = new bootstrapLib.Offcanvas(drawerEl);
      trigger.addEventListener('click', (event) => {
        event.preventDefault();
        drawer.toggle();
      });
    });
  });
})();
