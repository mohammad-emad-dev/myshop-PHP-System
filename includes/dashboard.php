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

/**
 * Fetch sales and purchase chart data for the last N days.
 * Pads missing dates with 0.0 values.
 */
function dashboard_get_chart_data($conn, $days = 7, $staff_id = null)
{
    $days = max(1, min((int)$days, 31));
    $data = [];

    // Generate empty values for the last N days to ensure complete timeline
    for ($i = $days - 1; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $data[$date] = [
            'label' => date('M d', strtotime($date)),
            'sales' => 0.0,
            'purchases' => 0.0
        ];
    }

    // Fetch aggregated daily sales and purchases
    $query = "SELECT DATE(order_date) as order_day, order_type, SUM(total_amount) as total
              FROM `Order`
              WHERE order_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)";
    if ($staff_id !== null) {
        $query .= " AND staff_id = ?";
    }
    $query .= " GROUP BY DATE(order_date), order_type
                ORDER BY DATE(order_date) ASC";

    $stmt = null;
    try {
        $stmt = $conn->prepare($query);
        if (!$stmt) {
            error_log('Chart data prepare failed: ' . $conn->error);
            return array_values($data);
        }
        if ($staff_id === null) {
            if (!$stmt->bind_param('i', $days)) {
                error_log('Chart data bind failed: ' . $stmt->error);
                return array_values($data);
            }
        } else {
            $staff_id = (int)$staff_id;
            if (!$stmt->bind_param('ii', $days, $staff_id)) {
                error_log('Scoped chart data bind failed: ' . $stmt->error);
                return array_values($data);
            }
        }
        if (!$stmt->execute()) {
            error_log('Chart data execute failed: ' . $stmt->error);
            return array_values($data);
        }
        $result = $stmt->get_result();
        if (!$result) {
            error_log('Chart data result failed: ' . $stmt->error);
            return array_values($data);
        }
        while ($row = $result->fetch_assoc()) {
            $day = $row['order_day'];
            if (isset($data[$day])) {
                if ($row['order_type'] === 'sale') {
                    $data[$day]['sales'] = floatval($row['total']);
                } else if ($row['order_type'] === 'purchase') {
                    $data[$day]['purchases'] = floatval($row['total']);
                }
            }
        }
    } catch (Throwable $exception) {
        error_log('Chart data query failed: ' . $exception->getMessage());
    } finally {
        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
    }

    return array_values($data);
}

/**
 * Compute inventory valuation based on current stock levels and unit prices.
 */
function dashboard_get_inventory_valuation($conn)
{
    $sql = "SELECT SUM(stock * price) as valuation FROM Product";
    try {
        $result = $conn->query($sql);
        if ($result) {
            $row = $result->fetch_assoc();
            return (float)($row['valuation'] ?? 0.0);
        }
        error_log('Inventory valuation query failed: ' . $conn->error);
    } catch (Throwable $exception) {
        error_log('Inventory valuation query failed: ' . $exception->getMessage());
    }
    return 0.0;
}
