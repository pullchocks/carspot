<?php
// Add CORS headers for cross-origin requests
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once 'config_mysql.php';
require_once 'database_mysql_clean.php';

// Initialize database connection
try {
    $pdo = getConnection();
} catch (Exception $e) {
    error_log('Dealer Applications API: Database connection failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        if (isset($_GET['id'])) {
            getDealerApplication($_GET['id']);
        } else {
            getDealerApplications();
        }
        break;
        
    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        createDealerApplication($data);
        break;
        
    case 'PUT':
        $data = json_decode(file_get_contents('php://input'), true);
        updateDealerApplication($data);
        break;
        
    case 'DELETE':
        $id = $_GET['id'] ?? null;
        if (!$id) handleError('Application ID required');
        deleteDealerApplication($id);
        break;
        
    default:
        handleError('Method not allowed', 405);
}

function getDealerApplications() {
    global $pdo;
    
    try {
        // First check if the table exists
        $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'dealer_applications'");
        $tableExists = $stmt->fetchColumn();
        
        if (!$tableExists) {
            // Create the table if it doesn't exist
            $createTableSQL = "
            CREATE TABLE IF NOT EXISTS dealer_applications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                company_name VARCHAR(255) NOT NULL,
                business_type VARCHAR(100) NOT NULL,
                description TEXT,
                website VARCHAR(255),
                phone VARCHAR(20) NOT NULL,
                status ENUM('pending', 'approved', 'declined') DEFAULT 'pending',
                review_notes TEXT,
                reviewed_by INT,
                reviewed_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_status (status),
                INDEX idx_created_at (created_at)
            )";
            
            $pdo->exec($createTableSQL);
        }
        
        // Get applications
        $query = "SELECT * FROM dealer_applications ORDER BY created_at DESC";
        $stmt = $pdo->query($query);
        $applications = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        jsonResponse(['applications' => $applications]);
        
    } catch (Exception $e) {
        handleError('Failed to get dealer applications: ' . $e->getMessage(), 500);
    }
}

function getDealerApplication($id) {
    global $pdo;
    
    try {
        $query = "SELECT * FROM dealer_applications WHERE id = ?";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$id]);
        $application = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($application) {
            jsonResponse($application);
        } else {
            handleError('Application not found', 404);
        }
        
    } catch (Exception $e) {
        handleError('Failed to get dealer application: ' . $e->getMessage(), 500);
    }
}

function createDealerApplication($data) {
    global $pdo;
    
    try {
        // Handle both camelCase and snake_case field names
        $companyName = $data['company_name'] ?? $data['companyName'] ?? null;
        $businessType = $data['business_type'] ?? $data['businessType'] ?? null;
        $phone = $data['phone'] ?? null;
        
        // Validate required fields
        if (empty($companyName)) {
            handleError("Missing required field: company_name");
        }
        if (empty($businessType)) {
            handleError("Missing required field: business_type");
        }
        if (empty($phone)) {
            handleError("Missing required field: phone");
        }
        
        // First check if the table exists
        $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'dealer_applications'");
        $tableExists = $stmt->fetchColumn();
        
        if (!$tableExists) {
            // Create the table if it doesn't exist
            $createTableSQL = "
            CREATE TABLE IF NOT EXISTS dealer_applications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                company_name VARCHAR(255) NOT NULL,
                business_type VARCHAR(100) NOT NULL,
                description TEXT,
                website VARCHAR(255),
                phone VARCHAR(20) NOT NULL,
                status ENUM('pending', 'approved', 'declined') DEFAULT 'pending',
                review_notes TEXT,
                reviewed_by INT,
                reviewed_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_status (status),
                INDEX idx_created_at (created_at)
            )";
            
            $pdo->exec($createTableSQL);
        }
        
        $query = "
            INSERT INTO dealer_applications (company_name, business_type, description, website, phone, status)
            VALUES (?, ?, ?, ?, ?, 'pending')
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([
            $companyName,
            $businessType,
            $data['description'] ?? null,
            $data['website'] ?? null,
            $phone
        ]);
        
        $applicationId = $pdo->lastInsertId();
        
        jsonResponse([
            'id' => $applicationId,
            'message' => 'Dealer application submitted successfully'
        ], 201);
        
    } catch (Exception $e) {
        handleError('Failed to create dealer application: ' . $e->getMessage(), 500);
    }
}

function updateDealerApplication($data) {
    global $pdo;
    
    try {
        if (empty($data['id'])) {
            handleError('Application ID required');
        }
        
        $updates = [];
        $params = [];
        
        if (isset($data['status'])) {
            $updates[] = "status = ?";
            $params[] = $data['status'];
        }
        
        if (isset($data['review_notes'])) {
            $updates[] = "review_notes = ?";
            $params[] = $data['review_notes'];
        }
        
        if (isset($data['reviewed_by'])) {
            $updates[] = "reviewed_by = ?";
            $params[] = $data['reviewed_by'];
        }
        
        if (empty($updates)) {
            handleError('No fields to update');
        }
        
        $updates[] = "reviewed_at = NOW()";
        $updates[] = "updated_at = NOW()";
        $params[] = $data['id'];
        
        $query = "UPDATE dealer_applications SET " . implode(", ", $updates) . " WHERE id = ?";
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        
        if ($stmt->rowCount() > 0) {
            jsonResponse(['message' => 'Application updated successfully']);
        } else {
            handleError('Application not found', 404);
        }
        
    } catch (Exception $e) {
        handleError('Failed to update dealer application: ' . $e->getMessage(), 500);
    }
}

function deleteDealerApplication($id) {
    global $pdo;
    
    try {
        $query = "DELETE FROM dealer_applications WHERE id = ?";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$id]);
        
        if ($stmt->rowCount() > 0) {
            jsonResponse(['message' => 'Application deleted successfully']);
        } else {
            handleError('Application not found', 404);
        }
        
    } catch (Exception $e) {
        handleError('Failed to delete dealer application: ' . $e->getMessage(), 500);
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
