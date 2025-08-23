<?php
require_once 'database.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

switch ($method) {
    case 'GET':
        handleGetRequest($action);
        break;
    case 'POST':
        handlePostRequest($action);
        break;
    case 'PUT':
        handlePutRequest($action);
        break;
    case 'DELETE':
        handleDeleteRequest($action);
        break;
    default:
        handleError('Method not allowed', 405);
}

function handleGetRequest($action) {
    switch ($action) {
        case 'user_sales':
            $userId = $_GET['user_id'] ?? null;
            if (!$userId) handleError('User ID required');
            getUserSales($userId);
            break;
        case 'dealer_sales':
            $dealerId = $_GET['dealer_id'] ?? null;
            if (!$dealerId) handleError('Dealer ID required');
            getDealerSales($dealerId);
            break;
        case 'my_sales':
            $userId = $_GET['user_id'] ?? null;
            if (!$userId) handleError('User ID required');
            getMySales($userId);
            break;
        case 'car_sale':
            $carId = $_GET['car_id'] ?? null;
            if (!$carId) handleError('Car ID required');
            getCarSale($carId);
            break;
        case 'sales_analytics':
            getSalesAnalytics();
            break;
        default:
            getSales();
    }
}

function handlePostRequest($action) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    switch ($action) {
        case 'complete_sale':
            completeCarSale($data);
            break;
        case 'verify_sale':
            verifySale($data);
            break;
        default:
            handleError('Invalid action', 400);
    }
}

function handlePutRequest($action) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    switch ($action) {
        case 'update_sale':
            updateSale($data);
            break;
        case 'update_sale_amount':
            updateSaleAmount($data);
            break;
        default:
            handleError('Invalid action', 400);
    }
}

function handleDeleteRequest($action) {
    $saleId = $_GET['id'] ?? null;
    if (!$saleId) handleError('Sale ID required');
    
    switch ($action) {
        case 'delete_sale':
            deleteSale($saleId);
            break;
        default:
            handleError('Invalid action', 400);
    }
}

// Get all sales (with pagination and filters)
function getSales() {
    global $pdo;
    
    try {
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
        $status = $_GET['status'] ?? null;
        $sellerId = $_GET['seller_id'] ?? null;
        $dealerId = $_GET['dealer_id'] ?? null;
        
        $offset = ($page - 1) * $limit;
        
        $whereConditions = [];
        $params = [];
        
        if ($status) {
            $whereConditions[] = "cs.is_verified = ?";
            $params[] = $status === 'verified' ? 1 : 0;
        }
        
        if ($sellerId) {
            $whereConditions[] = "cs.seller_id = ?";
            $params[] = $sellerId;
        }
        
        if ($dealerId) {
            $whereConditions[] = "c.dealer_account_id = ?";
            $params[] = $dealerId;
        }
        
        $whereClause = !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";
        
        // Get total count
        $countQuery = "
            SELECT COUNT(*) as total 
            FROM car_sales cs
            LEFT JOIN cars c ON cs.car_id = c.id
            $whereClause
        ";
        $countStmt = $pdo->prepare($countQuery);
        $countStmt->execute($params);
        $total = $countStmt->fetch()['total'];
        
        // Get sales with car and user information
        $query = "
            SELECT 
                cs.*,
                c.name as car_name,
                c.make,
                c.model,
                c.year,
                c.price as listing_price,
                u1.name as seller_name,
                u1.discord as seller_discord,
                u2.name as buyer_name,
                u2.discord as buyer_discord,
                u3.name as verified_by_name
            FROM car_sales cs
            LEFT JOIN cars c ON cs.car_id = c.id
            LEFT JOIN users u1 ON cs.seller_id = u1.id
            LEFT JOIN users u2 ON cs.buyer_id = u2.id
            LEFT JOIN users u3 ON cs.verified_by = u3.id
            $whereClause
            ORDER BY cs.sale_date DESC
            LIMIT ? OFFSET ?
        ";
        
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $sales = $stmt->fetchAll();
        
        jsonResponse([
            'sales' => $sales,
            'total' => $total,
            'page' => $page,
            'limit' => $limit
        ]);
        
    } catch (Exception $e) {
        handleError('Failed to get sales: ' . $e->getMessage(), 500);
    }
}

