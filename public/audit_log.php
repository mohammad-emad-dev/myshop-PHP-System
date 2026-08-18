<?php
require_once '../includes/functions.php';
start_secure_session();
require_once '../config/db.php';

verify_login();
require_admin();

$page_size = normalize_page_size($_GET['page_size'] ?? 25, 25, [10, 25, 50, 100]);
$page = normalize_page_number($_GET['page'] ?? 1);
$action = is_string($_GET['action'] ?? null) ? truncate_list_search($_GET['action'], 80) : '';
$entity_type = is_string($_GET['entity_type'] ?? null) ? truncate_list_search($_GET['entity_type'], 50) : '';
$outcome = is_string($_GET['outcome'] ?? null) && in_array($_GET['outcome'], ['success', 'failure'], true)
    ? $_GET['outcome']
    : '';
$actor_staff_id = filter_var($_GET['actor'] ?? null, FILTER_VALIDATE_INT);
$actor_staff_id = $actor_staff_id !== false && $actor_staff_id > 0 ? (int)$actor_staff_id : null;

$valid_date = static function ($value) {
    if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
        return '';
    }
    $date = DateTime::createFromFormat('!Y-m-d', $value);
    $errors = DateTime::getLastErrors();
    if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
        return '';
    }
    return $date->format('Y-m-d') === $value ? $value : '';
};

$date_from = $valid_date($_GET['date_from'] ?? '');
$date_to = $valid_date($_GET['date_to'] ?? '');
$filters = [
    'action' => $action,
    'actor_staff_id' => $actor_staff_id,
    'entity_type' => $entity_type,
    'outcome' => $outcome,
    'date_from' => $date_from,
    'date_to' => $date_to,
];

$total_events = count_audit_logs($conn, $filters);
$total_pages = max(1, (int)ceil($total_events / $page_size));
if ($page > $total_pages) {
    $page = $total_pages;
}
$offset = ($page - 1) * $page_size;
$events = get_audit_logs_page($conn, $filters, $page_size, $offset);
$range_start = $total_events > 0 ? $offset + 1 : 0;
$range_end = $total_events > 0 ? min($offset + count($events), $total_events) : 0;

$page_url = static function ($target_page) use ($filters, $page_size) {
    $query = [
        'page' => max(1, (int)$target_page),
        'page_size' => $page_size,
    ];
    foreach ([
        'action' => 'action',
        'actor_staff_id' => 'actor',
        'entity_type' => 'entity_type',
        'outcome' => 'outcome',
        'date_from' => 'date_from',
        'date_to' => 'date_to',
    ] as $filter_key => $query_key) {
        if ($filters[$filter_key] !== '' && $filters[$filter_key] !== null) {
            $query[$query_key] = $filters[$filter_key];
        }
    }
    return 'audit_log.php?' . http_build_query($query);
};

$pagination_pages = $total_pages <= 7 ? range(1, $total_pages) : [1];
if ($total_pages > 7) {
    $window_start = max(2, $page - 2);
    $window_end = min($total_pages - 1, $page + 2);
    if ($window_start > 2) $pagination_pages[] = '...';
    for ($pagination_page = $window_start; $pagination_page <= $window_end; $pagination_page++) $pagination_pages[] = $pagination_page;
    if ($window_end < $total_pages - 1) $pagination_pages[] = '...';
    $pagination_pages[] = $total_pages;
}

$page_title = 'Audit Log';
$active_page = 'audit_log';
$header_title = 'Audit Log';

require_once '../includes/layouts/header.php';
?>

