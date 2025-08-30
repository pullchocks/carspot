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
        if (isset($_GET['action'])) {
            switch ($_GET['action']) {
                case 'admin_users':
                    getAdminUsers();
                    break;
                case 'user_stats':
                    getUserStats();
                    break;
                default:
                    if (isset($_GET['id'])) {
                        getUser($_GET['id']);
                    } else {
                        getUsers();
                    }
            }
        } else {
            if (isset($_GET['id'])) {
                getUser($_GET['id']);
            } else {
                getUsers();
            }
        }
        break;
        
    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        createUser($data);
        break;
        
    case 'PUT':
        $data = json_decode(file_get_contents('php://input'), true);
        updateUser($data);
        break;
        
    case 'DELETE':
        $id = $_GET['id'] ?? null;
        if (!$id) handleError('User ID required');
        deleteUser($id);
        break;
        
    default:
        handleError('Method not allowed', 405);
}

function getAdminUsers() {
    global $pdo;
    
    try {
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
        $offset = ($page - 1) * $limit;
        
        // Build WHERE clause for filters
        $whereConditions = [];
        $params = [];
        
        if (isset($_GET['status']) && $_GET['status'] !== '') {
            $whereConditions[] = "u.status = ?";
            $params[] = $_GET['status'];
        }
        
        if (isset($_GET['role']) && $_GET['role'] !== '') {
            if ($_GET['role'] === 'dealer') {
                $whereConditions[] = "u.is_dealer = 1";
            } elseif ($_GET['role'] === 'staff') {
                $whereConditions[] = "u.staff_role IS NOT NULL";
            } elseif ($_GET['role'] === 'private') {
                $whereConditions[] = "u.is_dealer = 0 AND u.staff_role IS NULL";
            }
        }
        
        if (isset($_GET['search']) && $_GET['search'] !== '') {
            $search = '%' . $_GET['search'] . '%';
            $whereConditions[] = "(u.name LIKE ? OR u.email LIKE ? OR u.discord LIKE ?)";
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }
        
        $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
        
        // Get total count
        $countQuery = "SELECT COUNT(*) FROM users u $whereClause";
        $countStmt = $pdo->prepare($countQuery);
        $countStmt->execute($params);
        $total = $countStmt->fetchColumn();
        
        // Get users with pagination
        $query = "
            SELECT 
                u.id,
                u.name,
                u.discord,
                u.email,
                u.phone,
                u.phone_number,
                u.is_dealer,
                u.staff_role,
                u.company_name,
                u.rating,
                u.reviews,
                u.status,
                u.avatar_url,
                u.gta_world_id,
                u.gta_world_username,
                u.last_login,
                u.created_at,
                u.updated_at,
                COUNT(DISTINCT c.id) as total_cars,
                COUNT(DISTINCT CASE WHEN c.status = 'active' THEN c.id END) as active_cars,
                COUNT(DISTINCT CASE WHEN c.status = 'sold' THEN c.id END) as sold_cars
            FROM users u
            LEFT JOIN cars c ON u.id = c.seller_id
            $whereClause
            GROUP BY u.id
            ORDER BY u.created_at DESC
            LIMIT ? OFFSET ?
        ";
        
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $users = $stmt->fetchAll();
        
        // Calculate pagination info
        $totalPages = ceil($total / $limit);
        
        jsonResponse([
            'users' => $users,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => $totalPages
            ]
        ]);
        
    } catch (Exception $e) {
        handleError('Failed to get admin users: ' . $e->getMessage(), 500);
    }
}

function getUserStats() {
    global $pdo;
    
    try {
        $stats = [];
        
        // Total users by status
        $statusQuery = "
            SELECT 
                status,
                COUNT(*) as count
            FROM users 
            GROUP BY status
        ";
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
        $newUsersQuery = "
            SELECT COUNT(*) as count
            FROM users 
            WHERE created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')
        ";
        $newUsersStmt = $pdo->query($newUsersQuery);
        $stats['new_this_month'] = $newUsersStmt->fetchColumn();
        
        // Active users (logged in last 30 days)
        $activeUsersQuery = "
            SELECT COUNT(*) as count
            FROM users 
            WHERE last_login >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ";
        $activeUsersStmt = $pdo->query($activeUsersQuery);
        $stats['active_users'] = $activeUsersStmt->fetchColumn();
        
        jsonResponse($stats);
        
    } catch (Exception $e) {
        handleError('Failed to get user stats: ' . $e->getMessage(), 500);
    }
}

