<?php
require_once 'database.php';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        // Check for specific endpoints first
        if (isset($_GET['action'])) {
            switch ($_GET['action']) {
                case 'makes':
                    getCarMakes();
                    break;
                case 'models':
                    $makeId = $_GET['make_id'] ?? null;
                    if (!$makeId) handleError('Make ID required for models');
                    getCarModels($makeId);
                    break;
                default:
                    // Get cars with optional filters
                    $filters = [];
                    if (isset($_GET['make'])) $filters['make'] = $_GET['make'];
                    if (isset($_GET['model'])) $filters['model'] = $_GET['model'];
                    if (isset($_GET['minPrice'])) $filters['minPrice'] = $_GET['minPrice'];
                    if (isset($_GET['maxPrice'])) $filters['maxPrice'] = $_GET['maxPrice'];
                    if (isset($_GET['status'])) $filters['status'] = $_GET['status'];
                    if (isset($_GET['sellerId'])) $filters['sellerId'] = $_GET['sellerId'];
                    if (isset($_GET['dealerId'])) $filters['dealerId'] = $_GET['dealerId'];
                    
                    getCars($filters);
                    break;
            }
        } else {
            // Get cars with optional filters (default behavior)
            $filters = [];
            if (isset($_GET['make'])) $filters['make'] = $_GET['make'];
            if (isset($_GET['model'])) $filters['model'] = $_GET['model'];
            if (isset($_GET['minPrice'])) $filters['minPrice'] = $_GET['minPrice'];
            if (isset($_GET['maxPrice'])) $filters['maxPrice'] = $_GET['maxPrice'];
            if (isset($_GET['status'])) $filters['status'] = $_GET['status'];
            if (isset($_GET['sellerId'])) $filters['sellerId'] = $_GET['sellerId'];
            if (isset($_GET['dealerId'])) $filters['dealerId'] = $_GET['dealerId'];
            
            getCars($filters);
        }
        break;
        
    case 'POST':
        // Create new car
        $data = json_decode(file_get_contents('php://input'), true);
        createCar($data);
        break;
        
    case 'PUT':
        // Update car
        $data = json_decode(file_get_contents('php://input'), true);
        updateCar($data);
        break;
        
    case 'DELETE':
        // Delete car
        $id = $_GET['id'] ?? null;
        if (!$id) handleError('Car ID required');
        deleteCar($id);
        break;
        
    default:
        handleError('Method not allowed', 405);
}

function getCars($filters = []) {
    global $pdo;
    
    try {
        $query = "
            SELECT c.*, u.name as seller_name, u.discord as seller_discord, u.is_dealer as seller_is_dealer
            FROM cars c
            LEFT JOIN users u ON c.seller_id = u.id
            WHERE 1=1
        ";
        $params = [];
        $paramIndex = 1;
        
        if (!empty($filters['make'])) {
            $query .= " AND c.make LIKE ?";
            $params[] = '%' . $filters['make'] . '%';
        }
        
        if (!empty($filters['model'])) {
            $query .= " AND c.model LIKE ?";
            $params[] = '%' . $filters['model'] . '%';
        }
        

        
        if (!empty($filters['minPrice'])) {
            $query .= " AND c.price >= ?";
            $params[] = $filters['minPrice'];
        }
        
        if (!empty($filters['maxPrice'])) {
            $query .= " AND c.price <= ?";
            $params[] = $filters['maxPrice'];
        }
        
        if (!empty($filters['status'])) {
            $query .= " AND c.status = ?";
            $params[] = $filters['status'];
        }
        
        if (!empty($filters['sellerId'])) {
            $query .= " AND c.seller_id = ?";
            $params[] = $filters['sellerId'];
        }
        
        if (!empty($filters['dealerId'])) {
            $query .= " AND c.dealer_account_id = ?";
            $params[] = $filters['dealerId'];
        }
        
        if (!empty($filters['sellerType'])) {
            if ($filters['sellerType'] === 'dealer') {
                $query .= " AND c.is_dealer = true";
            } elseif ($filters['sellerType'] === 'private') {
                $query .= " AND c.is_dealer = false";
            }
        }
        
        $query .= " ORDER BY c.created_at DESC";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $cars = $stmt->fetchAll();
        
        jsonResponse($cars);
        
    } catch (Exception $e) {
        handleError('Failed to get cars: ' . $e->getMessage(), 500);
    }
}

function createCar($data) {
    global $pdo;
    
    try {
        $required = ['name', 'make', 'model', 'price', 'description', 'seller_id'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                handleError("Missing required field: $field");
            }
        }
        
        $query = "
            INSERT INTO cars (name, make, model, price, mileage, description, seller_id, dealer_account_id, is_dealer, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW())
            RETURNING id
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([
            $data['name'],
            $data['make'],
            $data['model'],
            $data['price'],
            $data['mileage'] ?? 0,
            $data['description'],
            $data['seller_id'],
            $data['dealer_account_id'] ?? null,
            $data['is_dealer'] ?? false
        ]);
        
        $carId = $stmt->fetchColumn();
        jsonResponse(['id' => $carId, 'message' => 'Car created successfully'], 201);
        
    } catch (Exception $e) {
        handleError('Failed to create car: ' . $e->getMessage(), 500);
    }
}

function updateCar($data) {
    global $pdo;
    
    try {
        if (empty($data['id'])) {
            handleError('Car ID required');
        }
        
        $fields = [];
        $params = [];
        
        $updatableFields = ['name', 'make', 'model', 'price', 'mileage', 'description', 'status', 'dealer_account_id', 'is_dealer'];
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
        
        $query = "UPDATE cars SET " . implode(', ', $fields) . " WHERE id = ?";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        
        if ($stmt->rowCount() > 0) {
            jsonResponse(['message' => 'Car updated successfully']);
        } else {
            handleError('Car not found', 404);
        }
        
    } catch (Exception $e) {
        handleError('Failed to update car: ' . $e->getMessage(), 500);
    }
}

function deleteCar($id) {
    global $pdo;
    
    try {
        $query = "DELETE FROM cars WHERE id = ?";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$id]);
        
        if ($stmt->rowCount() > 0) {
            jsonResponse(['message' => 'Car deleted successfully']);
        } else {
            handleError('Car not found', 404);
        }
        
    } catch (Exception $e) {
        handleError('Failed to delete car: ' . $e->getMessage(), 500);
    }
}
?>


