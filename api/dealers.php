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

require_once 'database_mysql_clean.php';

// Initialize database connection
try {
    $pdo = getConnection();
} catch (Exception $e) {
    error_log('Dealers API: Database connection failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        if (isset($_GET['action'])) {
            switch ($_GET['action']) {
                case 'check_user_role':
                    checkUserDealerRole($_GET['user_id']);
                    break;
                case 'team':
                    getDealerTeam($_GET['dealer_id']);
                    break;
                case 'pending_invitations':
                    getPendingInvitations($_GET['dealer_id']);
                    break;
                case 'search_users':
                    searchUsers($_GET['query']);
                    break;
                default:
                    handleError('Invalid action', 400);
            }
        } elseif (isset($_GET['id'])) {
            getDealerAccount($_GET['id']);
        } else {
            getDealerAccounts();
        }
        break;
        
    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        if (isset($_GET['action'])) {
            switch ($_GET['action']) {
                case 'create':
                    createDealerAccount($data);
                    break;
                case 'add_user':
                    addUserToDealer($data);
                    break;
                case 'invite':
                    sendInvitation($data);
                    break;
                case 'add_team_member':
                    addTeamMember($data);
                    break;
                default:
                    handleError('Invalid action', 400);
            }
        } else {
            createDealerAccount($data);
        }
        break;
        
    case 'PUT':
        $data = json_decode(file_get_contents('php://input'), true);
        if (isset($_GET['action'])) {
            switch ($_GET['action']) {
                case 'update':
                    updateDealerAccount($data);
                    break;
                case 'accept_invitation':
                    acceptInvitation($data);
                    break;
                case 'decline_invitation':
                    declineInvitation($data);
                    break;
                case 'change_member_role':
                    changeMemberRole($data);
                    break;
                case 'leave_team':
                    leaveTeam($data);
                    break;
                default:
                    handleError('Invalid action', 400);
            }
        } else {
            updateDealerAccount($data);
        }
        break;
        
    case 'DELETE':
        if (isset($_GET['action'])) {
            switch ($_GET['action']) {
                case 'remove_team_member':
                    $data = json_decode(file_get_contents('php://input'), true);
                    removeTeamMember($data);
                    break;
                default:
                    handleError('Invalid action', 400);
            }
        } else {
            $id = $_GET['id'] ?? null;
            if (!$id) handleError('Dealer ID required');
            deleteDealerAccount($id);
        }
        break;
        
    default:
        handleError('Method not allowed', 405);
}

function getDealerAccounts() {
    global $pdo;
    
    try {
        error_log('Dealers API: Starting getDealerAccounts function');
        // Get pagination parameters
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
        $offset = ($page - 1) * $limit;
        
        // Get total count
        $countQuery = "SELECT COUNT(*) FROM dealer_accounts";
        $countStmt = $pdo->prepare($countQuery);
        $countStmt->execute();
        $total = $countStmt->fetchColumn();
        
        error_log("Dealers API: Found $total total dealers in database");
        
        // Get dealers with additional data (simplified query to avoid join issues)
        $query = "
            SELECT 
                da.*,
                u.name as owner_name,

                (SELECT COUNT(*) FROM cars c WHERE c.dealer_account_id = da.id AND c.status = 'active') as total_cars,
                (SELECT COALESCE(AVG(r.rating), 0) FROM reviews r WHERE r.reviewed_user_id = u.id AND r.review_type = 'seller') as rating,
                (SELECT COUNT(*) FROM reviews r WHERE r.reviewed_user_id = u.id AND r.review_type = 'seller') as total_reviews,
                dm.status as membership_status,
                dm.end_date as membership_end_date
            FROM dealer_accounts da
            LEFT JOIN users u ON da.owner_id = u.id
            LEFT JOIN dealer_memberships dm ON da.id = dm.dealer_account_id
            ORDER BY da.created_at DESC
            LIMIT ? OFFSET ?
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([$limit, $offset]);
        $dealers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // If no dealers found with complex query, try simple query
        if (empty($dealers) && $total > 0) {
            error_log("Dealers API: Complex query returned no results, trying simple query");
            $simpleQuery = "SELECT * FROM dealer_accounts ORDER BY created_at DESC LIMIT ? OFFSET ?";
            $simpleStmt = $pdo->prepare($simpleQuery);
            $simpleStmt->execute([$limit, $offset]);
            $dealers = $simpleStmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        error_log("Dealers API: Retrieved " . count($dealers) . " dealers from database");
        
        // Format the response to match AdminDealer interface
        $formattedDealers = array_map(function($dealer) {
            return [
                'id' => (int)$dealer['id'],
                'company_name' => $dealer['company_name'] ?? 'Unknown Company',

                'status' => $dealer['status'] ?? 'active',
                'membership_status' => $dealer['membership_status'] ?? 'expired',
                'total_cars' => (int)($dealer['total_cars'] ?? 0),
                'total_sales' => 0, // This would need to be calculated from sales table
                'rating' => round((float)($dealer['rating'] ?? 0), 1),
                'created_at' => $dealer['created_at'] ?? date('Y-m-d H:i:s')
            ];
        }, $dealers);
        
        error_log('Dealers API: Successfully retrieved ' . count($formattedDealers) . ' dealers');
        
        jsonResponse([
            'dealers' => $formattedDealers,
            'total' => (int)$total,
            'page' => $page,
            'limit' => $limit,
            'pages' => ceil($total / $limit)
        ]);
        
    } catch (Exception $e) {
        error_log('Dealers API: Error in getDealerAccounts: ' . $e->getMessage());
        handleError('Failed to get dealer accounts: ' . $e->getMessage(), 500);
    }
}

function getDealerAccount($id) {
    global $pdo;
    
    try {
        $query = "SELECT * FROM dealer_accounts WHERE id = ?";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$id]);
        $dealer = $stmt->fetch();
        
        if ($dealer) {
            jsonResponse($dealer);
        } else {
            handleError('Dealer account not found', 404);
        }
        
    } catch (Exception $e) {
        handleError('Failed to get dealer account: ' . $e->getMessage(), 500);
    }
}