function getUsers() {
    global $pdo;
    
    try {
        $query = "SELECT * FROM users ORDER BY created_at DESC";
        $stmt = $pdo->prepare($query);
        $stmt->execute();
        $users = $stmt->fetchAll();
        
        jsonResponse($users);
        
    } catch (Exception $e) {
        handleError('Failed to get users: ' . $e->getMessage(), 500);
    }
}

function getUser($id) {
    global $pdo;
    
    try {
        $query = "SELECT * FROM users WHERE id = ?";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$id]);
        $user = $stmt->fetch();
        
        if ($user) {
            jsonResponse($user);
        } else {
            handleError('User not found', 404);
        }
        
    } catch (Exception $e) {
        handleError('Failed to get user: ' . $e->getMessage(), 500);
    }
}

function createUser($data) {
    global $pdo;
    
    try {
        $required = ['username', 'email', 'discord'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                handleError("Missing required field: $field");
            }
        }
        
        // Check if username or email already exists
        $checkQuery = "SELECT id FROM users WHERE username = ? OR email = ?";
        $checkStmt = $pdo->prepare($checkQuery);
        $checkStmt->execute([$data['username'], $data['email']]);
        
        if ($checkStmt->fetch()) {
            handleError('Username or email already exists', 409);
        }
        
        $query = "
            INSERT INTO users (username, email, discord, created_at)
            VALUES (?, ?, ?, NOW())
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([
            $data['username'],
            $data['email'],
            $data['discord']
        ]);
        
        $userId = $pdo->lastInsertId();
        jsonResponse(['id' => $userId, 'message' => 'User created successfully'], 201);
        
    } catch (Exception $e) {
        handleError('Failed to create user: ' . $e->getMessage(), 500);
    }
}

function updateUser($data) {
    global $pdo;
    
    try {
        if (empty($data['id'])) {
            handleError('User ID required');
        }
        
        $fields = [];
        $params = [];
        
        // Admin can update more fields
        $updatableFields = [
            'name', 'email', 'discord', 'phone', 'phone_number', 
            'status', 'staff_role', 'is_dealer', 'company_name'
        ];
        
        foreach ($updatableFields as $field) {
            if (isset($data[$field])) {
                $fields[] = "$field = ?";
                $params[] = $data[$field];
            }
        }
        
        if (empty($fields)) {
            handleError('No fields to update');
        }
        
        $fields[] = "updated_at = NOW()";
        $params[] = $data['id'];
        
        $query = "UPDATE users SET " . implode(', ', $fields) . " WHERE id = ?";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        
        if ($stmt->rowCount() > 0) {
            // Log the admin action if status or role changed
            if (isset($data['status']) || isset($data['staff_role'])) {
                logAdminAction($data['id'], $data);
            }
            
            jsonResponse(['message' => 'User updated successfully']);
        } else {
            handleError('User not found', 404);
        }
        
    } catch (Exception $e) {
        handleError('Failed to update user: ' . $e->getMessage(), 500);
    }
}

function deleteUser($id) {
    global $pdo;
    
    try {
        $query = "DELETE FROM users WHERE id = ?";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$id]);
        
        if ($stmt->rowCount() > 0) {
            jsonResponse(['message' => 'User deleted successfully']);
        } else {
            handleError('User not found', 404);
        }
        
    } catch (Exception $e) {
        handleError('Failed to delete user: ' . $e->getMessage(), 500);
    }
}

function logAdminAction($userId, $data) {
    global $pdo;
    
    try {
        $details = [];
        if (isset($data['status'])) $details['status'] = $data['status'];
        if (isset($data['staff_role'])) $details['staff_role'] = $data['staff_role'];
        if (isset($data['reason'])) $details['reason'] = $data['reason'];
        
        $query = "
            INSERT INTO staff_actions (staff_id, action_type, target_type, target_id, details, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ";
        
        // For now, we'll use a default staff ID since we don't have session management here
        // In production, this should come from the authenticated session
        $stmt = $pdo->prepare($query);
        $stmt->execute([
            1, // Default admin ID - should be replaced with actual session user ID
            'user_update',
            'user',
            $userId,
            json_encode($details)
        ]);
        
    } catch (Exception $e) {
        // Don't fail the main operation if logging fails
        error_log('Failed to log admin action: ' . $e->getMessage());
    }
}

function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function handleError($message, $statusCode = 400) {
    jsonResponse(['error' => $message], $statusCode);
}
?>


