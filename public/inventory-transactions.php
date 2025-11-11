<?php
declare(strict_types=1);

$app = require __DIR__ . '/../app/config/app.php';
$nav = require __DIR__ . '/../app/data/navigation.php';

require_once __DIR__ . '/../app/helpers/icons.php';
require_once __DIR__ . '/../app/helpers/database.php';
require_once __DIR__ . '/../app/helpers/view.php';
require_once __DIR__ . '/../app/data/inventory.php';

foreach ($nav as &$groupItems) {
    foreach ($groupItems as &$item) {
        $item['active'] = ($item['label'] === 'Inventory Transactions');
    }
}
unset($groupItems, $item);

$databaseConfig = $app['database'];
$dbError = null;
$successMessage = null;
$generalErrors = [];
$errors = [];
$lineErrors = [];
$recentTransactions = [];
$inventoryOptions = [];

$formData = [
    'reference' => '',
    'notes' => '',
    'lines' => [
        [
            'identifier' => '',
            'quantity' => '',
            'direction' => 'issue',
            'note' => '',
        ],
    ],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reference = trim((string) ($_POST['reference'] ?? ''));
    $notes = trim((string) ($_POST['notes'] ?? ''));
    $formData['reference'] = $reference;
    $formData['notes'] = $notes;
    $formData['lines'] = [];

    $submittedLines = $_POST['lines'] ?? [];

    if (is_array($submittedLines)) {
        foreach ($submittedLines as $line) {
            $identifier = isset($line['identifier']) ? trim((string) $line['identifier']) : '';
            $quantity = isset($line['quantity']) ? trim((string) $line['quantity']) : '';
            $direction = isset($line['direction']) && $line['direction'] === 'return' ? 'return' : 'issue';
            $note = isset($line['note']) ? trim((string) $line['note']) : '';

            $formData['lines'][] = [
                'identifier' => $identifier,
                'quantity' => $quantity,
                'direction' => $direction,
                'note' => $note,
            ];
        }
    }

    if ($formData['lines'] === []) {
        $formData['lines'][] = [
            'identifier' => '',
            'quantity' => '',
            'direction' => 'issue',
            'note' => '',
        ];
    }
}

try {
    $db = db($databaseConfig);
} catch (\Throwable $exception) {
    $dbError = $exception->getMessage();
}

