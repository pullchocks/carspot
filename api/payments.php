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
    error_log('Payments API: Database connection failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

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
    default:
        handleError('Method not allowed', 405);
}

function handleGetRequest($action) {
    switch ($action) {
        case 'plans':
            getPaymentPlans();
            break;
        case 'plan_by_type':
            getPaymentPlanByType($_GET['type']);
            break;
        case 'user_subscriptions':
            getUserSubscriptions($_GET['user_id']);
            break;
        case 'dealer_membership':
            getDealerMembership($_GET['dealer_id']);
            break;
        case 'featured_cars':
            getFeaturedCars();
            break;
        case 'transaction':
            getPaymentTransaction($_GET['id']);
            break;
        case 'admin_settings':
            getAdminSettings();
            break;
        case 'can_list_car':
            canUserListCar($_GET['user_id'], $_GET['dealer_id'] ?? null);
            break;
        case 'payment_history':
            getUserPaymentHistory($_GET['user_id']);
            break;
        default:
            handleError('Invalid action', 400);
    }
}

function handlePostRequest($action) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    switch ($action) {
        case 'purchase_listing':
            purchaseCarListing($data);
            break;
        case 'purchase_featured':
            purchaseFeaturedCar($data);
            break;
        case 'purchase_membership':
            purchaseDealerMembership($data);
            break;
        case 'renew_membership':
            renewDealerMembership($data);
            break;
        case 'process_gta_world_payment':
            processGTAWorldPayment($data);
            break;
        default:
            handleError('Invalid action', 400);
    }
}

function handlePutRequest($action) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    switch ($action) {
        case 'update_setting':
            updateAdminSetting($data);
            break;
        default:
            handleError('Invalid action', 400);
    }
}

// Get all payment plans
function getPaymentPlans() {
    global $pdo;
    
    try {
        $query = "SELECT * FROM payment_plans WHERE is_active = true ORDER BY type, price";
        $stmt = $pdo->prepare($query);
        $stmt->execute();
        $plans = $stmt->fetchAll();
        
        jsonResponse($plans);
    } catch (Exception $e) {
        handleError('Failed to get payment plans: ' . $e->getMessage(), 500);
    }
}

// Get payment plan by type
function getPaymentPlanByType($type) {
    global $pdo;
    
    try {
        $query = "SELECT * FROM payment_plans WHERE type = ? AND is_active = true LIMIT 1";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$type]);
        $plan = $stmt->fetch();
        
        if ($plan) {
            jsonResponse($plan);
        } else {
            handleError('Payment plan not found', 404);
        }
    } catch (Exception $e) {
        handleError('Failed to get payment plan: ' . $e->getMessage(), 500);
    }
}

// Purchase car listing
function purchaseCarListing($data) {
    global $pdo;
    
    try {
        if (empty($data['car_id']) || empty($data['user_id'])) {
            handleError('Car ID and User ID required');
        }
        
        // Get listing plan
        $planQuery = "SELECT * FROM payment_plans WHERE type = 'listing' AND is_active = true LIMIT 1";
        $planStmt = $pdo->prepare($planQuery);
        $planStmt->execute();
        $plan = $planStmt->fetch();
        
        if (!$plan) {
            handleError('Listing plan not found');
        }
        
        // Calculate dates
        $startDate = date('Y-m-d H:i:s');
        $endDate = date('Y-m-d H:i:s', strtotime("+{$plan['duration_days']} days"));
        
        // Create payment record
        $paymentQuery = "
            INSERT INTO car_listing_payments (car_id, user_id, plan_id, amount, listing_start_date, listing_end_date)
            VALUES (?, ?, ?, ?, ?, ?)
        ";
        
        $paymentStmt = $pdo->prepare($paymentQuery);
        $paymentStmt->execute([
            $data['car_id'],
            $data['user_id'],
            $plan['id'],
            $plan['price'],
            $startDate,
            $endDate
        ]);
        
        $paymentId = $pdo->lastInsertId();
        
        // Update car with payment info
        $carQuery = "
            UPDATE cars 
            SET listing_payment_id = ?, listing_expires_at = ?, status = 'active'
            WHERE id = ?
        ";
        
        $carStmt = $pdo->prepare($carQuery);
        $carStmt->execute([$paymentId, $endDate, $data['car_id']]);
        
        // Create payment transaction
        $transactionQuery = "
            INSERT INTO payment_transactions (user_id, amount, payment_type, reference_id, reference_table, status)
            VALUES (?, ?, 'listing', ?, 'car_listing_payments', 'pending')
        ";
        
        $transactionStmt = $pdo->prepare($transactionQuery);
        $transactionStmt->execute([
            $data['user_id'],
            $plan['price'],
            $paymentId
        ]);
        
        jsonResponse([
            'id' => $paymentId,
            'message' => 'Car listing purchased successfully',
            'amount' => $plan['price'],
            'expires_at' => $endDate
        ], 201);
        
    } catch (Exception $e) {
        handleError('Failed to purchase car listing: ' . $e->getMessage(), 500);
    }
}

