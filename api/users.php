<?php
require_once 'database.php';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        if (isset($_GET['id'])) {
            getUser($_GET['id']);
        } else {
            getUsers();
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
            RETURNING id
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([
            $data['username'],
            $data['email'],
            $data['discord']
        ]);
        
        $userId = $stmt->fetchColumn();
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
        
        $updatableFields = ['username', 'email', 'discord', 'bio', 'avatar'];
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
?>