// Get sales for a specific user
function getUserSales($userId) {
    global $pdo;
    
    try {
        $query = "
            SELECT 
                cs.*,
                c.name as car_name,
                c.make,
                c.model,
                c.year,
                c.price as listing_price
            FROM car_sales cs
            LEFT JOIN cars c ON cs.car_id = c.id
            WHERE cs.seller_id = ?
            ORDER BY cs.sale_date DESC
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([$userId]);
        $sales = $stmt->fetchAll();
        
        jsonResponse($sales);
        
    } catch (Exception $e) {
        handleError('Failed to get user sales: ' . $e->getMessage(), 500);
    }
}

// Get sales for a specific dealer
function getDealerSales($dealerId) {
    global $pdo;
    
    try {
        $query = "
            SELECT 
                cs.*,
                c.name as car_name,
                c.make,
                c.model,
                c.year,
                c.price as listing_price,
                u1.name as seller_name,
                u1.discord as seller_discord
            FROM car_sales cs
            LEFT JOIN cars c ON cs.car_id = c.id
            LEFT JOIN users u1 ON cs.seller_id = u1.id
            WHERE c.dealer_account_id = ?
            ORDER BY cs.sale_date DESC
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([$dealerId]);
        $sales = $stmt->fetchAll();
        
        jsonResponse($sales);
        
    } catch (Exception $e) {
        handleError('Failed to get dealer sales: ' . $e->getMessage(), 500);
    }
}

// Get sales for the current user (for editing amounts)
function getMySales($userId) {
    global $pdo;
    
    try {
        $query = "
            SELECT 
                cs.*,
                c.name as car_name,
                c.make,
                c.model,
                c.year,
                c.price as listing_price,
                c.dealer_account_id,
                da.company_name as dealer_name
            FROM car_sales cs
            LEFT JOIN cars c ON cs.car_id = c.id
            LEFT JOIN dealer_accounts da ON c.dealer_account_id = da.id
            WHERE cs.seller_id = ?
            ORDER BY cs.sale_date DESC
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([$userId]);
        $sales = $stmt->fetchAll();
        
        jsonResponse($sales);
        
    } catch (Exception $e) {
        handleError('Failed to get user sales: ' . $e->getMessage(), 500);
    }
}

// Get sale information for a specific car
function getCarSale($carId) {
    global $pdo;
    
    try {
        $query = "
            SELECT 
                cs.*,
                c.name as car_name,
                c.make,
                c.model,
                c.year,
                c.price as listing_price,
                u1.name as seller_name,
                u1.discord as seller_discord,
                u2.name as buyer_name,
                u2.discord as buyer_discord,
                u3.name as verified_by_name
            FROM car_sales cs
            LEFT JOIN cars c ON cs.car_id = c.id
            LEFT JOIN users u1 ON cs.seller_id = u1.id
            LEFT JOIN users u2 ON cs.buyer_id = u2.id
            LEFT JOIN users u3 ON cs.verified_by = u3.id
            WHERE cs.car_id = ?
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([$carId]);
        $sale = $stmt->fetch();
        
        if ($sale) {
            jsonResponse($sale);
        } else {
            handleError('Sale not found', 404);
        }
        
    } catch (Exception $e) {
        handleError('Failed to get car sale: ' . $e->getMessage(), 500);
    }
}