// Purchase featured car
function purchaseFeaturedCar($data) {
    global $pdo;
    
    try {
        if (empty($data['car_id']) || empty($data['user_id'])) {
            handleError('Car ID and User ID required');
        }
        
        // Get featured plan
        $planQuery = "SELECT * FROM payment_plans WHERE type = 'featured' AND is_active = true LIMIT 1";
        $planStmt = $pdo->prepare($planQuery);
        $planStmt->execute();
        $plan = $planStmt->fetch();
        
        if (!$plan) {
            handleError('Featured plan not found');
        }
        
        // Calculate dates
        $startTime = date('Y-m-d H:i:s');
        $endTime = date('Y-m-d H:i:s', strtotime("+{$plan['duration_hours']} hours"));
        
        // Create featured car record
        $featuredQuery = "
            INSERT INTO featured_cars (car_id, user_id, start_time, end_time)
            VALUES (?, ?, ?, ?)
        ";
        
        $featuredStmt = $pdo->prepare($featuredQuery);
        $featuredStmt->execute([
            $data['car_id'],
            $data['user_id'],
            $startTime,
            $endTime
        ]);
        
        $featuredId = $pdo->lastInsertId();
        
        // Update car as featured
        $carQuery = "
            UPDATE cars 
            SET is_featured = true, featured_until = ?
            WHERE id = ?
        ";
        
        $carStmt = $pdo->prepare($carQuery);
        $carStmt->execute([$endTime, $data['car_id']]);
        
        // Create payment transaction
        $transactionQuery = "
            INSERT INTO payment_transactions (user_id, amount, payment_type, reference_id, reference_table, status)
            VALUES (?, ?, 'featured', ?, 'featured_cars', 'pending')
        ";
        
        $transactionStmt = $pdo->prepare($transactionQuery);
        $transactionStmt->execute([
            $data['user_id'],
            $plan['price'],
            $featuredId
        ]);
        
        jsonResponse([
            'id' => $featuredId,
            'message' => 'Car featured successfully',
            'amount' => $plan['price'],
            'featured_until' => $endTime
        ], 201);
        
    } catch (Exception $e) {
        handleError('Failed to purchase featured car: ' . $e->getMessage(), 500);
    }
}

