<?php
// Add CORS headers for cross-origin requests
header('Access-Control-Allow-Origin: https://carspot.site');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once 'database.php';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        $action = $_GET['action'] ?? 'dashboard';
        
        switch ($action) {
            case 'dashboard':
                getDashboardAnalytics();
                break;
            case 'staff_actions':
                getStaffActions();
                break;
            case 'user_analytics':
                getUserAnalytics();
                break;
            case 'car_analytics':
                getCarAnalytics();
                break;
            case 'dealer_analytics':
                getDealerAnalytics();
                break;
            case 'revenue_analytics':
                getRevenueAnalytics();
                break;
            default:
                getDashboardAnalytics();
        }
        break;
        
    case 'POST':
        $action = $_GET['action'] ?? '';
        
        if ($action === 'log_staff_action') {
            $data = json_decode(file_get_contents('php://input'), true);
            logStaffAction($data);
        } else {
            handleError('Invalid action', 400);
        }
        break;
        
    default:
        handleError('Method not allowed', 405);
}

function getDashboardAnalytics() {
    global $pdo;
    
    try {
        // Get basic counts
        $queries = [
            'total_users' => "SELECT COUNT(*) as count FROM users WHERE status = 'active'",
            'total_cars' => "SELECT COUNT(*) as count FROM cars WHERE status = 'active'",
            'total_dealers' => "SELECT COUNT(*) as count FROM dealer_accounts WHERE status = 'active'",
            'total_transactions' => "SELECT COUNT(*) as count FROM payment_transactions WHERE status = 'completed'",
            'total_revenue' => "SELECT COALESCE(SUM(amount), 0) as total FROM payment_transactions WHERE status = 'completed'",
            'new_users_today' => "SELECT COUNT(*) as count FROM users WHERE DATE(created_at) = CURRENT_DATE",
            'new_cars_today' => "SELECT COUNT(*) as count FROM cars WHERE DATE(created_at) = CURRENT_DATE",
            'active_listings' => "SELECT COUNT(*) as count FROM cars WHERE status = 'active' AND listing_end_date > NOW()",
            'total_sales' => "SELECT COUNT(*) as count FROM car_sales",
            'verified_sales' => "SELECT COUNT(*) as count FROM car_sales WHERE is_verified = TRUE",
            'pending_sale_verification' => "SELECT COUNT(*) as count FROM car_sales WHERE is_verified = FALSE",
            'pending_reports' => "SELECT COUNT(*) as count FROM reports WHERE status = 'pending'",
            'investigating_reports' => "SELECT COUNT(*) as count FROM reports WHERE status = 'investigating'",
            'resolved_reports' => "SELECT COUNT(*) as count FROM reports WHERE status = 'resolved'",
            'dismissed_reports' => "SELECT COUNT(*) as count FROM reports WHERE status = 'dismissed'"
        ];
        
        $analytics = [];
        foreach ($queries as $key => $query) {
            $stmt = $pdo->prepare($query);
            $stmt->execute();
            $result = $stmt->fetch();
            $analytics[$key] = $result['count'] ?? 0;
        }
        
        // Convert revenue from cents to dollars
        $analytics['total_revenue'] = $analytics['total_revenue'] / 100;
        
        jsonResponse($analytics);
        
    } catch (Exception $e) {
        handleError('Failed to get dashboard analytics: ' . $e->getMessage(), 500);
    }
}

function getUserAnalytics() {
    global $pdo;
    
    try {
        $queries = [
            'users_by_role' => "
                SELECT 
                    CASE 
                        WHEN staff_role IS NOT NULL THEN 'staff'
                        WHEN is_dealer THEN 'dealer'
                        ELSE 'private'
                    END as role,
                    COUNT(*) as count
                FROM users 
                WHERE status = 'active'
                GROUP BY role
            ",
            'users_by_status' => "
                SELECT status, COUNT(*) as count
                FROM users
                GROUP BY status
            ",
            'top_users_by_activity' => "
                SELECT 
                    u.id,
                    u.name,
                    COUNT(c.id) as car_count
                FROM users u
                LEFT JOIN cars c ON u.id = c.user_id
                WHERE u.status = 'active'
                GROUP BY u.id, u.name
                ORDER BY car_count DESC
                LIMIT 10
            "
        ];
        
        $analytics = [];
        foreach ($queries as $key => $query) {
            $stmt = $pdo->prepare($query);
            $stmt->execute();
            $analytics[$key] = $stmt->fetchAll();
        }
        
        jsonResponse($analytics);
        
    } catch (Exception $e) {
        handleError('Failed to get user analytics: ' . $e->getMessage(), 500);
    }
}

function getCarAnalytics() {
    global $pdo;
    
    try {
        $queries = [
            'cars_by_status' => "
                SELECT status, COUNT(*) as count
                FROM cars
                GROUP BY status
            ",
            'cars_by_make' => "
                SELECT make, COUNT(*) as count
                FROM cars
                WHERE status = 'active'
                GROUP BY make
                ORDER BY count DESC
                LIMIT 10
            ",
            'cars_by_price_range' => "
                SELECT 
                    CASE 
                        WHEN price < 1000000 THEN 'Under $10,000'
                        WHEN price < 5000000 THEN '$10,000 - $50,000'
                        WHEN price < 10000000 THEN '$50,000 - $100,000'
                        ELSE 'Over $100,000'
                    END as price_range,
                    COUNT(*) as count
                FROM cars
                WHERE status = 'active'
                GROUP BY price_range
            "
        ];
        
        $analytics = [];
        foreach ($queries as $key => $query) {
            $stmt = $pdo->prepare($query);
            $stmt->execute();
            $analytics[$key] = $stmt->fetchAll();
        }
        
        jsonResponse($analytics);
        
    } catch (Exception $e) {
        handleError('Failed to get car analytics: ' . $e->getMessage(), 500);
    }
}

