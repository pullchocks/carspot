<?php
require_once 'database.php';

// Handle different HTTP methods
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        handleGet();
        break;
    case 'POST':
        handlePost();
        break;
    case 'PUT':
        handlePut();
        break;
    case 'DELETE':
        handleDelete();
        break;
    default:
        http_response_code(405);
        jsonResponse(['error' => 'Method not allowed']);
}

function handleGet() {
    $action = $_GET['action'] ?? '';
    
    switch ($action) {
        case 'unread_count':
            $userId = $_GET['user_id'] ?? null;
            if (!$userId) {
                handleError('User ID required');
            }
            getUnreadCount($userId);
            break;
        default:
            if (isset($_GET['conversation_id'])) {
                getConversation($_GET['conversation_id']);
            } elseif (isset($_GET['user_id'])) {
                getConversations($_GET['user_id']);
            } else {
                handleError('Missing required parameters');
            }
    }
}

function handlePost() {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $_GET['action'] ?? '';
    
    switch ($action) {
        case 'send_message':
            sendMessage($input);
            break;
        case 'add_participant':
            addParticipant($input);
            break;
        default:
            createConversation($input);
    }
}

function handlePut() {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $_GET['action'] ?? '';
    
    if ($action === 'mark_read') {
        markMessagesAsRead($input);
    } else {
        handleError('Invalid action');
    }
}

function handleDelete() {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $_GET['action'] ?? '';
    
    if ($action === 'remove_participant') {
        removeParticipant($input);
    } else {
        handleError('Invalid action');
    }
}

function getConversations($userId) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            SELECT DISTINCT 
                c.id,
                c.title,
                c.conversation_type,
                c.created_at,
                c.updated_at,
                (
                    SELECT COUNT(*) 
                    FROM messages m 
                    WHERE m.conversation_id = c.id 
                    AND m.sender_id != ? 
                    AND m.id NOT IN (
                        SELECT message_id FROM message_reads WHERE user_id = ?
                    )
                ) as unread_count
            FROM conversations c
            INNER JOIN conversation_participants cp ON c.id = cp.conversation_id
            WHERE cp.user_id = ?
            ORDER BY c.updated_at DESC
        ");
        
        $stmt->execute([$userId, $userId, $userId]);
        $conversations = $stmt->fetchAll();
        
        // Get last message and participants for each conversation
        foreach ($conversations as &$conv) {
            $conv['participants'] = getConversationParticipants($conv['id']);
            $conv['last_message'] = getLastMessage($conv['id']);
        }
        
        jsonResponse($conversations);
    } catch (Exception $e) {
        handleError('Failed to get conversations: ' . $e->getMessage());
    }
}

function getConversation($conversationId) {
    global $pdo;
    
    try {
        // Get conversation details
        $stmt = $pdo->prepare("
            SELECT id, title, conversation_type, created_at, updated_at
            FROM conversations 
            WHERE id = ?
        ");
        $stmt->execute([$conversationId]);
        $conversation = $stmt->fetch();
        
        if (!$conversation) {
            http_response_code(404);
            jsonResponse(['error' => 'Conversation not found']);
        }
        
        // Get participants and messages
        $conversation['participants'] = getConversationParticipants($conversationId);
        $conversation['messages'] = getConversationMessages($conversationId);
        
        jsonResponse($conversation);
    } catch (Exception $e) {
        handleError('Failed to get conversation: ' . $e->getMessage());
    }
}

function getConversationParticipants($conversationId) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        SELECT cp.user_id, u.name, u.discord, u.avatar_url, cp.role
        FROM conversation_participants cp
        INNER JOIN users u ON cp.user_id = u.id
        WHERE cp.conversation_id = ?
    ");
    $stmt->execute([$conversationId]);
    return $stmt->fetchAll();
}

function getConversationMessages($conversationId) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        SELECT id, conversation_id, sender_id, content, message_type, created_at, updated_at
        FROM messages 
        WHERE conversation_id = ?
        ORDER BY created_at ASC
    ");
    $stmt->execute([$conversationId]);
    return $stmt->fetchAll();
}

function getLastMessage($conversationId) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        SELECT content, sender_id, created_at
        FROM messages 
        WHERE conversation_id = ?
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$conversationId]);
    $message = $stmt->fetch();
    
    return $message ?: ['content' => '', 'sender_id' => 0, 'created_at' => null];
}