<div class="d-flex" id="wrapper">
    <?php require_once '../includes/layouts/sidebar.php'; ?>
    <?php require_once '../includes/layouts/navbar.php'; ?>

    <div class="container-fluid px-4 py-4">
        <div class="card shadow-sm border-0 rounded-4">
            <div class="card-header bg-white border-0 py-3">
                <h4 class="mb-0 fw-bold ui-page-heading"><i class="fas fa-shield-halved me-2 text-primary"></i>Security Audit Log</h4>
                <p class="text-muted small mb-0 mt-1">Administrative view of bounded security and business-critical events.</p>
            </div>
            <div class="card-body">
                <form method="GET" action="audit_log.php" class="row g-2 align-items-end mb-4">
                    <div class="col-md-2">
                        <label for="audit_action" class="form-label">Action</label>
                        <input type="text" class="form-control" id="audit_action" name="action" value="<?php echo htmlspecialchars($action, ENT_QUOTES, 'UTF-8'); ?>" maxlength="80">
                    </div>
                    <div class="col-md-2">
                        <label for="audit_actor" class="form-label">Actor ID</label>
                        <input type="number" class="form-control" id="audit_actor" name="actor" min="1" value="<?php echo $actor_staff_id !== null ? (int)$actor_staff_id : ''; ?>">
                    </div>
                    <div class="col-md-2">
                        <label for="audit_entity" class="form-label">Entity type</label>
                        <input type="text" class="form-control" id="audit_entity" name="entity_type" value="<?php echo htmlspecialchars($entity_type, ENT_QUOTES, 'UTF-8'); ?>" maxlength="50">
                    </div>
                    <div class="col-md-2">
                        <label for="audit_outcome" class="form-label">Outcome</label>
                        <select class="form-select" id="audit_outcome" name="outcome">
                            <option value="">All outcomes</option>
                            <option value="success"<?php echo $outcome === 'success' ? ' selected' : ''; ?>>Success</option>
                            <option value="failure"<?php echo $outcome === 'failure' ? ' selected' : ''; ?>>Failure</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="audit_date_from" class="form-label">From</label>
                        <input type="date" class="form-control" id="audit_date_from" name="date_from" value="<?php echo htmlspecialchars($date_from, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="col-md-2">
                        <label for="audit_date_to" class="form-label">To</label>
                        <input type="date" class="form-control" id="audit_date_to" name="date_to" value="<?php echo htmlspecialchars($date_to, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="col-md-2">
                        <label for="audit_page_size" class="form-label">Rows</label>
                        <select class="form-select" id="audit_page_size" name="page_size">
                            <?php foreach ([10, 25, 50, 100] as $size): ?>
                                <option value="<?php echo $size; ?>"<?php echo $page_size === $size ? ' selected' : ''; ?>><?php echo $size; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-10 d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-filter me-1"></i>Filter</button>
                        <a href="audit_log.php" class="btn btn-outline-secondary">Clear</a>
                    </div>
                </form>

                <p class="text-muted small">Showing <?php echo $range_start; ?>-<?php echo $range_end; ?> of <?php echo $total_events; ?> events.</p>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="bg-light text-secondary">
                            <tr>
                                <th>Timestamp</th>
                                <th>Actor</th>
                                <th>Action</th>
                                <th>Entity</th>
                                <th>Outcome</th>
                                <th>Source IP</th>
                                <th>Metadata</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($events as $event): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars((string)$event['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars((string)($event['actor_full_name'] ?? $event['actor_username'] ?? 'System/unknown'), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><code><?php echo htmlspecialchars((string)$event['action'], ENT_QUOTES, 'UTF-8'); ?></code></td>
                                    <td><?php echo htmlspecialchars((string)$event['entity_type'], ENT_QUOTES, 'UTF-8'); ?><?php echo $event['entity_id'] !== null ? ' #' . (int)$event['entity_id'] : ''; ?></td>
                                    <td><span class="badge <?php echo $event['outcome'] === 'success' ? 'bg-success' : 'bg-danger'; ?>"><?php echo htmlspecialchars((string)$event['outcome'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                                    <td><?php echo htmlspecialchars((string)($event['source_ip'] ?? 'Unavailable'), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><small><?php echo htmlspecialchars((string)($event['metadata'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></small></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if ($events === []): ?>
                                <tr><td colspan="7" class="text-center text-muted py-4">No audit events matched the selected filters.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($total_pages > 1): ?>
                    <nav aria-label="Audit log pagination">
                        <ul class="pagination justify-content-center flex-wrap gap-1">
                            <li class="page-item<?php echo $page <= 1 ? ' disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo htmlspecialchars($page_url($page - 1), ENT_QUOTES, 'UTF-8'); ?>" aria-label="Previous page">Previous</a>
                            </li>
                            <?php foreach ($pagination_pages as $pagination_page): ?>
                                <?php if ($pagination_page === '...'): ?>
                                    <li class="page-item disabled"><span class="page-link">&hellip;</span></li>
                                <?php else: ?>
                                    <li class="page-item<?php echo $pagination_page === $page ? ' active' : ''; ?>">
                                        <a class="page-link" href="<?php echo htmlspecialchars($page_url($pagination_page), ENT_QUOTES, 'UTF-8'); ?>"<?php echo $pagination_page === $page ? ' aria-current="page"' : ''; ?>><?php echo (int)$pagination_page; ?></a>
                                    </li>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <li class="page-item<?php echo $page >= $total_pages ? ' disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo htmlspecialchars($page_url($page + 1), ENT_QUOTES, 'UTF-8'); ?>" aria-label="Next page">Next</a>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/layouts/footer.php'; ?>