function createDealerAccount($data) {
    global $pdo;
    
    try {
        $required = ['name', 'company_name', 'phone', 'owner_id'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                handleError("Missing required field: $field");
            }
        }
        
        $query = "
            INSERT INTO dealer_accounts (name, company_name, owner_id, phone, website, expected_monthly_listings, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([
            $data['name'],
            $data['company_name'],
            $data['owner_id'],
            $data['phone'],
            $data['website'] ?? null,
            $data['expected_monthly_listings'] ?? null
        ]);
        
        $dealerId = $pdo->lastInsertId();
        jsonResponse(['id' => $dealerId, 'message' => 'Dealer account created successfully'], 201);
        
    } catch (Exception $e) {
        handleError('Failed to create dealer account: ' . $e->getMessage(), 500);
    }
}

function updateDealerAccount($data) {
    global $pdo;
    
    try {
        if (empty($data['id'])) {
            handleError('Dealer ID required');
        }
        
        $fields = [];
        $params = [];
        
        $updatableFields = ['name', 'company_name', 'phone', 'website', 'expected_monthly_listings', 'status'];
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
        
        $query = "UPDATE dealer_accounts SET " . implode(', ', $fields) . " WHERE id = ?";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        
        if ($stmt->rowCount() > 0) {
            jsonResponse(['message' => 'Dealer account updated successfully']);
        } else {
            handleError('Dealer account not found', 404);
        }
        
    } catch (Exception $e) {
        handleError('Failed to update dealer account: ' . $e->getMessage(), 500);
    }
}

function deleteDealerAccount($id) {
    global $pdo;
    
    try {
        $query = "DELETE FROM dealer_accounts WHERE id = ?";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$id]);
        
        if ($stmt->rowCount() > 0) {
            jsonResponse(['message' => 'Dealer account deleted successfully']);
        } else {
            handleError('Dealer account not found', 404);
        }
        
    } catch (Exception $e) {
        handleError('Failed to delete dealer account: ' . $e->getMessage(), 500);
    }
}

function addUserToDealer($data) {
    global $pdo;
    
    try {
        $required = ['dealer_id', 'user_id', 'role'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                handleError("Missing required field: $field");
            }
        }
        
        // Check if user is already in this dealer team
        $checkQuery = "SELECT id FROM dealer_user_roles WHERE dealer_account_id = ? AND user_id = ?";
        $checkStmt = $pdo->prepare($checkQuery);
        $checkStmt->execute([$data['dealer_id'], $data['user_id']]);
        
        if ($checkStmt->fetch()) {
            handleError('User is already a member of this dealer team', 409);
        }
        
        $query = "
            INSERT INTO dealer_user_roles (dealer_account_id, user_id, role, permissions, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ";
        
        $permissions = getPermissionsForRole($data['role']);
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([
            $data['dealer_id'],
            $data['user_id'],
            $data['role'],
            json_encode($permissions)
        ]);
        
        $roleId = $pdo->lastInsertId();
        jsonResponse(['id' => $roleId, 'message' => 'User added to dealer team successfully'], 201);
        
    } catch (Exception $e) {
        handleError('Failed to add user to dealer: ' . $e->getMessage(), 500);
    }
}

