<?php
/**
 * One-time backfill: assign category_order to any category whose rows have NULL.
 *
 * Why: the SQL that renders categories uses
 *      ORDER BY COALESCE(category_order, 99999), category, ...
 * So categories with NULL category_order fall back to alphabetical sort.
 * After this backfill, every category has a numeric order, and your
 * existing Reorder → Sections drag-and-drop is the only thing that changes it.
 *
 * Rules:
 *  - Categories that already have a non-NULL category_order are NOT touched.
 *  - Categories with NULL category_order get assigned in alphabetical order
 *    (the order they currently appear), spaced by 10 to leave room for new inserts.
 *  - All questions inside each category share the same category_order.
 *  - Safe to re-run: it skips categories that already have an order.
 *
 * Usage: open this file in the browser once, then delete it.
 */

require_once 'config.php';

$pdo = getDBConnection();

$hasCol = false;
try {
    $pdo->query("SELECT category_order FROM evaluation_questions LIMIT 1");
    $hasCol = true;
} catch (Exception $e) {
    die("category_order column is missing on evaluation_questions. Run your earlier migrations first.");
}

// Find categories that have at least one NULL category_order row
$rows = $pdo->query("
    SELECT DISTINCT category
    FROM evaluation_questions
    WHERE category IS NOT NULL
      AND category != ''
      AND category_order IS NULL
    ORDER BY category
")->fetchAll(PDO::FETCH_COLUMN);

if (empty($rows)) {
    echo "<p style='font-family: sans-serif;'>No categories needed backfilling. Everything is already ordered.</p>";
    exit;
}

$nextOrder = (int)$pdo->query("SELECT COALESCE(MAX(category_order), 0) FROM evaluation_questions")->fetchColumn();
$updated = 0;

echo "<pre style='font-family: monospace;'>";
echo "Backfilling " . count($rows) . " categor(ies) in alphabetical order...\n\n";

foreach ($rows as $cat) {
    $nextOrder += 10;
    $stmt = $pdo->prepare("UPDATE evaluation_questions SET category_order = ? WHERE category = ? AND category_order IS NULL");
    $stmt->execute([$nextOrder, $cat]);
    $count = $stmt->rowCount();
    $updated += $count;
    echo "  - {$cat} -> category_order = {$nextOrder} ({$count} row(s))\n";
}

echo "\nDone. Total rows updated: {$updated}\n";
echo "Next free slot for a brand-new category: " . ($nextOrder + 10) . "\n";
echo "\nYou can now use the Reorder -> Sections drag-and-drop on questions.php to rearrange.\n";
echo "Delete this file when finished: run_category_order_backfill.php\n";
echo "</pre>";