function getDealerAnalytics() {
    global $pdo;
    
    try {
        $queries = [
            'dealers_by_status' => "
                SELECT status, COUNT(*) as count
                FROM dealer_accounts
                GROUP BY status
            ",
            'top_dealers_by_sales' => "
                SELECT 
                    da.id,
                    da.company_name,
                    COUNT(c.id) as car_count,
                    COALESCE(SUM(c.price), 0) as total_value
                FROM dealer_accounts da
                LEFT JOIN cars c ON da.id = c.dealer_account_id
                WHERE da.status = 'active' AND c.status = 'active'
                GROUP BY da.id, da.company_name
                ORDER BY car_count DESC
                LIMIT 10
            ",
            'dealer_membership_status' => "
                SELECT 
                    dm.status,
                    COUNT(*) as count
                FROM dealer_memberships dm
                JOIN dealer_accounts da ON dm.dealer_account_id = da.id
                WHERE da.status = 'active'
                GROUP BY dm.status
            "
        ];
        
        $analytics = [];
        foreach ($queries as $key => $query) {
            $stmt = $pdo->prepare($query);
            $stmt->execute();
            $analytics[$key] = $stmt->fetchAll();
        }
        
        jsonResponse($analytics);
        
    } catch (Exception $e) {
        handleError('Failed to get dealer analytics: ' . $e->getMessage(), 500);
    }
}

function getRevenueAnalytics() {
    global $pdo;
    
    try {
        $queries = [
            'revenue_by_month' => "
                SELECT 
                    DATE_TRUNC('month', created_at) as month,
                    SUM(amount) as revenue,
                    COUNT(*) as transactions
                FROM payment_transactions
                WHERE status = 'completed'
                GROUP BY month
                ORDER BY month DESC
                LIMIT 12
            ",
            'revenue_by_type' => "
                SELECT 
                    payment_type,
                    SUM(amount) as revenue,
                    COUNT(*) as transactions
                FROM payment_transactions
                WHERE status = 'completed'
                GROUP BY payment_type
            ",
            'daily_revenue' => "
                SELECT 
                    DATE(created_at) as date,
                    SUM(amount) as revenue
                FROM payment_transactions
                WHERE status = 'completed' AND created_at >= CURRENT_DATE - INTERVAL '30 days'
                GROUP BY date
                ORDER BY date DESC
            "
        ];
        
        $analytics = [];
        foreach ($queries as $key => $query) {
            $stmt = $pdo->prepare($query);
            $stmt->execute();
            $analytics[$key] = $stmt->fetchAll();
        }
        
        // Convert amounts from cents to dollars
        foreach ($analytics as $key => $data) {
            foreach ($data as $item) {
                if (isset($item['revenue'])) {
                    $item['revenue'] = $item['revenue'] / 100;
                }
            }
        }
        
        jsonResponse($analytics);
        
    } catch (Exception $e) {
        handleError('Failed to get revenue analytics: ' . $e->getMessage(), 500);
    }
}

function getStaffActions() {
    global $pdo;
    
    try {
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
        $staff_id = $_GET['staff_id'] ?? null;
        $action_type = $_GET['action_type'] ?? null;
        
        $offset = ($page - 1) * $limit;
        
        $whereConditions = [];
        $params = [];
        
        if ($staff_id) {
            $whereConditions[] = "sa.staff_id = ?";
            $params[] = $staff_id;
        }
        
        if ($action_type) {
            $whereConditions[] = "sa.action_type = ?";
            $params[] = $action_type;
        }
        
        $whereClause = !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";
        
        // Get total count
        $countQuery = "SELECT COUNT(*) as total FROM staff_actions sa $whereClause";
        $countStmt = $pdo->prepare($countQuery);
        $countStmt->execute($params);
        $total = $countStmt->fetch()['total'];
        
        // Get staff actions with user information
        $query = "
            SELECT 
                sa.*,
                u.name as staff_name
            FROM staff_actions sa
            LEFT JOIN users u ON sa.staff_id = u.id
            $whereClause
            ORDER BY sa.created_at DESC
            LIMIT ? OFFSET ?
        ";
        
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $actions = $stmt->fetchAll();
        
        jsonResponse([
            'actions' => $actions,
            'total' => $total,
            'page' => $page,
            'limit' => $limit
        ]);
        
    } catch (Exception $e) {
        handleError('Failed to get staff actions: ' . $e->getMessage(), 500);
    }
}

function logStaffAction($data) {
    global $pdo;
    
    try {
        $required = ['staff_id', 'action_type', 'target_type', 'target_id', 'details'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                handleError("Missing required field: $field");
            }
        }
        
        $query = "
            INSERT INTO staff_actions (staff_id, action_type, target_type, target_id, details, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
            RETURNING id
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([
            $data['staff_id'],
            $data['action_type'],
            $data['target_type'],
            $data['target_id'],
            $data['details']
        ]);
        
        $actionId = $stmt->fetch()['id'];
        
        jsonResponse(['id' => $actionId, 'message' => 'Staff action logged successfully'], 201);
        
    } catch (Exception $e) {
        handleError('Failed to log staff action: ' . $e->getMessage(), 500);
    }
}
?>




