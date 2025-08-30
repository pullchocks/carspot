<?php
require_once 'database.php';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        if (isset($_GET['id'])) {
            getReview($_GET['id']);
        } elseif (isset($_GET['user_id'])) {
            getUserReviews($_GET['user_id']);
        } elseif (isset($_GET['action'])) {
            switch ($_GET['action']) {
                case 'responses':
                    getReviewResponses($_GET['review_id']);
                    break;
                case 'stats':
                    getUserStats($_GET['user_id']);
                    break;
                default:
                    handleError('Invalid action', 400);
            }
        } else {
            getReviews();
        }
        break;
        
    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        if (isset($_GET['action'])) {
            switch ($_GET['action']) {
                case 'add_response':
                    addReviewResponse($data);
                    break;
                default:
                    handleError('Invalid action', 400);
            }
        } else {
            createReview($data);
        }
        break;
        
    case 'PUT':
        $data = json_decode(file_get_contents('php://input'), true);
        updateReview($data);
        break;
        
    case 'DELETE':
        $id = $_GET['id'] ?? null;
        if (!$id) handleError('Review ID required');
        deleteReview($id);
        break;
        
    default:
        handleError('Method not allowed', 405);
}

function getReviews() {
    global $pdo;
    
    try {
        $query = "
            SELECT r.*, u1.username as reviewer_name, u2.username as reviewed_user_name
            FROM reviews r
            JOIN users u1 ON r.reviewer_id = u1.id
            JOIN users u2 ON r.reviewed_user_id = u2.id
            ORDER BY r.created_at DESC
        ";
        $stmt = $pdo->prepare($query);
        $stmt->execute();
        $reviews = $stmt->fetchAll();
        
        jsonResponse($reviews);
        
    } catch (Exception $e) {
        handleError('Failed to get reviews: ' . $e->getMessage(), 500);
    }
}

function getReview($id) {
    global $pdo;
    
    try {
        $query = "
            SELECT r.*, u1.username as reviewer_name, u2.username as reviewed_user_name
            FROM reviews r
            JOIN users u1 ON r.reviewer_id = u1.id
            JOIN users u2 ON r.reviewed_user_id = u2.id
            WHERE r.id = ?
        ";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$id]);
        $review = $stmt->fetch();
        
        if ($review) {
            jsonResponse($review);
        } else {
            handleError('Review not found', 404);
        }
        
    } catch (Exception $e) {
        handleError('Failed to get review: ' . $e->getMessage(), 500);
    }
}

function getUserReviews($userId) {
    global $pdo;
    
    try {
        $query = "
            SELECT r.*, u1.username as reviewer_name, u2.username as reviewed_user_name
            FROM reviews r
            JOIN users u1 ON r.reviewer_id = u1.id
            JOIN users u2 ON r.reviewed_user_id = u2.id
            WHERE r.reviewed_user_id = ?
            ORDER BY r.created_at DESC
        ";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$userId]);
        $reviews = $stmt->fetchAll();
        
        jsonResponse($reviews);
        
    } catch (Exception $e) {
        handleError('Failed to get user reviews: ' . $e->getMessage(), 500);
    }
}

function createReview($data) {
    global $pdo;
    
    try {
        $required = ['reviewer_id', 'reviewed_user_id', 'rating', 'comment', 'transaction_type'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                handleError("Missing required field: $field");
            }
        }
        
        // Validate rating
        if ($data['rating'] < 1 || $data['rating'] > 5) {
            handleError('Rating must be between 1 and 5');
        }
        
        $query = "
            INSERT INTO reviews (reviewer_id, reviewed_user_id, transaction_id, rating, comment, transaction_type, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
            RETURNING id
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([
            $data['reviewer_id'],
            $data['reviewed_user_id'],
            $data['transaction_id'] ?? null,
            $data['rating'],
            $data['comment'],
            $data['transaction_type']
        ]);
        
        $reviewId = $stmt->fetchColumn();
        jsonResponse(['id' => $reviewId, 'message' => 'Review created successfully'], 201);
        
    } catch (Exception $e) {
        handleError('Failed to create review: ' . $e->getMessage(), 500);
    }
}

function updateReview($data) {
    global $pdo;
    
    try {
        if (empty($data['id'])) {
            handleError('Review ID required');
        }
        
        $fields = [];
        $params = [];
        
        $updatableFields = ['rating', 'comment', 'transaction_type'];
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
        
        $query = "UPDATE reviews SET " . implode(', ', $fields) . " WHERE id = ?";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        
        if ($stmt->rowCount() > 0) {
            jsonResponse(['message' => 'Review updated successfully']);
        } else {
            handleError('Review not found', 404);
        }
        
    } catch (Exception $e) {
        handleError('Failed to update review: ' . $e->getMessage(), 500);
    }
}

