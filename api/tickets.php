<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once 'config_mysql.php';
require_once 'database_mysql.php';

try {
    $pdo = getConnection();
} catch (Exception $e) {
    error_log('Tickets API: Database connection failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

// Handle different actions
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'create_ticket':
        createTicket();
        break;
    case 'get_tickets':
        getTickets();
        break;
    case 'get_ticket':
        getTicket();
        break;
    case 'update_ticket':
        updateTicket();
        break;
    case 'add_response':
        addResponse();
        break;
    case 'get_responses':
        getResponses();
        break;
    case 'get_categories':
        getCategories();
        break;
    // Tags system removed - using only categories
    case 'add_attachment':
        addAttachment();
        break;
    case 'update_status':
        updateStatus();
        break;
    case 'update_priority':
        updatePriority();
        break;
    case 'assign_ticket':
        assignTicket();
        break;
    case 'close_ticket':
        closeTicket();
        break;
    case 'rate_satisfaction':
        rateSatisfaction();
        break;
    case 'get_staff_tickets':
        getStaffTickets();
        break;
    default:
        handleError('Invalid action', 400);
}

// Create a new support ticket
function createTicket() {
    
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['user_id']) || empty($data['category_id']) || empty($data['subject']) || empty($data['description'])) {
            handleError('Missing required fields', 400);
        }
        
        // Generate unique ticket number
        $ticketNumber = generateTicketNumber();
        
        // Create the ticket
        $query = "
            INSERT INTO support_tickets (ticket_number, user_id, category_id, subject, description, priority)
            VALUES (?, ?, ?, ?, ?, ?)
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([
            $ticketNumber,
            $data['user_id'],
            $data['category_id'],
            $data['subject'],
            $data['description'],
            $data['priority'] ?? 'medium'
        ]);
        
        $ticketId = $pdo->lastInsertId();
        
        // Log status change
        logStatusChange($ticketId, null, 'open', $data['user_id']);
        
        // Get the created ticket
        $ticket = getTicketById($ticketId);
        
        jsonResponse([
            'success' => true,
            'message' => 'Ticket created successfully',
            'ticket' => $ticket
        ]);
        
    } catch (Exception $e) {
        handleError('Failed to create ticket: ' . $e->getMessage(), 500);
    }
}

