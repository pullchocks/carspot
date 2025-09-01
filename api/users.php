<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once 'config_mysql.php';
require_once 'database_mysql_clean.php';

try {
    $pdo = getConnection();
} catch (Exception $e) {
    error_log('Users API: Database connection failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

$action = $_GET['action'] ?? '';

if ($action === 'admin_users') {
    try {
        // Set proper headers FIRST - before any output
        header('Content-Type: application/json');
        
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
        $offset = ($page - 1) * $limit;
        
        // Build WHERE clause for filters
        $whereConditions = [];
        $params = [];
        
        if (isset($_GET['status']) && $_GET['status'] !== '') {
            $whereConditions[] = "status = ?";
            $params[] = $_GET['status'];
        }
        
        if (isset($_GET['role']) && $_GET['role'] !== '') {
            if ($_GET['role'] === 'dealer') {
                $whereConditions[] = "is_dealer = 1";
            } elseif ($_GET['role'] === 'staff') {
                $whereConditions[] = "staff_role IS NOT NULL";
            } elseif ($_GET['role'] === 'private') {
                $whereConditions[] = "is_dealer = 0 AND staff_role IS NULL";
            }
        }
        
        if (isset($_GET['search']) && $_GET['search'] !== '') {
            $search = '%' . $_GET['search'] . '%';
            $whereConditions[] = "(name LIKE ? OR discord LIKE ?)";
            $params[] = $search;
            $params[] = $search;
        }
        
        $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
        
        // Get total count
        $countQuery = "SELECT COUNT(*) FROM users $whereClause";
        $countStmt = $pdo->prepare($countQuery);
        $countStmt->execute($params);
        $total = $countStmt->fetchColumn();
        
        // Get users with pagination - simplified query
        $query = "
            SELECT 
                id,
                name,
                discord,
                phone,
                phone_number,
                is_dealer,
                staff_role,
                company_name,
                rating,
                reviews,
                status,
                avatar_url,
                gta_world_id,
                gta_world_username,
                last_login,
                created_at,
                updated_at
            FROM users 
            $whereClause
            ORDER BY created_at DESC
            LIMIT ? OFFSET ?
        ";
        
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $users = $stmt->fetchAll();
        
        // Calculate pagination info
        $totalPages = ceil($total / $limit);
        
        echo json_encode([
            'success' => true,
            'users' => $users,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => $totalPages
            ]
        ]);
        
    } catch (Exception $e) {
        error_log('Users API: Error getting admin users: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Failed to get admin users: ' . $e->getMessage()
        ]);
    }
} elseif ($action === 'user_stats') {
    try {
        // Set proper headers FIRST - before any output
        header('Content-Type: application/json');
        
        $stats = [];
        
        // Total users by status
        $statusQuery = "SELECT status, COUNT(*) as count FROM users GROUP BY status";
        $statusStmt = $pdo->query($statusQuery);
        $stats['by_status'] = $statusStmt->fetchAll();
        
        // Total users by role
        $roleQuery = "
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
        ";
        $roleStmt = $pdo->query($roleQuery);
        $stats['by_role'] = $roleStmt->fetchAll();
        
        // New users this month
        $newUsersQuery = "SELECT COUNT(*) as count FROM users WHERE created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')";
        $newUsersStmt = $pdo->query($newUsersQuery);
        $stats['new_this_month'] = $newUsersStmt->fetchColumn();
        
        // Active users (logged in last 30 days)
        $activeUsersQuery = "SELECT COUNT(*) as count FROM users WHERE last_login >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        $activeUsersStmt = $pdo->query($activeUsersQuery);
        $stats['active_users'] = $activeUsersStmt->fetchColumn();
        
        echo json_encode([
            'success' => true,
            'stats' => $stats
        ]);
        
    } catch (Exception $e) {
        error_log('Users API: Error getting user stats: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Failed to get user stats: ' . $e->getMessage()
        ]);
    }
} else {
    // Default action - get all users (simplified)
    try {
        // Set proper headers FIRST - before any output
        header('Content-Type: application/json');
        
        $query = "SELECT * FROM users ORDER BY created_at DESC";
        $stmt = $pdo->prepare($query);
        $stmt->execute();
        $users = $stmt->fetchAll();
        
        echo json_encode([
            'success' => true,
            'users' => $users
        ]);
        
    } catch (Exception $e) {
        error_log('Users API: Error getting users: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Failed to get users: ' . $e->getMessage()
        ]);
    }
}
?>