if ($dbError === null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($formData['reference'] === '') {
        $errors['reference'] = 'Reference name is required.';
    }

    $pendingChanges = [];
    $transactionLines = [];

    foreach ($formData['lines'] as $index => $line) {
        $identifier = $line['identifier'];
        $quantityRaw = $line['quantity'];
        $direction = $line['direction'] === 'return' ? 'return' : 'issue';
        $note = $line['note'];
        $lineError = [];

        $isBlank = $identifier === '' && $quantityRaw === '' && $note === '';
        if ($isBlank) {
            continue;
        }

        if ($identifier === '') {
            $lineError['identifier'] = 'Enter a SKU, part number, or description.';
        }

        $quantity = filter_var($quantityRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($quantity === false) {
            $lineError['quantity'] = 'Enter a positive quantity.';
        }

        $item = null;

        if ($identifier !== '' && $quantity !== false) {
            try {
                $item = resolveInventoryItemByIdentifier($db, $identifier);
            } catch (\Throwable $exception) {
                $lineError['identifier'] = 'Lookup failed: ' . $exception->getMessage();
            }

            if ($item === null && !isset($lineError['identifier'])) {
                $lineError['identifier'] = 'No inventory item matches that value.';
            }
        }

        if ($item !== null && $quantity !== false) {
            $change = $direction === 'return' ? $quantity : -$quantity;
            $pending = $pendingChanges[$item['id']] ?? 0;
            $projected = $item['stock'] + $pending + $change;

            if ($projected < 0) {
                $available = $item['stock'] + $pending;
                $lineError['quantity'] = 'Only ' . inventoryFormatQuantity(max(0, $available)) . ' units are available for this part.';
            } else {
                $pendingChanges[$item['id']] = $pending + $change;
            }
        }

        if ($lineError !== []) {
            $lineErrors[$index] = $lineError;
            continue;
        }

        $transactionLines[] = [
            'item_id' => $item['id'],
            'quantity_change' => $direction === 'return' ? $quantity : -$quantity,
            'note' => $note !== '' ? $note : null,
        ];
    }

    if ($transactionLines === [] && $lineErrors === []) {
        $errors['lines'] = 'Add at least one inventory line to record the transaction.';
    }

    if ($errors === [] && $lineErrors === []) {
        try {
            recordInventoryTransaction($db, [
                'reference' => $formData['reference'],
                'notes' => $formData['notes'] !== '' ? $formData['notes'] : null,
                'lines' => $transactionLines,
            ]);

            header('Location: inventory-transactions.php?success=recorded');
            exit;
        } catch (\Throwable $exception) {
            $generalErrors[] = 'Unable to save the transaction: ' . $exception->getMessage();
        }
    }
} elseif ($dbError !== null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $generalErrors[] = 'Unable to save the transaction because the database connection failed.';
}

if ($dbError === null) {
    try {
        $inventoryOptions = listInventoryLookupOptions($db);
    } catch (\Throwable $exception) {
        $generalErrors[] = 'Unable to load inventory options: ' . $exception->getMessage();
    }

    try {
        $recentTransactions = loadRecentInventoryTransactions($db, 12);
    } catch (\Throwable $exception) {
        $generalErrors[] = 'Unable to load recent transactions: ' . $exception->getMessage();
    }
}

foreach ($nav as &$groupItems) {
    foreach ($groupItems as &$item) {
        if (($item['label'] ?? '') === 'Database Health') {
            $item['badge'] = $dbError === null ? 'Live' : 'Error';
            $item['badge_class'] = $dbError === null ? 'success' : 'danger';
        }
    }
}
unset($groupItems, $item);

if (isset($_GET['success']) && $_GET['success'] === 'recorded') {
    $successMessage = 'Inventory transaction recorded successfully.';
}

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= e($app['name']) ?> Inventory Transactions</title>
  <link rel="stylesheet" href="css/dashboard.css" />
</head>
<body>
  <div class="layout">
    <?php $sidebarAriaLabel = 'Primary navigation'; require __DIR__ . '/../app/views/partials/sidebar.php'; ?>

    <header class="topbar">
      <form class="search" role="search" aria-label="Inventory search">
        <span aria-hidden="true"><?= icon('search') ?></span>
        <input type="search" name="q" placeholder="Search SKUs, bins, or components" />
      </form>
      <button class="user" type="button">
        <span class="user-avatar" aria-hidden="true"><?= e($app['user']['avatar']) ?></span>
        <span class="user-email"><?= e($app['user']['email']) ?></span>
        <span aria-hidden="true"><?= icon('chev') ?></span>
      </button>
    </header>

    <main class="content">
      <section class="panel" aria-labelledby="transaction-form-title">
        <header>
          <div>
            <h2 id="transaction-form-title">Inventory Transactions</h2>
            <p class="small">Issue or return parts while keeping on-hand quantities accurate.</p>
          </div>
          <a class="badge link" href="#recent-transactions">Recent activity</a>
        </header>

        <?php if ($successMessage !== null): ?>
          <div class="alert success">
            <strong>Saved.</strong>
            <span><?= e($successMessage) ?></span>
          </div>
        <?php endif; ?>

        <?php if ($dbError !== null): ?>
          <div class="alert error">
            <strong>Database error.</strong>
            <span><?= e($dbError) ?></span>
          </div>
        <?php endif; ?>

        <?php foreach ($generalErrors as $message): ?>
          <div class="alert error">
            <strong>Heads up.</strong>
            <span><?= e($message) ?></span>
          </div>
        <?php endforeach; ?>

        <form method="post" class="form" novalidate>
          <div class="field-grid">
            <div class="field">
              <label for="reference">Reference name</label>
              <input type="text" id="reference" name="reference" value="<?= e($formData['reference']) ?>" placeholder="e.g. WO-1045 pull" <?= isset($errors['reference']) ? 'aria-invalid="true"' : '' ?> />
              <?php if (isset($errors['reference'])): ?>
                <p class="field-error"><?= e($errors['reference']) ?></p>
              <?php else: ?>
                <p class="field-help">Include a job number, return slip, or another identifier.</p>
              <?php endif; ?>
            </div>
            <div class="field">
              <label for="notes">Transaction notes <span class="small">(optional)</span></label>
              <textarea id="notes" name="notes" rows="3" placeholder="Additional context for this movement."><?= e($formData['notes']) ?></textarea>
            </div>
          </div>

          <div class="field">
            <label>Parts included</label>
            <p class="field-help">Search by SKU, part number, or description. Use the movement column to deduct from stock or add returns.</p>
          </div>

          <?php if (isset($errors['lines'])): ?>
            <div class="alert error">
              <strong>Line items required.</strong>
              <span><?= e($errors['lines']) ?></span>
            </div>
          <?php endif; ?>

          <div class="transaction-toolbar">
            <div class="small muted">Quantities adjust the on-hand value immediately after submission.</div>
            <button type="button" class="button secondary" data-add-line>
              <span aria-hidden="true">+</span>
              <span>Add part</span>
            </button>
          </div>

          <div class="table-wrapper">
            <table class="table transaction-lines-table">
              <thead>
                <tr>
                  <th scope="col">Part or SKU</th>
                  <th scope="col">Quantity</th>
                  <th scope="col">Movement</th>
                  <th scope="col">Line notes</th>
                  <th scope="col" class="sr-only">Actions</th>
                </tr>
              </thead>
              <tbody id="transaction-lines" data-next-index="<?= count($formData['lines']) ?>">
                <?php foreach ($formData['lines'] as $index => $line): ?>
                  <tr data-line="<?= $index ?>">
                    <td>
                      <div class="field">
                        <label class="sr-only" for="line-<?= $index ?>-identifier">Part or SKU</label>
                        <input
                          type="text"
                          id="line-<?= $index ?>-identifier"
                          name="lines[<?= $index ?>][identifier]"
                          value="<?= e($line['identifier']) ?>"
                          list="inventory-item-options"
                          placeholder="Start typing to search"
                          <?= isset($lineErrors[$index]['identifier']) ? 'aria-invalid="true"' : '' ?>
                        />
                        <?php if (isset($lineErrors[$index]['identifier'])): ?>
                          <p class="field-error"><?= e($lineErrors[$index]['identifier']) ?></p>
                        <?php endif; ?>
                      </div>
                    </td>
                    <td class="numeric">
                      <div class="field">
                        <label class="sr-only" for="line-<?= $index ?>-quantity">Quantity</label>
                        <input
                          type="number"
                          id="line-<?= $index ?>-quantity"
                          name="lines[<?= $index ?>][quantity]"
                          value="<?= e($line['quantity']) ?>"
                          min="1"
                          step="1"
                          placeholder="0"
                          <?= isset($lineErrors[$index]['quantity']) ? 'aria-invalid="true"' : '' ?>
                        />
                        <?php if (isset($lineErrors[$index]['quantity'])): ?>
                          <p class="field-error"><?= e($lineErrors[$index]['quantity']) ?></p>
                        <?php endif; ?>
                      </div>
                    </td>
                    <td>
                      <div class="field">
                        <label class="sr-only" for="line-<?= $index ?>-direction">Movement</label>
                        <select id="line-<?= $index ?>-direction" name="lines[<?= $index ?>][direction]">
                          <option value="issue"<?= $line['direction'] === 'issue' ? ' selected' : '' ?>>Issue (pull from stock)</option>
                          <option value="return"<?= $line['direction'] === 'return' ? ' selected' : '' ?>>Return to stock</option>
                        </select>
                      </div>
                    </td>
                    <td>
                      <div class="field">
                        <label class="sr-only" for="line-<?= $index ?>-note">Line note</label>
                        <input
                          type="text"
                          id="line-<?= $index ?>-note"
                          name="lines[<?= $index ?>][note]"
                          value="<?= e($line['note']) ?>"
                          placeholder="Optional detail for this part"
                        />
                      </div>
                    </td>
                    <td class="line-actions">
                      <button type="button" class="button ghost" data-remove-line>Remove</button>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <div class="field submit">
            <button type="submit" class="button primary">Record transaction</button>
          </div>
        </form>

        <datalist id="inventory-item-options">
          <?php foreach ($inventoryOptions as $option): ?>
            <?php
              $labelParts = [$option['item']];
              if ($option['part_number'] !== '') {
                  $labelParts[] = 'Part ' . $option['part_number'];
              }
              $labelParts[] = 'On hand ' . inventoryFormatQuantity($option['stock']);
              $label = $option['sku'] . ' · ' . implode(' • ', $labelParts);
            ?>
            <option value="<?= e($option['sku']) ?>" label="<?= e($label) ?>"></option>
          <?php endforeach; ?>
        </datalist>
      </section>

      <section class="panel" id="recent-transactions" aria-labelledby="recent-transactions-title">
        <header>
          <h2 id="recent-transactions-title">Recent activity</h2>
          <span class="small">Latest inventory adjustments</span>
        </header>

        <?php if ($recentTransactions === []): ?>
          <p class="small">No inventory transactions recorded yet.</p>
        <?php else: ?>
          <div class="transaction-list">
            <?php foreach ($recentTransactions as $transaction): ?>
              <article class="transaction-card">
                <header>
                  <h3><?= e($transaction['reference']) ?></h3>
                  <?php $timestamp = strtotime($transaction['created_at']); ?>
                  <time datetime="<?= e($transaction['created_at']) ?>">
                    <?= $timestamp !== false ? e(date('M j, Y g:ia', $timestamp)) : e($transaction['created_at']) ?>
                  </time>
                </header>
                <p class="meta">
                  <span><?= e((string) $transaction['line_count']) ?> line<?= $transaction['line_count'] === 1 ? '' : 's' ?></span>
                  <span>
                    <?php $totalChange = (int) $transaction['total_change']; ?>
                    <span class="quantity <?= $totalChange >= 0 ? 'positive' : 'negative' ?>">
                      <?= $totalChange >= 0 ? '+' : '' ?><?= e(inventoryFormatQuantity($totalChange)) ?>
                    </span>
                    units net
                  </span>
                </p>
                <ul>
                  <?php foreach ($transaction['lines'] as $line): ?>
                    <?php $change = (int) $line['quantity_change']; ?>
                    <li>
                      <span class="quantity <?= $change >= 0 ? 'positive' : 'negative' ?>">
                        <?= $change >= 0 ? '+' : '' ?><?= e(inventoryFormatQuantity($change)) ?>
                      </span>
                      × <?= e($line['sku']) ?> — <?= e($line['item']) ?>
                      <span class="muted">(<?= e(inventoryFormatQuantity($line['stock_before'])) ?> → <?= e(inventoryFormatQuantity($line['stock_after'])) ?> on hand)</span>
                      <?php if ($line['note'] !== null && $line['note'] !== ''): ?>
                        <div class="small">Note: <?= e($line['note']) ?></div>
                      <?php endif; ?>
                    </li>
                  <?php endforeach; ?>
                </ul>
                <?php if ($transaction['notes'] !== null && $transaction['notes'] !== ''): ?>
                  <p class="note"><?= e($transaction['notes']) ?></p>
                <?php endif; ?>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>
    </main>
  </div>

  <template id="transaction-line-template">
    <tr data-line="__INDEX__">
      <td>
        <div class="field">
          <label class="sr-only" for="line-__INDEX__-identifier">Part or SKU</label>
          <input
            type="text"
            id="line-__INDEX__-identifier"
            name="lines[__INDEX__][identifier]"
            value=""
            list="inventory-item-options"
            placeholder="Start typing to search"
            data-field="identifier"
          />
        </div>
      </td>
      <td class="numeric">
        <div class="field">
          <label class="sr-only" for="line-__INDEX__-quantity">Quantity</label>
          <input
            type="number"
            id="line-__INDEX__-quantity"
            name="lines[__INDEX__][quantity]"
            value=""
            min="1"
            step="1"
            placeholder="0"
            data-field="quantity"
          />
        </div>
      </td>
      <td>
        <div class="field">
          <label class="sr-only" for="line-__INDEX__-direction">Movement</label>
          <select id="line-__INDEX__-direction" name="lines[__INDEX__][direction]" data-field="direction">
            <option value="issue" selected>Issue (pull from stock)</option>
            <option value="return">Return to stock</option>
          </select>
        </div>
      </td>
      <td>
        <div class="field">
          <label class="sr-only" for="line-__INDEX__-note">Line note</label>
          <input
            type="text"
            id="line-__INDEX__-note"
            name="lines[__INDEX__][note]"
            value=""
            placeholder="Optional detail for this part"
            data-field="note"
          />
        </div>
      </td>
      <td class="line-actions">
        <button type="button" class="button ghost" data-remove-line>Remove</button>
      </td>
    </tr>
  </template>

  <script>
    (function () {
      const container = document.getElementById('transaction-lines');
      const addButton = document.querySelector('[data-add-line]');
      const template = document.getElementById('transaction-line-template');

      if (!container || !addButton || !template) {
        return;
      }

      const registerRow = (row) => {
        const removeButton = row.querySelector('[data-remove-line]');
        if (!removeButton) {
          return;
        }

        removeButton.addEventListener('click', () => {
          if (container.children.length <= 1) {
            row.querySelectorAll('input, select, textarea').forEach((element) => {
              if (element instanceof HTMLSelectElement) {
                element.value = 'issue';
              } else if ('value' in element) {
                element.value = '';
              }
            });
            return;
          }

          row.remove();
        });
      };

      Array.from(container.querySelectorAll('tr')).forEach(registerRow);

      let nextIndex = parseInt(container.dataset.nextIndex || String(container.children.length), 10);
      if (!Number.isFinite(nextIndex)) {
        nextIndex = container.children.length;
      }

      addButton.addEventListener('click', (event) => {
        event.preventDefault();

        const fragment = template.content.cloneNode(true);
        const row = fragment.querySelector('tr');
        const currentIndex = nextIndex;
        nextIndex += 1;
        container.dataset.nextIndex = String(nextIndex);

        if (!row) {
          return;
        }

        row.dataset.line = String(currentIndex);

        fragment.querySelectorAll('[data-field]').forEach((element) => {
          const field = element.getAttribute('data-field');
          if (!field) {
            return;
          }

          if (element.id) {
            element.id = element.id.replace('__INDEX__', String(currentIndex));
          }

          if ('name' in element && typeof element.name === 'string') {
            element.name = element.name.replace('__INDEX__', String(currentIndex));
          }
        });

        container.appendChild(fragment);

        const appendedRow = container.querySelector(`tr[data-line="${currentIndex}"]`);
        if (appendedRow) {
          registerRow(appendedRow);
          const focusTarget = appendedRow.querySelector('input[name^="lines"][name$="[identifier]"]');
          if (focusTarget instanceof HTMLElement) {
            focusTarget.focus();
          }
        }
      });
    })();
  </script>
</body>
</html>
