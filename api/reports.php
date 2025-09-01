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

require_once 'database_mysql_clean.php';

// Initialize database connection
try {
    $pdo = getConnection();
} catch (Exception $e) {
    error_log('Reports API: Database connection failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        if (isset($_GET['id'])) {
            getReport($_GET['id']);
        } else {
            getReports();
        }
        break;
        
    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        createReport($data);
        break;
        
    case 'PUT':
        $data = json_decode(file_get_contents('php://input'), true);
        updateReport($data);
        break;
        
    case 'DELETE':
        $id = $_GET['id'] ?? null;
        if (!$id) handleError('Report ID required');
        deleteReport($id);
        break;
        
    default:
        handleError('Method not allowed', 405);
}

function getReports() {
    global $pdo;
    
    try {
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
        $status = $_GET['status'] ?? null;
        $target_type = $_GET['target_type'] ?? null;
        
        $offset = ($page - 1) * $limit;
        
        $whereConditions = [];
        $params = [];
        
        if ($status) {
            $whereConditions[] = "r.status = ?";
            $params[] = $status;
        }
        
        if ($target_type) {
            $whereConditions[] = "r.target_type = ?";
            $params[] = $target_type;
        }
        
        $whereClause = !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";
        
        // Get total count
        $countQuery = "SELECT COUNT(*) as total FROM reports r $whereClause";
        $countStmt = $pdo->prepare($countQuery);
        $countStmt->execute($params);
        $total = $countStmt->fetch()['total'];
        
        // Get reports with user information
        $query = "
            SELECT 
                r.*,
                u1.name as reporter_name,
                u2.name as assigned_staff_name
            FROM reports r
            LEFT JOIN users u1 ON r.reporter_id = u1.id
            LEFT JOIN users u2 ON r.assigned_staff_id = u2.id
            $whereClause
            ORDER BY r.created_at DESC
            LIMIT ? OFFSET ?
        ";
        
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $reports = $stmt->fetchAll();
        
        jsonResponse([
            'reports' => $reports,
            'total' => $total,
            'page' => $page,
            'limit' => $limit
        ]);
        
    } catch (Exception $e) {
        handleError('Failed to get reports: ' . $e->getMessage(), 500);
    }
}

function getReport($id) {
    global $pdo;
    
    try {
        $query = "
            SELECT 
                r.*,
                u1.name as reporter_name,
                u2.name as assigned_staff_name
            FROM reports r
            LEFT JOIN users u1 ON r.reporter_id = u1.id
            LEFT JOIN users u2 ON r.assigned_staff_id = u2.id
            WHERE r.id = ?
        ";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$id]);
        $report = $stmt->fetch();
        
        if ($report) {
            jsonResponse($report);
        } else {
            handleError('Report not found', 404);
        }
        
    } catch (Exception $e) {
        handleError('Failed to get report: ' . $e->getMessage(), 500);
    }
}

function createReport($data) {
    global $pdo;
    
    try {
        $required = ['reporter_id', 'target_type', 'target_id', 'reason', 'description'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                handleError("Missing required field: $field");
            }
        }
        
        $query = "
            INSERT INTO reports (reporter_id, target_type, target_id, reason, description, status, created_at)
            VALUES (?, ?, ?, ?, ?, 'pending', NOW())
            RETURNING id
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([
            $data['reporter_id'],
            $data['target_type'],
            $data['target_id'],
            $data['reason'],
            $data['description']
        ]);
        
        $reportId = $stmt->fetch()['id'];
        
        // Log staff action if created by staff
        if (isset($data['staff_id'])) {
            logStaffAction($data['staff_id'], 'create_report', 'report', $reportId, 'Created new report');
        }
        
        jsonResponse(['id' => $reportId, 'message' => 'Report created successfully'], 201);
        
    } catch (Exception $e) {
        handleError('Failed to create report: ' . $e->getMessage(), 500);
    }
}

function updateReport($data) {
    global $pdo;
    
    try {
        if (empty($data['id'])) {
            handleError('Report ID required');
        }
        
        $updates = [];
        $params = [];
        
        if (isset($data['status'])) {
            $updates[] = "status = ?";
            $params[] = $data['status'];
        }
        
        if (isset($data['assigned_staff_id'])) {
            $updates[] = "assigned_staff_id = ?";
            $params[] = $data['assigned_staff_id'];
        }
        
        if (isset($data['notes'])) {
            $updates[] = "notes = ?";
            $params[] = $data['notes'];
        }
        
        if (empty($updates)) {
            handleError('No fields to update');
        }
        
        $updates[] = "updated_at = NOW()";
        $params[] = $data['id'];
        
        $query = "UPDATE reports SET " . implode(", ", $updates) . " WHERE id = ?";
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        
        if ($stmt->rowCount() > 0) {
            // Log staff action
            if (isset($data['staff_id'])) {
                $actionDetails = "Updated report status to: " . ($data['status'] ?? 'unknown');
                if (isset($data['notes'])) {
                    $actionDetails .= " - Notes: " . $data['notes'];
                }
                logStaffAction($data['staff_id'], 'update_report', 'report', $data['id'], $actionDetails);
            }
            
            jsonResponse(['message' => 'Report updated successfully']);
        } else {
            handleError('Report not found', 404);
        }
        
    } catch (Exception $e) {
        handleError('Failed to update report: ' . $e->getMessage(), 500);
    }
}

function deleteReport($id) {
    global $pdo;
    
    try {
        $query = "DELETE FROM reports WHERE id = ?";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$id]);
        
        if ($stmt->rowCount() > 0) {
            jsonResponse(['message' => 'Report deleted successfully']);
        } else {
            handleError('Report not found', 404);
        }
        
    } catch (Exception $e) {
        handleError('Failed to delete report: ' . $e->getMessage(), 500);
    }
}

function logStaffAction($staffId, $actionType, $targetType, $targetId, $details) {
    global $pdo;
    
    try {
        $query = "
            INSERT INTO staff_actions (staff_id, action_type, target_type, target_id, details, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([$staffId, $actionType, $targetType, $targetId, $details]);
        
    } catch (Exception $e) {
        // Log error but don't fail the main operation
        error_log('Failed to log staff action: ' . $e->getMessage());
    }
}
?>