function createConversation($data) {
    global $pdo;
    
    try {
        $pdo->beginTransaction();
        
        // Create conversation
        $stmt = $pdo->prepare("
            INSERT INTO conversations (title, conversation_type, created_at, updated_at)
            VALUES (?, ?, NOW(), NOW())
        ");
        $stmt->execute([$data['title'] ?? null, $data['conversation_type']]);
        $conversationId = $pdo->lastInsertId();
        
        // Add participants
        if (isset($data['participants']) && is_array($data['participants'])) {
            foreach ($data['participants'] as $participant) {
                $stmt = $pdo->prepare("
                    INSERT INTO conversation_participants (conversation_id, user_id, role)
                    VALUES (?, ?, ?)
                ");
                $stmt->execute([$conversationId, $participant['user_id'], $participant['role'] ?? 'member']);
            }
        }
        
        $pdo->commit();
        
        jsonResponse(['id' => $conversationId, 'message' => 'Conversation created successfully']);
    } catch (Exception $e) {
        $pdo->rollBack();
        handleError('Failed to create conversation: ' . $e->getMessage());
    }
}

function sendMessage($data) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO messages (conversation_id, sender_id, content, message_type, created_at, updated_at)
            VALUES (?, ?, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([
            $data['conversation_id'],
            $data['sender_id'],
            $data['content'],
            $data['message_type'] ?? 'text'
        ]);
        
        $messageId = $pdo->lastInsertId();
        
        // Update conversation timestamp
        $stmt = $pdo->prepare("
            UPDATE conversations 
            SET updated_at = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([$data['conversation_id']]);
        
        jsonResponse(['id' => $messageId, 'message' => 'Message sent successfully']);
    } catch (Exception $e) {
        handleError('Failed to send message: ' . $e->getMessage());
    }
}

function addParticipant($data) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO conversation_participants (conversation_id, user_id, role)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE role = VALUES(role)
        ");
        $stmt->execute([
            $data['conversation_id'],
            $data['user_id'],
            $data['role'] ?? 'member'
        ]);
        
        jsonResponse(['message' => 'Participant added successfully']);
    } catch (Exception $e) {
        handleError('Failed to add participant: ' . $e->getMessage());
    }
}

function removeParticipant($data) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            DELETE FROM conversation_participants 
            WHERE conversation_id = ? AND user_id = ?
        ");
        $stmt->execute([$data['conversation_id'], $data['user_id']]);
        
        jsonResponse(['message' => 'Participant removed successfully']);
    } catch (Exception $e) {
        handleError('Failed to remove participant: ' . $e->getMessage());
    }
}

function markMessagesAsRead($data) {
    global $pdo;
    
    try {
        // Get unread messages for this user in this conversation
        $stmt = $pdo->prepare("
            SELECT m.id 
            FROM messages m
            WHERE m.conversation_id = ? 
            AND m.sender_id != ?
            AND m.id NOT IN (
                SELECT message_id FROM message_reads WHERE user_id = ?
            )
        ");
        $stmt->execute([$data['conversation_id'], $data['user_id'], $data['user_id']]);
        $unreadMessages = $stmt->fetchAll();
        
        // Mark them as read
        if (!empty($unreadMessages)) {
            $stmt = $pdo->prepare("
                INSERT INTO message_reads (message_id, user_id, read_at) 
                VALUES (?, ?, NOW())
            ");
            
            foreach ($unreadMessages as $msg) {
                $stmt->execute([$msg['id'], $data['user_id']]);
            }
        }
        
        jsonResponse(['message' => 'Messages marked as read']);
    } catch (Exception $e) {
        handleError('Failed to mark messages as read: ' . $e->getMessage());
    }
}

function getUnreadCount($userId) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as unread_count
            FROM messages m
            WHERE m.sender_id != ?
            AND m.id NOT IN (
                SELECT message_id FROM message_reads WHERE user_id = ?
            )
            AND m.conversation_id IN (
                SELECT conversation_id FROM conversation_participants WHERE user_id = ?
            )
        ");
        $stmt->execute([$userId, $userId, $userId]);
        $result = $stmt->fetch();
        
        jsonResponse(['unread_count' => (int)$result['unread_count']]);
    } catch (Exception $e) {
        handleError('Failed to get unread count: ' . $e->getMessage());
    }
}
?>