function getDealerTeam($dealerId) {
    global $pdo;
    
    try {
        $query = "
            SELECT dur.*, u.name, u.gta_world_username
            FROM dealer_user_roles dur
            JOIN users u ON dur.user_id = u.id
            WHERE dur.dealer_account_id = ?
            ORDER BY dur.created_at ASC
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([$dealerId]);
        $team = $stmt->fetchAll();
        
        jsonResponse($team);
        
    } catch (Exception $e) {
        handleError('Failed to get dealer team: ' . $e->getMessage(), 500);
    }
}

function sendInvitation($data) {
    global $pdo;
    
    try {
        $required = ['dealer_account_id', 'user_id', 'role'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                handleError("Missing required field: $field");
            }
        }
        
        // Check if invitation already exists
        $checkQuery = "SELECT id FROM dealer_invitations WHERE dealer_account_id = ? AND user_id = ? AND status = 'pending'";
        $checkStmt = $pdo->prepare($checkQuery);
        $checkStmt->execute([$data['dealer_account_id'], $data['user_id']]);
        
        if ($checkStmt->fetch()) {
            handleError('Invitation already exists for this user', 409);
        }
        
        $query = "
            INSERT INTO dealer_invitations (dealer_account_id, invited_by_user_id, user_id, role, status, expires_at, created_at)
            VALUES (?, ?, ?, ?, 'pending', ?, NOW())
        ";
        
        $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([
            $data['dealer_account_id'],
            $data['invited_by_user_id'] ?? 1, // Default to admin user
            $data['user_id'],
            $data['role'],
            $expiresAt
        ]);
        
        $invitationId = $pdo->lastInsertId();
        jsonResponse(['id' => $invitationId, 'message' => 'Invitation sent successfully'], 201);
        
    } catch (Exception $e) {
        handleError('Failed to send invitation: ' . $e->getMessage(), 500);
    }
}

function getPendingInvitations($dealerId) {
    global $pdo;
    
    try {
        // Check if the dealer_invitations table has the expected structure
        $checkQuery = "SHOW COLUMNS FROM dealer_invitations LIKE 'user_id'";
        $checkStmt = $pdo->prepare($checkQuery);
        $checkStmt->execute();
        $hasUserId = $checkStmt->fetch();
        
        if ($hasUserId) {
            // New structure with user_id column
            $query = "
                SELECT di.*, u.name, u.gta_world_username
                FROM dealer_invitations di
                JOIN users u ON di.user_id = u.id
                WHERE di.dealer_account_id = ? AND di.status = 'pending' AND di.expires_at > NOW()
                ORDER BY di.created_at DESC
            ";
        } else {
            // Old structure - return empty array for now since invitations aren't fully implemented
            $query = "
                SELECT di.*, '' as name, '' as gta_world_username
                FROM dealer_invitations di
                WHERE di.dealer_account_id = ? AND di.status = 'pending' AND di.expires_at > NOW()
                ORDER BY di.created_at DESC
            ";
        }
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([$dealerId]);
        $invitations = $stmt->fetchAll();
        
        jsonResponse($invitations);
        
    } catch (Exception $e) {
        handleError('Failed to get pending invitations: ' . $e->getMessage(), 500);
    }
}

function acceptInvitation($data) {
    global $pdo;
    
    try {
        if (empty($data['invitation_id']) || empty($data['user_id'])) {
            handleError('Invitation ID and user ID required');
        }
        
        // Get invitation details
        $invQuery = "SELECT * FROM dealer_invitations WHERE id = ? AND status = 'pending'";
        $invStmt = $pdo->prepare($invQuery);
        $invStmt->execute([$data['invitation_id']]);
        $invitation = $invStmt->fetch();
        
        if (!$invitation) {
            handleError('Invitation not found or already processed', 404);
        }
        
        // Check if invitation has expired
        if (strtotime($invitation['expires_at']) < time()) {
            handleError('Invitation has expired', 400);
        }
        
        // Add user to dealer team
        $addUserData = [
            'dealer_id' => $invitation['dealer_account_id'],
            'user_id' => $data['user_id'],
            'role' => $invitation['role']
        ];
        
        addUserToDealer($addUserData);
        
        // Update invitation status
        $updateQuery = "UPDATE dealer_invitations SET status = 'accepted', updated_at = NOW() WHERE id = ?";
        $updateStmt = $pdo->prepare($updateQuery);
        $updateStmt->execute([$data['invitation_id']]);
        
        jsonResponse(['message' => 'Invitation accepted successfully']);
        
    } catch (Exception $e) {
        handleError('Failed to accept invitation: ' . $e->getMessage(), 500);
    }
}