// Purchase dealer membership
function purchaseDealerMembership($data) {
    global $pdo;
    
    try {
        if (empty($data['dealer_account_id']) || empty($data['user_id'])) {
            handleError('Dealer Account ID and User ID required');
        }
        
        // Get membership plan
        $planQuery = "SELECT * FROM payment_plans WHERE type = 'membership' AND is_active = true LIMIT 1";
        $planStmt = $pdo->prepare($planQuery);
        $planStmt->execute();
        $plan = $planStmt->fetch();
        
        if (!$plan) {
            handleError('Membership plan not found');
        }
        
        // Calculate dates
        $startDate = date('Y-m-d H:i:s');
        $endDate = date('Y-m-d H:i:s', strtotime("+{$plan['duration_days']} days"));
        $nextPaymentDate = date('Y-m-d H:i:s', strtotime("+{$plan['duration_days']} days"));
        $gracePeriodEnd = date('Y-m-d H:i:s', strtotime("+{$plan['duration_days']} days +30 days"));
        
        // Create or update dealer membership
        $membershipQuery = "
            INSERT INTO dealer_memberships (dealer_account_id, status, start_date, end_date, monthly_fee, next_payment_date, grace_period_end)
            VALUES (?, 'active', ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
            status = 'active',
            start_date = VALUES(start_date),
            end_date = VALUES(end_date),
            next_payment_date = VALUES(next_payment_date),
            grace_period_end = VALUES(grace_period_end)
        ";
        
        $membershipStmt = $pdo->prepare($membershipQuery);
        $membershipStmt->execute([
            $data['dealer_account_id'],
            $startDate,
            $endDate,
            $plan['price'],
            $nextPaymentDate,
            $gracePeriodEnd
        ]);
        
        $membershipId = $pdo->lastInsertId();
        
        // Create payment transaction
        $transactionQuery = "
            INSERT INTO payment_transactions (user_id, amount, payment_type, reference_id, reference_table, status)
            VALUES (?, ?, 'membership', ?, 'dealer_memberships', 'pending')
        ";
        
        $transactionStmt = $pdo->prepare($transactionQuery);
        $transactionStmt->execute([
            $data['user_id'],
            $plan['price'],
            $membershipId
        ]);
        
        jsonResponse([
            'id' => $membershipId,
            'message' => 'Dealer membership purchased successfully',
            'amount' => $plan['price'],
            'expires_at' => $endDate,
            'next_payment_date' => $nextPaymentDate
        ], 201);
        
    } catch (Exception $e) {
        handleError('Failed to purchase dealer membership: ' . $e->getMessage(), 500);
    }
}

// Renew dealer membership
function renewDealerMembership($data) {
    global $pdo;
    
    try {
        if (empty($data['membership_id']) || empty($data['user_id'])) {
            handleError('Membership ID and User ID required');
        }
        
        // Get current membership
        $membershipQuery = "SELECT * FROM dealer_memberships WHERE id = ?";
        $membershipStmt = $pdo->prepare($membershipQuery);
        $membershipStmt->execute([$data['membership_id']]);
        $membership = $membershipStmt->fetch();
        
        if (!$membership) {
            handleError('Membership not found');
        }
        
        // Calculate new dates
        $newEndDate = date('Y-m-d H:i:s', strtotime("+30 days"));
        $newNextPaymentDate = date('Y-m-d H:i:s', strtotime("+30 days"));
        $newGracePeriodEnd = date('Y-m-d H:i:s', strtotime("+30 days +30 days"));
        
        // Update membership
        $updateQuery = "
            UPDATE dealer_memberships 
            SET status = 'active', end_date = ?, next_payment_date = ?, grace_period_end = ?, last_payment_date = NOW()
            WHERE id = ?
        ";
        
        $updateStmt = $pdo->prepare($updateQuery);
        $updateStmt->execute([
            $newEndDate,
            $newNextPaymentDate,
            $newGracePeriodEnd,
            $data['membership_id']
        ]);
        
        // Create payment transaction
        $transactionQuery = "
            INSERT INTO payment_transactions (user_id, amount, payment_type, reference_id, reference_table, status)
            VALUES (?, ?, 'renewal', ?, 'dealer_memberships', 'pending')
        ";
        
        $transactionStmt = $pdo->prepare($transactionQuery);
        $transactionStmt->execute([
            $data['user_id'],
            $membership['monthly_fee'],
            $data['membership_id']
        ]);
        
        jsonResponse([
            'message' => 'Dealer membership renewed successfully',
            'amount' => $membership['monthly_fee'],
            'expires_at' => $newEndDate,
            'next_payment_date' => $newNextPaymentDate
        ]);
        
    } catch (Exception $e) {
        handleError('Failed to renew dealer membership: ' . $e->getMessage(), 500);
    }
}

// Process GTA World payment
function processGTAWorldPayment($data) {
    global $pdo;
    
    try {
        if (empty($data['amount']) || empty($data['user_id']) || empty($data['payment_type']) || empty($data['reference_id']) || empty($data['reference_table'])) {
            handleError('Missing required payment data');
        }
        
        // Generate GTA World transaction ID (in real implementation, this would come from GTA World API)
        $gtaWorldTransactionId = 'GTW_' . time() . '_' . rand(1000, 9999);
        
        // Update payment transaction
        $updateQuery = "
            UPDATE payment_transactions 
            SET gta_world_transaction_id = ?, status = 'completed'
            WHERE user_id = ? AND payment_type = ? AND reference_id = ? AND reference_table = ?
        ";
        
        $updateStmt = $pdo->prepare($updateQuery);
        $updateStmt->execute([
            $gtaWorldTransactionId,
            $data['user_id'],
            $data['payment_type'],
            $data['reference_id'],
            $data['reference_table']
        ]);
        
        // Update related payment status based on type
        switch ($data['payment_type']) {
            case 'listing':
                updateCarListingPaymentStatus($data['reference_id'], 'paid');
                break;
            case 'featured':
                updateFeaturedCarPaymentStatus($data['reference_id'], 'paid');
                break;
            case 'membership':
            case 'renewal':
                updateDealerMembershipPaymentStatus($data['reference_id'], 'active');
                break;
        }
        
        jsonResponse([
            'message' => 'Payment processed successfully',
            'gta_world_transaction_id' => $gtaWorldTransactionId,
            'status' => 'completed'
        ]);
        
    } catch (Exception $e) {
        handleError('Failed to process GTA World payment: ' . $e->getMessage(), 500);
    }
}

// Helper functions
function updateCarListingPaymentStatus($paymentId, $status) {
    global $pdo;
    $query = "UPDATE car_listing_payments SET payment_status = ? WHERE id = ?";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$status, $paymentId]);
}