// Get tickets for a user
function getTickets() {
    
    try {
        $userId = $_GET['user_id'] ?? null;
        $status = $_GET['status'] ?? null;
        $category = $_GET['category'] ?? null;
        $page = max(1, intval($_GET['page'] ?? 1));
        $limit = min(50, max(1, intval($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        
        $whereConditions = [];
        $params = [];
        
        if ($userId) {
            $whereConditions[] = "t.user_id = ?";
            $params[] = $userId;
        }
        
        if ($status) {
            $whereConditions[] = "t.status = ?";
            $params[] = $status;
        }
        
        if ($category) {
            $whereConditions[] = "t.category_id = ?";
            $params[] = $category;
        }
        
        $whereClause = !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";
        
        // Get total count
        $countQuery = "
            SELECT COUNT(*) as total
            FROM support_tickets t
            $whereClause
        ";
        
        $countStmt = $pdo->prepare($countQuery);
        $countStmt->execute($params);
        $total = $countStmt->fetch()['total'];
        
        // Get tickets
        $query = "
            SELECT 
                t.*,
                c.name as category_name,
                u.name as user_name,
                u.avatar_url as user_avatar,
                s.name as staff_name,
                s.avatar_url as staff_avatar,
                (SELECT COUNT(*) FROM ticket_responses tr WHERE tr.ticket_id = t.id) as response_count,
                (SELECT MAX(created_at) FROM ticket_responses tr WHERE tr.ticket_id = t.id) as last_response
            FROM support_tickets t
            JOIN ticket_categories c ON t.category_id = c.id
            JOIN users u ON t.user_id = u.id
            LEFT JOIN users s ON t.assigned_staff_id = s.id
            $whereClause
            ORDER BY 
                CASE t.priority 
                    WHEN 'urgent' THEN 1 
                    WHEN 'high' THEN 2 
                    WHEN 'medium' THEN 3 
                    WHEN 'low' THEN 4 
                END,
                t.created_at DESC
            LIMIT ? OFFSET ?
        ";
        
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $tickets = $stmt->fetchAll();
        
        // Tags system removed - using only categories
        
        jsonResponse([
            'success' => true,
            'tickets' => $tickets,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => ceil($total / $limit)
            ]
        ]);
        
    } catch (Exception $e) {
        handleError('Failed to get tickets: ' . $e->getMessage(), 500);
    }
}

// Get a specific ticket
function getTicket() {
    
    try {
        $ticketId = $_GET['id'] ?? null;
        
        if (!$ticketId) {
            handleError('Ticket ID required', 400);
        }
        
        $ticket = getTicketById($ticketId);
        
        if (!$ticket) {
            handleError('Ticket not found', 404);
        }
        
        jsonResponse([
            'success' => true,
            'ticket' => $ticket
        ]);
        
    } catch (Exception $e) {
        handleError('Failed to get ticket: ' . $e->getMessage(), 500);
    }
}

// Update a ticket
function updateTicket() {
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['ticket_id']) || empty($data['user_id'])) {
            handleError('Missing required fields', 400);
        }
        
        // Check if user is staff or ticket owner
        $ticket = getTicketById($data['ticket_id']);
        if (!$ticket) {
            handleError('Ticket not found', 404);
        }
        
        if ($ticket['user_id'] != $data['user_id'] && !isStaff($data['user_id'])) {
            handleError('Unauthorized', 403);
        }
        
        // Update fields
        $updateFields = [];
        $params = [];
        
        if (isset($data['subject'])) {
            $updateFields[] = "subject = ?";
            $params[] = $data['subject'];
        }
        
        if (isset($data['description'])) {
            $updateFields[] = "description = ?";
            $params[] = $data['description'];
        }
        
        if (isset($data['priority']) && isStaff($data['user_id'])) {
            $updateFields[] = "priority = ?";
            $params[] = $data['priority'];
        }
        
        if (empty($updateFields)) {
            handleError('No fields to update', 400);
        }
        
        $params[] = $data['ticket_id'];
        
        $query = "UPDATE support_tickets SET " . implode(", ", $updateFields) . " WHERE id = ?";
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        
        // Get updated ticket
        $updatedTicket = getTicketById($data['ticket_id']);
        
        jsonResponse([
            'success' => true,
            'message' => 'Ticket updated successfully',
            'ticket' => $updatedTicket
        ]);
        
    } catch (Exception $e) {
        handleError('Failed to update ticket: ' . $e->getMessage(), 500);
    }
}

// Add a response to a ticket
function addResponse() {
    
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['ticket_id']) || empty($data['user_id']) || empty($data['message'])) {
            handleError('Missing required fields', 400);
        }
        
        // Check if ticket exists and user has access
        $ticket = getTicketById($data['ticket_id']);
        if (!$ticket) {
            handleError('Ticket not found', 404);
        }
        
        // Check if user is the ticket owner or staff
        if ($ticket['user_id'] != $data['user_id'] && !isStaff($data['user_id'])) {
            handleError('Unauthorized', 403);
        }
        
        // Add response
        $query = "
            INSERT INTO ticket_responses (ticket_id, user_id, message, is_staff_response)
            VALUES (?, ?, ?, ?)
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([
            $data['ticket_id'],
            $data['user_id'],
            $data['message'],
            isStaff($data['user_id']) ? 1 : 0
        ]);
        
        $responseId = $pdo->lastInsertId();
        
        // Update ticket status if staff responded
        if (isStaff($data['user_id'])) {
            updateTicketStatus($data['ticket_id'], 'in_progress', $data['user_id']);
        } else {
            updateTicketStatus($data['ticket_id'], 'waiting_for_user', $data['user_id']);
        }
        
        // Get the created response
        $response = getResponseById($responseId);
        
        jsonResponse([
            'success' => true,
            'message' => 'Response added successfully',
            'response' => $response
        ]);
        
    } catch (Exception $e) {
        handleError('Failed to add response: ' . $e->getMessage(), 500);
    }
}