function declineInvitation($data) {
    global $pdo;
    
    try {
        if (empty($data['invitation_id'])) {
            handleError('Invitation ID required');
        }
        
        $query = "UPDATE dealer_invitations SET status = 'declined', updated_at = NOW() WHERE id = ?";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$data['invitation_id']]);
        
        if ($stmt->rowCount() > 0) {
            jsonResponse(['message' => 'Invitation declined successfully']);
        } else {
            handleError('Invitation not found', 404);
        }
        
    } catch (Exception $e) {
        handleError('Failed to decline invitation: ' . $e->getMessage(), 500);
    }
}

function getPermissionsForRole($role) {
    switch ($role) {
        case 'owner':
            return ['read', 'write', 'delete', 'manage_team', 'manage_settings'];
        case 'manager':
            return ['read', 'write', 'manage_team'];
        case 'staff':
            return ['read', 'write'];
        default:
            return ['read'];
    }
}

function checkUserDealerRole($userId) {
    global $pdo;
    
    try {
        if (empty($userId)) {
            handleError('User ID required');
        }
        
        // First check if user is a dealer themselves (owner of a dealer account)
        $dealerQuery = "SELECT id, company_name FROM dealer_accounts WHERE owner_id = ?";
        $dealerStmt = $pdo->prepare($dealerQuery);
        $dealerStmt->execute([$userId]);
        $dealerAccount = $dealerStmt->fetch();
        
        if ($dealerAccount) {
            jsonResponse([
                'is_dealer' => true,
                'dealer_account_id' => $dealerAccount['id'],
                'company_name' => $dealerAccount['company_name'],
                'role' => 'owner'
            ]);
        }
        
        // Check if user is a team member of any dealer
        $teamQuery = "
            SELECT dur.dealer_account_id, dur.role, da.company_name
            FROM dealer_user_roles dur
            JOIN dealer_accounts da ON dur.dealer_account_id = da.id
            WHERE dur.user_id = ? AND dur.is_active = true
        ";
        $teamStmt = $pdo->prepare($teamQuery);
        $teamStmt->execute([$userId]);
        $teamRole = $teamStmt->fetch();
        
        if ($teamRole) {
            jsonResponse([
                'is_dealer' => true,
                'dealer_account_id' => $teamRole['dealer_account_id'],
                'company_name' => $teamRole['company_name'],
                'role' => $teamRole['role']
            ]);
        }
        
        // User is not a dealer
        jsonResponse([
            'is_dealer' => false,
            'dealer_account_id' => null,
            'company_name' => null,
            'role' => null
        ]);
        
    } catch (Exception $e) {
        handleError('Failed to check user dealer role: ' . $e->getMessage(), 500);
    }
}

function searchUsers($query) {
    global $pdo;
    
    try {
        if (empty($query)) {
            handleError('Search query required');
        }
        
        // Search by username or GTA World username
        $searchQuery = "
            SELECT 
                id, 
                name, 
                gta_world_username, 

                is_dealer, 
                staff_role
            FROM users 
            WHERE 
                name LIKE ? OR 
                gta_world_username LIKE ?
            ORDER BY name 
            LIMIT 20
        ";
        
        $searchTerm = "%{$query}%";
        $stmt = $pdo->prepare($searchQuery);
        $stmt->execute([$searchTerm, $searchTerm]);
        $users = $stmt->fetchAll();
        
        // Convert is_dealer to proper boolean values
        foreach ($users as &$user) {
            $user['is_dealer'] = (bool) $user['is_dealer'];
        }
        
        jsonResponse($users);
        
    } catch (Exception $e) {
        handleError('Failed to search users: ' . $e->getMessage(), 500);
    }
}

function addTeamMember($data) {
    global $pdo;
    
    try {
        if (empty($data['dealer_account_id']) || empty($data['user_id']) || empty($data['role'])) {
            handleError('Dealer account ID, user ID, and role are required');
        }
        
        // Check if user is already a team member
        $checkQuery = "
            SELECT id FROM dealer_user_roles 
            WHERE dealer_account_id = ? AND user_id = ? AND is_active = true
        ";
        $checkStmt = $pdo->prepare($checkQuery);
        $checkStmt->execute([$data['dealer_account_id'], $data['user_id']]);
        
        if ($checkStmt->fetch()) {
            handleError('User is already a team member');
        }
        
        // Add user to team
        $insertQuery = "
            INSERT INTO dealer_user_roles (dealer_account_id, user_id, role, is_active, created_at, updated_at)
            VALUES (?, ?, ?, true, NOW(), NOW())
        ";
        $insertStmt = $pdo->prepare($insertQuery);
        $insertStmt->execute([
            $data['dealer_account_id'], 
            $data['user_id'], 
            $data['role']
        ]);
        
        jsonResponse(['message' => 'Team member added successfully']);
        
    } catch (Exception $e) {
        handleError('Failed to add team member: ' . $e->getMessage(), 500);
    }
}