function deleteReview($id) {
    global $pdo;
    
    try {
        $query = "DELETE FROM reviews WHERE id = ?";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$id]);
        
        if ($stmt->rowCount() > 0) {
            jsonResponse(['message' => 'Review deleted successfully']);
        } else {
            handleError('Review not found', 404);
        }
        
    } catch (Exception $e) {
        handleError('Failed to delete review: ' . $e->getMessage(), 500);
    }
}

function getReviewResponses($reviewId) {
    global $pdo;
    
    try {
        $query = "
            SELECT rr.*, u.username as responder_name
            FROM review_responses rr
            JOIN users u ON rr.responder_id = u.id
            WHERE rr.review_id = ?
            ORDER BY rr.created_at ASC
        ";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$reviewId]);
        $responses = $stmt->fetchAll();
        
        jsonResponse($responses);
        
    } catch (Exception $e) {
        handleError('Failed to get review responses: ' . $e->getMessage(), 500);
    }
}

function addReviewResponse($data) {
    global $pdo;
    
    try {
        $required = ['review_id', 'responder_id', 'response_text'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                handleError("Missing required field: $field");
            }
        }
        
        $query = "
            INSERT INTO review_responses (review_id, responder_id, response_text, created_at)
            VALUES (?, ?, ?, NOW())
            RETURNING id
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([
            $data['review_id'],
            $data['responder_id'],
            $data['response_text']
        ]);
        
        $responseId = $stmt->fetchColumn();
        jsonResponse(['id' => $responseId, 'message' => 'Response added successfully'], 201);
        
    } catch (Exception $e) {
        handleError('Failed to add review response: ' . $e->getMessage(), 500);
    }
}

function getUserStats($userId) {
    global $pdo;
    
    try {
        // Get basic user info
        $userQuery = "SELECT * FROM users WHERE id = ?";
        $userStmt = $pdo->prepare($userQuery);
        $userStmt->execute([$userId]);
        $user = $userStmt->fetch();
        
        if (!$user) {
            handleError('User not found', 404);
        }
        
        // Get review statistics
        $reviewStatsQuery = "
            SELECT 
                COUNT(*) as total_reviews,
                AVG(rating) as average_rating,
                transaction_type,
                rating
            FROM reviews 
            WHERE reviewed_user_id = ?
            GROUP BY transaction_type, rating
        ";
        $reviewStatsStmt = $pdo->prepare($reviewStatsQuery);
        $reviewStatsStmt->execute([$userId]);
        $reviewStats = $reviewStatsStmt->fetchAll();
        
        // Calculate statistics
        $totalReviews = 0;
        $totalRating = 0;
        $ratingDistribution = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
        $reviewsByType = ['buyer' => 0, 'seller' => 0];
        
        foreach ($reviewStats as $stat) {
            $totalReviews += $stat['total_reviews'];
            $totalRating += $stat['rating'] * $stat['total_reviews'];
            $ratingDistribution[$stat['rating']] += $stat['total_reviews'];
            $reviewsByType[$stat['transaction_type']] += $stat['total_reviews'];
        }
        
        $averageRating = $totalReviews > 0 ? $totalRating / $totalReviews : 0;
        
        // Get user reviews
        $reviewsQuery = "
            SELECT r.*, u.username as reviewer_name
            FROM reviews r
            JOIN users u ON r.reviewer_id = u.id
            WHERE r.reviewed_user_id = ?
            ORDER BY r.created_at DESC
        ";
        $reviewsStmt = $pdo->prepare($reviewsQuery);
        $reviewsStmt->execute([$userId]);
        $reviews = $reviewsStmt->fetchAll();
        
        // Mock profile data (you can extend this based on your schema)
        $profile = [
            'bio' => $user['bio'] ?? '',
            'avatar_url' => $user['avatar_url'] ?? '',
            'specialties' => ['Car Sales', 'Customer Service'],
            'certifications' => ['Certified Sales Professional'],
            'experience_years' => 3
        ];
        
        $stats = [
            'total_reviews' => $totalReviews,
            'average_rating' => round($averageRating, 2),
            'review_stats' => [
                'total_reviews' => $totalReviews,
                'average_rating' => round($averageRating, 2),
                'rating_distribution' => $ratingDistribution,
                'reviews_by_type' => $reviewsByType
            ],
            'profile' => $profile,
            'reviews' => $reviews,
            'awards' => [] // You can implement awards system later
        ];
        
        jsonResponse($stats);
        
    } catch (Exception $e) {
        handleError('Failed to get user stats: ' . $e->getMessage(), 500);
    }
}
?>

