// Get responses for a ticket
function getResponses() {
    
    try {
        $ticketId = $_GET['ticket_id'] ?? null;
        $userId = $_GET['user_id'] ?? null;
        
        if (!$ticketId) {
            handleError('Ticket ID required', 400);
        }
        
        // Check if user has access to this ticket
        $ticket = getTicketById($ticketId);
        if (!$ticket) {
            handleError('Ticket not found', 404);
        }
        
        if ($ticket['user_id'] != $userId && !isStaff($userId)) {
            handleError('Unauthorized', 403);
        }
        
        $query = "
            SELECT 
                tr.*,
                u.name as user_name,
                u.avatar_url as user_avatar,
                u.staff_role
            FROM ticket_responses tr
            JOIN users u ON tr.user_id = u.id
            WHERE tr.ticket_id = ?
            ORDER BY tr.created_at ASC
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([$ticketId]);
        $responses = $stmt->fetchAll();
        
        // Internal notes filtering removed - using only categories
        
        jsonResponse([
            'success' => true,
            'responses' => array_values($responses)
        ]);
        
    } catch (Exception $e) {
        handleError('Failed to get responses: ' . $e->getMessage(), 500);
    }
}

// Get ticket categories
function getCategories() {
    
    try {
        error_log('Tickets API: Getting categories...');
        
        $query = "
            SELECT * FROM ticket_categories 
            WHERE is_active = 1 
            ORDER BY sort_order, name
        ";
        
        error_log('Tickets API: Query: ' . $query);
        
        $stmt = $pdo->prepare($query);
        $stmt->execute();
        $categories = $stmt->fetchAll();
        
        error_log('Tickets API: Found ' . count($categories) . ' categories');
        error_log('Tickets API: Categories: ' . json_encode($categories));
        
        jsonResponse([
            'success' => true,
            'categories' => $categories
        ]);
        
    } catch (Exception $e) {
        error_log('Tickets API: Error getting categories: ' . $e->getMessage());
        handleError('Failed to get categories: ' . $e->getMessage(), 500);
    }
}

// Tags system removed - using only categories

// Add attachment to ticket
function addAttachment() {
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['ticket_id']) || empty($data['file_name']) || empty($data['file_url'])) {
            handleError('Missing required fields', 400);
        }
        
        $query = "
            INSERT INTO ticket_attachments (ticket_id, response_id, file_name, file_url, file_type, file_size, uploaded_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([
            $data['ticket_id'],
            $data['response_id'] ?? null,
            $data['file_name'],
            $data['file_url'],
            $data['file_type'] ?? null,
            $data['file_size'] ?? null,
            $data['user_id'] ?? null
        ]);
        
        $attachmentId = $pdo->lastInsertId();
        
        jsonResponse([
            'success' => true,
            'message' => 'Attachment added successfully',
            'attachment_id' => $attachmentId
        ]);
        
    } catch (Exception $e) {
        handleError('Failed to add attachment: ' . $e->getMessage(), 500);
    }
}

// Update ticket status
function updateStatus() {
    
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['ticket_id']) || empty($data['status']) || empty($data['user_id'])) {
            handleError('Missing required fields', 400);
        }
        
        // Check if user is staff
        if (!isStaff($data['user_id'])) {
            handleError('Unauthorized', 403);
        }
        
        updateTicketStatus($data['ticket_id'], $data['status'], $data['user_id']);
        
        jsonResponse([
            'success' => true,
            'message' => 'Ticket status updated successfully'
        ]);
        
    } catch (Exception $e) {
        handleError('Failed to update status: ' . $e->getMessage(), 500);
    }
}

