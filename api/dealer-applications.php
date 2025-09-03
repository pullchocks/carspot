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
        $data = json_decode(file_get_contents('php://input'), true);
        $id = $data['id'] ?? null;
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
                gta_world_id INT,
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
                INDEX idx_created_at (created_at),
                INDEX idx_gta_world_id (gta_world_id)
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
        $gtaWorldId = $data['gta_world_id'] ?? null;
        
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
                gta_world_id INT,
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
                INDEX idx_created_at (created_at),
                INDEX idx_gta_world_id (gta_world_id)
            )";
            
            $pdo->exec($createTableSQL);
        }
        
        // Check if gta_world_id column exists, if not add it
        $columnCheck = $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'dealer_applications' AND column_name = 'gta_world_id'");
        $columnExists = $columnCheck->fetchColumn();
        
        if (!$columnExists) {
            $pdo->exec("ALTER TABLE dealer_applications ADD COLUMN gta_world_id INT AFTER id");
            $pdo->exec("ALTER TABLE dealer_applications ADD INDEX idx_gta_world_id (gta_world_id)");
        }
        
        $query = "
            INSERT INTO dealer_applications (gta_world_id, company_name, business_type, description, website, phone, status)
            VALUES (?, ?, ?, ?, ?, ?, 'pending')
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([
            $gtaWorldId,
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
            
            // If status is being set to 'approved', create dealer account
            if ($data['status'] === 'approved') {
                createDealerAccountFromApplication($data['id']);
            }
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

function createDealerAccountFromApplication($applicationId) {
    global $pdo;
    
    try {
        // Get the application details
        $appQuery = "SELECT * FROM dealer_applications WHERE id = ?";
        $appStmt = $pdo->prepare($appQuery);
        $appStmt->execute([$applicationId]);
        $application = $appStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$application) {
            error_log("Dealer Application: Application not found for ID: $applicationId");
            return;
        }
        
        // Get the GTA World ID from the application
        $gtaWorldId = $application['gta_world_id'];
        
        $user = null;
        $ownerId = null;
        
        if ($gtaWorldId) {
            // Try to find user by GTA World ID first
            $userQuery = "SELECT id, name, is_dealer FROM users WHERE gta_world_id = ?";
            $userStmt = $pdo->prepare($userQuery);
            $userStmt->execute([$gtaWorldId]);
            $user = $userStmt->fetch();
            
            if ($user) {
                $ownerId = $user['id'];
                error_log("Dealer Application: Found user by GTA World ID {$gtaWorldId} - User ID: {$ownerId}");
            }
        }
        
        // If no user found by GTA World ID, try to find by company name
        if (!$user) {
            error_log("Dealer Application: No user found by GTA World ID, trying company name: {$application['company_name']}");
            
            $userQuery = "SELECT id, name, is_dealer FROM users WHERE company_name = ? OR name = ? LIMIT 1";
            $userStmt = $pdo->prepare($userQuery);
            $userStmt->execute([$application['company_name'], $application['company_name']]);
            $user = $userStmt->fetch();
            
            if ($user) {
                $ownerId = $user['id'];
                error_log("Dealer Application: Found user by company name - User ID: {$ownerId}");
            }
        }
        
        // If still no user found, create a new user
        if (!$user) {
            error_log("Dealer Application: No existing user found, creating new user for: {$application['company_name']}");
            
            $createUserQuery = "
                INSERT INTO users (name, company_name, is_dealer, status, created_at)
                VALUES (?, ?, 1, 'active', NOW())
            ";
            $createUserStmt = $pdo->prepare($createUserQuery);
            $createUserStmt->execute([$application['company_name'], $application['company_name']]);
            $ownerId = $pdo->lastInsertId();
            
            error_log("Dealer Application: Created new user with ID: {$ownerId}");
        }
        
        error_log("Dealer Application: Using owner ID {$ownerId} for application {$applicationId}");
        
        // Check if dealer account already exists
        $existingDealerQuery = "SELECT id FROM dealer_accounts WHERE owner_id = ?";
        $existingDealerStmt = $pdo->prepare($existingDealerQuery);
        $existingDealerStmt->execute([$ownerId]);
        $existingDealer = $existingDealerStmt->fetch();
        
        if ($existingDealer) {
            error_log("Dealer Application: Dealer account already exists for user ID {$ownerId}");
            return;
        }
        
        // Create the dealer account
        $dealerQuery = "
            INSERT INTO dealer_accounts (name, company_name, owner_id, phone, website, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 'active', NOW())
        ";
        
        $dealerStmt = $pdo->prepare($dealerQuery);
        $dealerStmt->execute([
            $application['company_name'],
            $application['company_name'],
            $ownerId,
            $application['phone'],

            $application['website']
        ]);
        
        $dealerId = $pdo->lastInsertId();
        
        if (!$dealerId) {
            error_log("Dealer Application: Failed to create dealer account for application {$applicationId}");
            return;
        }
        
        // Update the user's dealer status
        $updateUserQuery = "
            UPDATE users 
            SET is_dealer = 1, company_name = ?, updated_at = NOW()
            WHERE id = ?
        ";
        $updateUserStmt = $pdo->prepare($updateUserQuery);
        $updateUserStmt->execute([$application['company_name'], $ownerId]);
        
        $rowsAffected = $updateUserStmt->rowCount();
        error_log("Dealer Application: Updated {$rowsAffected} user records for user ID {$ownerId}");
        
        // Add the owner to the dealer_user_roles table
        $addOwnerQuery = "
            INSERT INTO dealer_user_roles (dealer_account_id, user_id, role, is_active, joined_at, created_at)
            VALUES (?, ?, 'owner', 1, NOW(), NOW())
        ";
        $addOwnerStmt = $pdo->prepare($addOwnerQuery);
        $addOwnerStmt->execute([$dealerId, $ownerId]);
        
        error_log("Dealer Application: Added owner to dealer_user_roles for dealer ID {$dealerId}, user ID {$ownerId}");
        
        error_log("Dealer Application: Successfully created dealer account ID {$dealerId} for application ID {$applicationId}");
        
    } catch (Exception $e) {
        error_log("Dealer Application: Error creating dealer account: " . $e->getMessage());
    }
}

function handleError($message, $statusCode = 400) {
    jsonResponse(['error' => $message], $statusCode);
}
?>