function updateFeaturedCarPaymentStatus($featuredId, $status) {
    global $pdo;
    $query = "UPDATE featured_cars SET payment_status = ? WHERE id = ?";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$status, $featuredId]);
}

function updateDealerMembershipPaymentStatus($membershipId, $status) {
    global $pdo;
    $query = "UPDATE dealer_memberships SET status = ? WHERE id = ?";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$status, $membershipId]);
}

// Get user subscriptions
function getUserSubscriptions($userId) {
    global $pdo;
    
    try {
        $query = "
            SELECT us.*, pp.name as plan_name, pp.type, pp.price
            FROM user_subscriptions us
            JOIN payment_plans pp ON us.plan_id = pp.id
            WHERE us.user_id = ?
            ORDER BY us.created_at DESC
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([$userId]);
        $subscriptions = $stmt->fetchAll();
        
        jsonResponse($subscriptions);
    } catch (Exception $e) {
        handleError('Failed to get user subscriptions: ' . $e->getMessage(), 500);
    }
}

// Get dealer membership
function getDealerMembership($dealerId) {
    global $pdo;
    
    try {
        $query = "SELECT * FROM dealer_memberships WHERE dealer_account_id = ? ORDER BY created_at DESC LIMIT 1";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$dealerId]);
        $membership = $stmt->fetch();
        
        if ($membership) {
            jsonResponse($membership);
        } else {
            jsonResponse(null);
        }
    } catch (Exception $e) {
        handleError('Failed to get dealer membership: ' . $e->getMessage(), 500);
    }
}

// Get featured cars
function getFeaturedCars() {
    global $pdo;
    
    try {
        $query = "
            SELECT fc.*, c.*, u.name as seller_name
            FROM featured_cars fc
            JOIN cars c ON fc.car_id = c.id
            JOIN users u ON c.seller_id = u.id
            WHERE fc.status = 'active' AND fc.end_time > NOW()
            ORDER BY fc.start_time DESC
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute();
        $featuredCars = $stmt->fetchAll();
        
        jsonResponse($featuredCars);
    } catch (Exception $e) {
        handleError('Failed to get featured cars: ' . $e->getMessage(), 500);
    }
}

// Get payment transaction
function getPaymentTransaction($transactionId) {
    global $pdo;
    
    try {
        $query = "SELECT * FROM payment_transactions WHERE id = ?";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$transactionId]);
        $transaction = $stmt->fetch();
        
        if ($transaction) {
            jsonResponse($transaction);
        } else {
            handleError('Transaction not found', 404);
        }
    } catch (Exception $e) {
        handleError('Failed to get payment transaction: ' . $e->getMessage(), 500);
    }
}