// Update ticket priority
function updatePriority() {
    
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['ticket_id']) || empty($data['priority']) || empty($data['user_id'])) {
            handleError('Missing required fields', 400);
        }
        
        // Check if user is staff
        if (!isStaff($data['user_id'])) {
            handleError('Unauthorized', 403);
        }
        
        updateTicketPriority($data['ticket_id'], $data['priority'], $data['user_id']);
        
        jsonResponse([
            'success' => true,
            'message' => 'Ticket priority updated successfully'
        ]);
        
    } catch (Exception $e) {
        handleError('Failed to update priority: ' . $e->getMessage(), 500);
    }
}

// Assign ticket to staff member
function assignTicket() {
    
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['ticket_id']) || empty($data['staff_id']) || empty($data['assigned_by'])) {
            handleError('Missing required fields', 400);
        }
        
        // Check if assigned_by is staff
        if (!isStaff($data['assigned_by'])) {
            handleError('Unauthorized', 403);
        }
        
        // Check if staff_id is actually staff
        if (!isStaff($data['staff_id'])) {
            handleError('Invalid staff member', 400);
        }
        
        assignTicketToStaff($data['ticket_id'], $data['staff_id'], $data['assigned_by']);
        
        jsonResponse([
            'success' => true,
            'message' => 'Ticket assigned successfully'
        ]);
        
    } catch (Exception $e) {
        handleError('Failed to assign ticket: ' . $e->getMessage(), 500);
    }
}

// Close a ticket
function closeTicket() {
    
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['ticket_id']) || empty($data['user_id'])) {
            handleError('Missing required fields', 400);
        }
        
        // Check if user is staff or ticket owner
        $ticket = getTicketById($data['ticket_id']);
        if (!$ticket) {
            handleError('Ticket not found', 404);
        }
        
        if ($ticket['user_id'] != $data['user_id'] && !isStaff($data['user_id'])) {
            handleError('Unauthorized', 403);
        }
        
        // Close ticket
        $query = "
            UPDATE support_tickets 
            SET status = 'closed', closed_at = NOW() 
            WHERE id = ?
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([$data['ticket_id']]);
        
        // Log status change
        logStatusChange($data['ticket_id'], 'open', 'closed', $data['user_id']);
        
        jsonResponse([
            'success' => true,
            'message' => 'Ticket closed successfully'
        ]);
        
    } catch (Exception $e) {
        handleError('Failed to close ticket: ' . $e->getMessage(), 500);
    }
}

// Rate ticket satisfaction
function rateSatisfaction() {
    
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['ticket_id']) || empty($data['rating']) || empty($data['user_id'])) {
            handleError('Missing required fields', 400);
        }
        
        // Check if rating is valid
        if ($data['rating'] < 1 || $data['rating'] > 5) {
            handleError('Rating must be between 1 and 5', 400);
        }
        
        // Check if user owns the ticket
        $ticket = getTicketById($data['ticket_id']);
        if (!$ticket || $ticket['user_id'] != $data['user_id']) {
            handleError('Unauthorized', 403);
        }
        
        // Check if ticket is closed
        if ($ticket['status'] !== 'closed') {
            handleError('Can only rate closed tickets', 400);
        }
        
        // Check if already rated
        $existingQuery = "SELECT id FROM ticket_satisfaction WHERE ticket_id = ?";
        $existingStmt = $pdo->prepare($existingQuery);
        $existingStmt->execute([$data['ticket_id']]);
        
        if ($existingStmt->fetch()) {
            handleError('Ticket already rated', 400);
        }
        
        // Add rating
        $query = "
            INSERT INTO ticket_satisfaction (ticket_id, rating, feedback)
            VALUES (?, ?, ?)
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([
            $data['ticket_id'],
            $data['rating'],
            $data['feedback'] ?? null
        ]);
        
        jsonResponse([
            'success' => true,
            'message' => 'Satisfaction rating added successfully'
        ]);
        
    } catch (Exception $e) {
        handleError('Failed to add rating: ' . $e->getMessage(), 500);
    }
}