function removeTeamMember($data) {
    global $pdo;
    
    try {
        if (empty($data['dealer_account_id']) || empty($data['user_id'])) {
            handleError('Dealer account ID and user ID are required');
        }
        
        // Check if user is a team member
        $checkQuery = "
            SELECT id, role FROM dealer_user_roles 
            WHERE dealer_account_id = ? AND user_id = ? AND is_active = true
        ";
        $checkStmt = $pdo->prepare($checkQuery);
        $checkStmt->execute([$data['dealer_account_id'], $data['user_id']]);
        $member = $checkStmt->fetch();
        
        if (!$member) {
            handleError('User is not a team member');
        }
        
        // Prevent removing owner
        if ($member['role'] === 'owner') {
            handleError('Cannot remove the owner from the team');
        }
        
        // Remove user from team (set is_active to false)
        $updateQuery = "
            UPDATE dealer_user_roles 
            SET is_active = false, updated_at = NOW()
            WHERE dealer_account_id = ? AND user_id = ?
        ";
        $updateStmt = $pdo->prepare($updateQuery);
        $updateStmt->execute([$data['dealer_account_id'], $data['user_id']]);
        
        jsonResponse(['message' => 'Team member removed successfully']);
        
    } catch (Exception $e) {
        handleError('Failed to remove team member: ' . $e->getMessage(), 500);
    }
}

function changeMemberRole($data) {
    global $pdo;
    
    try {
        if (empty($data['dealer_account_id']) || empty($data['user_id']) || empty($data['new_role'])) {
            handleError('Dealer account ID, user ID, and new role are required');
        }
        
        // Validate role
        $validRoles = ['owner', 'admin', 'manager', 'staff'];
        if (!in_array($data['new_role'], $validRoles)) {
            handleError('Invalid role. Must be one of: ' . implode(', ', $validRoles));
        }
        
        // Check if user is a team member
        $checkQuery = "
            SELECT id, role FROM dealer_user_roles 
            WHERE dealer_account_id = ? AND user_id = ? AND is_active = true
        ";
        $checkStmt = $pdo->prepare($checkQuery);
        $checkStmt->execute([$data['dealer_account_id'], $data['user_id']]);
        $member = $checkStmt->fetch();
        
        if (!$member) {
            handleError('User is not a team member');
        }
        
        // Update role
        $updateQuery = "
            UPDATE dealer_user_roles 
            SET role = ?, updated_at = NOW()
            WHERE dealer_account_id = ? AND user_id = ?
        ";
        $updateStmt = $pdo->prepare($updateQuery);
        $updateStmt->execute([$data['new_role'], $data['dealer_account_id'], $data['user_id']]);
        
        jsonResponse(['message' => 'Member role updated successfully']);
        
    } catch (Exception $e) {
        handleError('Failed to change member role: ' . $e->getMessage(), 500);
    }
}

function leaveTeam($data) {
    global $pdo;
    
    try {
        if (empty($data['dealer_account_id']) || empty($data['user_id'])) {
            handleError('Dealer account ID and user ID are required');
        }
        
        // Check if user is a team member
        $checkQuery = "
            SELECT id, role FROM dealer_user_roles 
            WHERE dealer_account_id = ? AND user_id = ? AND is_active = true
        ";
        $checkStmt = $pdo->prepare($checkQuery);
        $checkStmt->execute([$data['dealer_account_id'], $data['user_id']]);
        $member = $checkStmt->fetch();
        
        if (!$member) {
            handleError('User is not a team member');
        }
        
        // Prevent owner from leaving (they should transfer ownership first)
        if ($member['role'] === 'owner') {
            handleError('Owner cannot leave the team. Transfer ownership first.');
        }
        
        // Remove user from team (set is_active to false)
        $updateQuery = "
            UPDATE dealer_user_roles 
            SET is_active = false, updated_at = NOW()
            WHERE dealer_account_id = ? AND user_id = ?
        ";
        $updateStmt = $pdo->prepare($updateQuery);
        $updateStmt->execute([$data['dealer_account_id'], $data['user_id']]);
        
        jsonResponse(['message' => 'Successfully left the team']);
        
    } catch (Exception $e) {
        handleError('Failed to leave team: ' . $e->getMessage(), 500);
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