// Get admin settings
function getAdminSettings() {
    global $pdo;
    
    try {
        $query = "SELECT * FROM admin_settings ORDER BY setting_key";
        $stmt = $pdo->prepare($query);
        $stmt->execute();
        $settings = $stmt->fetchAll();
        
        jsonResponse($settings);
    } catch (Exception $e) {
        handleError('Failed to get admin settings: ' . $e->getMessage(), 500);
    }
}

// Update admin setting
function updateAdminSetting($data) {
    global $pdo;
    
    try {
        if (empty($data['setting_key']) || !isset($data['setting_value'])) {
            handleError('Setting key and value required');
        }
        
        $query = "UPDATE admin_settings SET setting_value = ? WHERE setting_key = ? AND is_editable = true";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$data['setting_value'], $data['setting_key']]);
        
        if ($stmt->rowCount() > 0) {
            jsonResponse(['message' => 'Setting updated successfully']);
        } else {
            handleError('Setting not found or not editable', 404);
        }
    } catch (Exception $e) {
        handleError('Failed to update admin setting: ' . $e->getMessage(), 500);
    }
}

// Check if user can list car
function canUserListCar($userId, $dealerId = null) {
    global $pdo;
    
    try {
        $canList = false;
        $reason = '';
        $membership = null;
        $requiresPayment = false;
        $paymentAmount = 0;
        
        if ($dealerId) {
            // Check dealer membership
            $membershipQuery = "
                SELECT * FROM dealer_memberships 
                WHERE dealer_account_id = ? AND status = 'active' AND end_date > NOW()
            ";
            $membershipStmt = $pdo->prepare($membershipQuery);
            $membershipStmt->execute([$dealerId]);
            $membership = $membershipStmt->fetch();
            
            if ($membership) {
                $canList = true;
                $reason = 'Active dealer membership';
            } else {
                // Check grace period
                $graceQuery = "
                    SELECT * FROM dealer_memberships 
                    WHERE dealer_account_id = ? AND grace_period_end > NOW()
                ";
                $graceStmt = $pdo->prepare($graceQuery);
                $graceStmt->execute([$dealerId]);
                $graceMembership = $graceStmt->fetch();
                
                if ($graceMembership) {
                    $canList = true;
                    $reason = 'Grace period active';
                } else {
                    $canList = false;
                    $reason = 'Dealer membership expired';
                    $requiresPayment = true;
                    
                    // Get membership plan price
                    $planQuery = "SELECT price FROM payment_plans WHERE type = 'membership' AND is_active = true LIMIT 1";
                    $planStmt = $pdo->prepare($planQuery);
                    $planStmt->execute();
                    $plan = $planStmt->fetch();
                    $paymentAmount = $plan ? $plan['price'] : 25000.00;
                }
            }
        } else {
            // Private seller - check if they can pay for listing
            $canList = true;
            $reason = 'Private seller - payment required';
            $requiresPayment = true;
            
            // Get listing plan price
            $planQuery = "SELECT price FROM payment_plans WHERE type = 'listing' AND is_active = true LIMIT 1";
            $planStmt = $pdo->prepare($planQuery);
            $planStmt->execute();
            $plan = $planStmt->fetch();
            $paymentAmount = $plan ? $plan['price'] : 1500.00;
        }
        
        jsonResponse([
            'can_list' => $canList,
            'reason' => $reason,
            'membership' => $membership,
            'requires_payment' => $requiresPayment,
            'payment_amount' => $paymentAmount
        ]);
        
    } catch (Exception $e) {
        handleError('Failed to check listing permissions: ' . $e->getMessage(), 500);
    }
}

// Get user payment history
function getUserPaymentHistory($userId) {
    global $pdo;
    
    try {
        $query = "
            SELECT * FROM payment_transactions 
            WHERE user_id = ? 
            ORDER BY created_at DESC
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([$userId]);
        $transactions = $stmt->fetchAll();
        
        jsonResponse($transactions);
    } catch (Exception $e) {
        handleError('Failed to get payment history: ' . $e->getMessage(), 500);
    }
}

// Utility functions - using functions from database.php
?>