// Complete a car sale (mark as sold)
function completeCarSale($data) {
    global $pdo;
    
    try {
        if (empty($data['car_id']) || empty($data['seller_id'])) {
            handleError('Car ID and seller ID required');
        }
        
        // Start transaction
        $pdo->beginTransaction();
        
        // Check if car exists and is available
        $carQuery = "SELECT * FROM cars WHERE id = ? AND status = 'active'";
        $carStmt = $pdo->prepare($carQuery);
        $carStmt->execute([$data['car_id']]);
        $car = $carStmt->fetch();
        
        if (!$car) {
            $pdo->rollBack();
            handleError('Car not found or not available for sale');
        }
        
        // Check if sale already exists
        $existingSaleQuery = "SELECT id FROM car_sales WHERE car_id = ?";
        $existingSaleStmt = $pdo->prepare($existingSaleQuery);
        $existingSaleStmt->execute([$data['car_id']]);
        
        if ($existingSaleStmt->fetch()) {
            $pdo->rollBack();
            handleError('Car already marked as sold');
        }
        
        // Create sale record
        $saleQuery = "
            INSERT INTO car_sales (
                car_id, seller_id, buyer_id, buyer_name, buyer_discord,
                sale_price, sale_notes, sale_method
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ";
        
        $saleStmt = $pdo->prepare($saleQuery);
        $saleStmt->execute([
            $data['car_id'],
            $data['seller_id'],
            $data['buyer_id'] ?? null,
            $data['buyer_name'] ?? null,
            $data['buyer_discord'] ?? null,
            $data['sale_price'] ?? null,
            $data['sale_notes'] ?? null,
            $data['sale_method'] ?? 'online'
        ]);
        
        // Update car status to sold
        $updateCarQuery = "UPDATE cars SET status = 'sold' WHERE id = ?";
        $updateCarStmt = $pdo->prepare($updateCarQuery);
        $updateCarStmt->execute([$data['car_id']]);
        
        // Remove from featured cars if it was featured
        $removeFeaturedQuery = "UPDATE cars SET is_featured = FALSE, featured_until = NULL WHERE id = ?";
        $removeFeaturedStmt = $pdo->prepare($removeFeaturedQuery);
        $removeFeaturedStmt->execute([$data['car_id']]);
        
        // Commit transaction
        $pdo->commit();
        
        jsonResponse(['message' => 'Sale completed successfully', 'sale_id' => $pdo->lastInsertId()]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        handleError('Failed to complete sale: ' . $e->getMessage(), 500);
    }
}

// Verify a sale (staff only)
function verifySale($data) {
    global $pdo;
    
    try {
        if (empty($data['sale_id']) || empty($data['verified_by'])) {
            handleError('Sale ID and verifier ID required');
        }
        
        $query = "UPDATE car_sales SET is_verified = TRUE, verified_by = ? WHERE id = ?";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$data['verified_by'], $data['sale_id']]);
        
        if ($stmt->rowCount() > 0) {
            jsonResponse(['message' => 'Sale verified successfully']);
        } else {
            handleError('Sale not found', 404);
        }
        
    } catch (Exception $e) {
        handleError('Failed to verify sale: ' . $e->getMessage(), 500);
    }
}

// Update sale information
function updateSale($data) {
    global $pdo;
    
    try {
        if (empty($data['sale_id'])) {
            handleError('Sale ID required');
        }
        
        $updateFields = [];
        $params = [];
        
        if (isset($data['buyer_name'])) {
            $updateFields[] = "buyer_name = ?";
            $params[] = $data['buyer_name'];
        }
        
        if (isset($data['buyer_discord'])) {
            $updateFields[] = "buyer_discord = ?";
            $params[] = $data['buyer_discord'];
        }
        
        if (isset($data['sale_price'])) {
            $updateFields[] = "sale_price = ?";
            $params[] = $data['sale_price'];
        }
        
        if (isset($data['sale_notes'])) {
            $updateFields[] = "sale_notes = ?";
            $params[] = $data['sale_notes'];
        }
        
        if (isset($data['sale_method'])) {
            $updateFields[] = "sale_method = ?";
            $params[] = $data['sale_method'];
        }
        
        if (empty($updateFields)) {
            handleError('No fields to update');
        }
        
        $params[] = $data['sale_id'];
        
        $query = "UPDATE car_sales SET " . implode(', ', $updateFields) . " WHERE id = ?";
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        
        if ($stmt->rowCount() > 0) {
            jsonResponse(['message' => 'Sale updated successfully']);
        } else {
            handleError('Sale not found', 404);
        }
        
    } catch (Exception $e) {
        handleError('Failed to update sale: ' . $e->getMessage(), 500);
    }
}

// Update sale amount specifically (for financial tracking)
function updateSaleAmount($data) {
    global $pdo;
    
    try {
        if (empty($data['sale_id']) || !isset($data['sale_price'])) {
            handleError('Sale ID and sale price required');
        }
        
        // Validate sale price is numeric and positive
        if (!is_numeric($data['sale_price']) || $data['sale_price'] < 0) {
            handleError('Sale price must be a positive number');
        }
        
        // Check if sale exists and belongs to the user
        $saleQuery = "SELECT seller_id FROM car_sales WHERE id = ?";
        $saleStmt = $pdo->prepare($saleQuery);
        $saleStmt->execute([$data['sale_id']]);
        $sale = $saleStmt->fetch();
        
        if (!$sale) {
            handleError('Sale not found', 404);
        }
        
        // Optional: Check if user is authorized to update this sale
        if (isset($data['user_id']) && $sale['seller_id'] != $data['user_id']) {
            handleError('Unauthorized to update this sale', 403);
        }
        
        // Update the sale amount
        $updateQuery = "UPDATE car_sales SET sale_price = ?, updated_at = NOW() WHERE id = ?";
        $updateStmt = $pdo->prepare($updateQuery);
        $updateStmt->execute([$data['sale_price'], $data['sale_id']]);
        
        if ($updateStmt->rowCount() > 0) {
            jsonResponse([
                'message' => 'Sale amount updated successfully',
                'new_amount' => $data['sale_price']
            ]);
        } else {
            handleError('Failed to update sale amount', 500);
        }
        
    } catch (Exception $e) {
        handleError('Failed to update sale amount: ' . $e->getMessage(), 500);
    }
}