// Get tickets for staff members
function getStaffTickets() {
    
    try {
        $userId = $_GET['user_id'] ?? null;
        $status = $_GET['status'] ?? null;
        $page = max(1, intval($_GET['page'] ?? 1));
        $limit = min(50, max(1, intval($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        
        if (!$userId || !isStaff($userId)) {
            handleError('Unauthorized', 403);
        }
        
        $whereConditions = [];
        $params = [];
        
        if ($status) {
            $whereConditions[] = "t.status = ?";
            $params[] = $status;
        }
        
        $whereClause = !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";
        
        // Get total count
        $countQuery = "
            SELECT COUNT(*) as total
            FROM support_tickets t
            $whereClause
        ";
        
        $countStmt = $pdo->prepare($countQuery);
        $countStmt->execute($params);
        $total = $countStmt->fetch()['total'];
        
        // Get tickets
        $query = "
            SELECT 
                t.*,
                c.name as category_name,
                u.name as user_name,
                u.avatar_url as user_avatar,
                s.name as staff_name,
                s.avatar_url as staff_avatar,
                (SELECT COUNT(*) FROM ticket_responses tr WHERE tr.ticket_id = t.id) as response_count,
                (SELECT MAX(created_at) FROM ticket_responses tr WHERE tr.ticket_id = t.id) as last_response
            FROM support_tickets t
            JOIN ticket_categories c ON t.category_id = c.id
            JOIN users u ON t.user_id = u.id
            LEFT JOIN users s ON t.assigned_staff_id = s.id
            $whereClause
            ORDER BY 
                CASE t.priority 
                    WHEN 'urgent' THEN 1 
                    WHEN 'high' THEN 2 
                    WHEN 'medium' THEN 3 
                    WHEN 'low' THEN 4 
                END,
                t.created_at DESC
            LIMIT ? OFFSET ?
        ";
        
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $tickets = $stmt->fetchAll();
        
        // Tags system removed - using only categories
        
        jsonResponse([
            'success' => true,
            'tickets' => $tickets,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => ceil($total / $limit)
            ]
        ]);
        
    } catch (Exception $e) {
        handleError('Failed to get staff tickets: ' . $e->getMessage(), 500);
    }
}

// Helper functions

function generateTicketNumber() {
    
    $year = date('Y');
    $prefix = "TKT-$year-";
    
    // Get the last ticket number for this year
    $query = "
        SELECT ticket_number 
        FROM support_tickets 
        WHERE ticket_number LIKE ? 
        ORDER BY ticket_number DESC 
        LIMIT 1
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$prefix . '%']);
    $lastTicket = $stmt->fetch();
    
    if ($lastTicket) {
        $lastNumber = intval(substr($lastTicket['ticket_number'], strlen($prefix)));
        $newNumber = $lastNumber + 1;
    } else {
        $newNumber = 1;
    }
    
    return $prefix . str_pad($newNumber, 3, '0', STR_PAD_LEFT);
}

function getTicketById($id) {
    
    $query = "
        SELECT 
            t.*,
            c.name as category_name,
            u.name as user_name,
            u.avatar_url as user_avatar,
            s.name as staff_name,
            s.avatar_url as staff_avatar
        FROM support_tickets t
        JOIN ticket_categories c ON t.category_id = c.id
        JOIN users u ON t.user_id = u.id
        LEFT JOIN users s ON t.assigned_staff_id = s.id
        WHERE t.id = ?
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$id]);
    $ticket = $stmt->fetch();
    
    // Tags system removed - using only categories
    
    return $ticket;
}

function getResponseById($id) {
    
    $query = "
        SELECT 
            tr.*,
            u.name as user_name,
            u.avatar_url as user_avatar
        FROM ticket_responses tr
        JOIN users u ON tr.user_id = u.id
        WHERE tr.id = ?
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$id]);
    return $stmt->fetch();
}

