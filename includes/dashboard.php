<?php

declare(strict_types=1);

/**
 * Return the dashboard KPI aggregates for the requested staff scope.
 *
 * Product and stock totals are global. Order count and sales totals are
 * global when no staff scope is provided and scoped to the supplied staff ID
 * otherwise. The fixed defaults preserve the legacy dashboard contract when
 * a query or database operation fails.
 */
function dashboard_get_stats($conn, $staff_id = null)
{
    $stats = [
        'total_products' => 0,
        'total_orders'   => 0,
        'total_sales'    => 0.0,
        'total_stock'    => 0
    ];

    try {
        $result = $conn->query("SELECT COUNT(*) as count FROM Product");
        if ($result) {
            $stats['total_products'] = (int) $result->fetch_assoc()['count'];
        } else {
            error_log('Dashboard product count query failed: ' . $conn->error);
        }

        if ($staff_id === null) {
            $result = $conn->query("SELECT COUNT(*) as count FROM `Order`");
            if ($result) {
                $stats['total_orders'] = (int) $result->fetch_assoc()['count'];
            } else {
                error_log('Dashboard order count query failed: ' . $conn->error);
            }

            // Only count revenue from SALES, not purchases.
            $result = $conn->query("SELECT COALESCE(SUM(total_amount), 0) as total FROM `Order` WHERE order_type = 'sale'");
            if ($result) {
                $stats['total_sales'] = (float) $result->fetch_assoc()['total'];
            } else {
                error_log('Dashboard sales total query failed: ' . $conn->error);
            }
        } else {
            // Cashier dashboards must not aggregate another staff member's orders.
            $staff_id = (int)$staff_id;

            $stmt = $conn->prepare("SELECT COUNT(*) as count FROM `Order` WHERE staff_id = ?");
            if ($stmt) {
                if (!$stmt->bind_param('i', $staff_id)) {
                    error_log('Dashboard scoped order count bind failed: ' . $stmt->error);
                } elseif (!$stmt->execute()) {
                    error_log('Dashboard scoped order count execute failed: ' . $stmt->error);
                } else {
                    $result = $stmt->get_result();
                    if ($result) {
                        $stats['total_orders'] = (int)$result->fetch_assoc()['count'];
                    } else {
                        error_log('Dashboard scoped order count result failed: ' . $stmt->error);
                    }
                }
                $stmt->close();
            } else {
                error_log('Dashboard scoped order count prepare failed: ' . $conn->error);
            }

            // Only count this cashier's sales, not purchases or other staff orders.
            $stmt = $conn->prepare("SELECT COALESCE(SUM(total_amount), 0) as total FROM `Order` WHERE order_type = 'sale' AND staff_id = ?");
            if ($stmt) {
                if (!$stmt->bind_param('i', $staff_id)) {
                    error_log('Dashboard scoped sales total bind failed: ' . $stmt->error);
                } elseif (!$stmt->execute()) {
                    error_log('Dashboard scoped sales total execute failed: ' . $stmt->error);
                } else {
                    $result = $stmt->get_result();
                    if ($result) {
                        $stats['total_sales'] = (float)$result->fetch_assoc()['total'];
                    } else {
                        error_log('Dashboard scoped sales total result failed: ' . $stmt->error);
                    }
                }
                $stmt->close();
            } else {
                error_log('Dashboard scoped sales total prepare failed: ' . $conn->error);
            }
        }

        $result = $conn->query("SELECT COALESCE(SUM(stock), 0) as total FROM Product");
        if ($result) {
            $stats['total_stock'] = (int) $result->fetch_assoc()['total'];
        } else {
            error_log('Dashboard stock total query failed: ' . $conn->error);
        }
    } catch (Throwable $exception) {
        error_log('Dashboard stats query failed: ' . $exception->getMessage());
    }

    return $stats;
}