// Delete a sale (staff only)
function deleteSale($saleId) {
    global $pdo;
    
    try {
        // Start transaction
        $pdo->beginTransaction();
        
        // Get car ID from sale
        $carQuery = "SELECT car_id FROM car_sales WHERE id = ?";
        $carStmt = $pdo->prepare($carQuery);
        $carStmt->execute([$saleId]);
        $sale = $carStmt->fetch();
        
        if (!$sale) {
            $pdo->rollBack();
            handleError('Sale not found', 404);
        }
        
        // Delete sale record
        $deleteSaleQuery = "DELETE FROM car_sales WHERE id = ?";
        $deleteSaleStmt = $pdo->prepare($deleteSaleQuery);
        $deleteSaleStmt->execute([$saleId]);
        
        // Reset car status to active
        $updateCarQuery = "UPDATE cars SET status = 'active' WHERE id = ?";
        $updateCarStmt = $pdo->prepare($updateCarQuery);
        $updateCarStmt->execute([$sale['car_id']]);
        
        // Commit transaction
        $pdo->commit();
        
        jsonResponse(['message' => 'Sale deleted and car restored successfully']);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        handleError('Failed to delete sale: ' . $e->getMessage(), 500);
    }
}

// Get sales analytics
function getSalesAnalytics() {
    global $pdo;
    
    try {
        $queries = [
            'total_sales' => "SELECT COUNT(*) as count FROM car_sales",
            'verified_sales' => "SELECT COUNT(*) as count FROM car_sales WHERE is_verified = TRUE",
            'pending_verification' => "SELECT COUNT(*) as count FROM car_sales WHERE is_verified = FALSE",
            'sales_this_month' => "SELECT COUNT(*) as count FROM car_sales WHERE MONTH(sale_date) = MONTH(CURRENT_DATE) AND YEAR(sale_date) = YEAR(CURRENT_DATE)",
            'sales_last_month' => "SELECT COUNT(*) as count FROM car_sales WHERE MONTH(sale_date) = MONTH(DATE_SUB(CURRENT_DATE, INTERVAL 1 MONTH)) AND YEAR(sale_date) = YEAR(DATE_SUB(CURRENT_DATE, INTERVAL 1 MONTH))",
            'total_sellers' => "SELECT COUNT(DISTINCT seller_id) as count FROM car_sales",
            'total_buyers' => "SELECT COUNT(DISTINCT buyer_id) as count FROM car_sales WHERE buyer_id IS NOT NULL"
        ];
        
        $analytics = [];
        foreach ($queries as $key => $query) {
            $stmt = $pdo->prepare($query);
            $stmt->execute();
            $result = $stmt->fetch();
            $analytics[$key] = $result['count'] ?? 0;
        }
        
        // Get sales by month for the last 12 months
        $monthlyQuery = "
            SELECT 
                DATE_FORMAT(sale_date, '%Y-%m') as month,
                COUNT(*) as sales_count
            FROM car_sales 
            WHERE sale_date >= DATE_SUB(CURRENT_DATE, INTERVAL 12 MONTH)
            GROUP BY DATE_FORMAT(sale_date, '%Y-%m')
            ORDER BY month DESC
        ";
        
        $monthlyStmt = $pdo->prepare($monthlyQuery);
        $monthlyStmt->execute();
        $analytics['monthly_sales'] = $monthlyStmt->fetchAll();
        
        // Get top sellers
        $topSellersQuery = "
            SELECT 
                u.name as seller_name,
                u.discord as seller_discord,
                COUNT(*) as sales_count
            FROM car_sales cs
            LEFT JOIN users u ON cs.seller_id = u.id
            GROUP BY cs.seller_id, u.name, u.discord
            ORDER BY sales_count DESC
            LIMIT 10
        ";
        
        $topSellersStmt = $pdo->prepare($topSellersQuery);
        $topSellersStmt->execute();
        $analytics['top_sellers'] = $topSellersStmt->fetchAll();
        
        jsonResponse($analytics);
        
    } catch (Exception $e) {
        handleError('Failed to get sales analytics: ' . $e->getMessage(), 500);
    }
}

// Helper function to send JSON response
function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// Helper function to handle errors
function handleError($message, $statusCode = 400) {
    jsonResponse(['error' => $message], $statusCode);
}
?>