// Tags system removed - using only categories

function isStaff($userId) {
    
    $query = "SELECT staff_role FROM users WHERE id = ?";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    return $user && !empty($user['staff_role']);
}

function updateTicketStatus($ticketId, $newStatus, $userId) {
    
    // Get current status
    $currentQuery = "SELECT status FROM support_tickets WHERE id = ?";
    $currentStmt = $pdo->prepare($currentQuery);
    $currentStmt->execute([$ticketId]);
    $current = $currentStmt->fetch();
    
    if (!$current) return;
    
    $oldStatus = $current['status'];
    
    // Update ticket status
    $updateQuery = "UPDATE support_tickets SET status = ? WHERE id = ?";
    $updateStmt = $pdo->prepare($updateQuery);
    $updateStmt->execute([$newStatus, $ticketId]);
    
    // Log status change
    logStatusChange($ticketId, $oldStatus, $newStatus, $userId);
}

function updateTicketPriority($ticketId, $newPriority, $userId) {
    
    // Get current priority
    $currentQuery = "SELECT priority FROM support_tickets WHERE id = ?";
    $currentStmt = $pdo->prepare($currentQuery);
    $currentStmt->execute([$ticketId]);
    $current = $currentStmt->fetch();
    
    if (!$current) return;
    
    $oldPriority = $current['priority'];
    
    // Update ticket priority
    $updateQuery = "UPDATE support_tickets SET priority = ? WHERE id = ?";
    $updateStmt = $pdo->prepare($updateQuery);
    $updateStmt->execute([$newPriority, $ticketId]);
    
    // Log priority change
    logPriorityChange($ticketId, $oldPriority, $newPriority, $userId);
}

function assignTicketToStaff($ticketId, $staffId, $assignedBy) {
    
    // Get current assignment
    $currentQuery = "SELECT assigned_staff_id FROM support_tickets WHERE id = ?";
    $currentStmt = $pdo->prepare($currentQuery);
    $currentStmt->execute([$ticketId]);
    $current = $currentStmt->fetch();
    
    $oldStaffId = $current ? $current['assigned_staff_id'] : null;
    
    // Update ticket assignment
    $updateQuery = "UPDATE support_tickets SET assigned_staff_id = ? WHERE id = ?";
    $updateStmt = $pdo->prepare($updateQuery);
    $updateStmt->execute([$staffId, $ticketId]);
    
    // Log assignment change
    logAssignmentChange($ticketId, $oldStaffId, $staffId, $assignedBy);
}

function logStatusChange($ticketId, $oldStatus, $newStatus, $userId) {
    
    $query = "
        INSERT INTO ticket_status_history (ticket_id, old_status, new_status, changed_by)
        VALUES (?, ?, ?, ?)
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$ticketId, $oldStatus, $newStatus, $userId]);
}

function logPriorityChange($ticketId, $oldPriority, $newPriority, $userId) {
    
    $query = "
        INSERT INTO ticket_priority_history (ticket_id, old_priority, new_priority, changed_by, reason)
        VALUES (?, ?, ?, ?, ?)
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$ticketId, $oldPriority, $newPriority, $userId, 'Priority updated by staff']);
}

function logAssignmentChange($ticketId, $oldStaffId, $newStaffId, $assignedBy) {
    
    $query = "
        INSERT INTO ticket_assignment_history (ticket_id, old_staff_id, new_staff_id, assigned_by, reason)
        VALUES (?, ?, ?, ?, ?)
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$ticketId, $oldStaffId, $newStaffId, $assignedBy, 'Ticket reassigned']);
}

function handleError($message, $code = 500) {
    http_response_code($code);
    echo json_encode([
        'success' => false,
        'error' => $message,
        'code' => $code
    ]);
    exit;
}

function jsonResponse($data) {
    echo json_encode($data);
    exit;
}
?>
