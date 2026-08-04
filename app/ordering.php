<?php
/**
 * Manual ordering helper.
 *
 * `sort_order` exists on guides, testimonials, tours and categories and drives
 * the order they appear in on the public site — but the old admin exposed no
 * way to set it anywhere except categories, where it was a bare number box.
 * This gives the list screens working move-up / move-down controls.
 */

/**
 * Swap a row with its neighbour in the current display order.
 *
 * Rows that have never been ordered all share sort_order = 0, so we first
 * renumber the whole set into its current visible sequence and then swap two
 * adjacent entries. That makes the control predictable from the very first
 * click, whatever state the data starts in.
 *
 * @param string $table   Table name (trusted, never user input).
 * @param string $orderBy The list's own ORDER BY, minus `sort_order`.
 */
function reorder_move(string $table, int $id, string $direction, string $orderBy = 'id'): bool
{
    $rows = db_all("SELECT id FROM `$table` ORDER BY sort_order, $orderBy");
    $ids  = array_map(static fn($r) => (int) $r['id'], $rows);

    $pos = array_search($id, $ids, true);
    if ($pos === false) { return false; }

    $target = $direction === 'up' ? $pos - 1 : $pos + 1;
    if ($target < 0 || $target >= count($ids)) { return false; }

    [$ids[$pos], $ids[$target]] = [$ids[$target], $ids[$pos]];

    foreach ($ids as $i => $rowId) {
        db_run("UPDATE `$table` SET sort_order = ? WHERE id = ?", [$i + 1, $rowId]);
    }
    return true;
}
