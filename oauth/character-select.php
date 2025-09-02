<?php
session_start();
require_once 'oauth-config.php';
require_once '../api/config.php';
require_once '../api/database.php';

// Make sure OAuth character data exists
if (!isset($_SESSION['oauth_user']['user']['character'])) {
    header("Location: https://carspot.site/oauth/login.php");
    exit;
}

$characters = $_SESSION['oauth_user']['user']['character'];
$forumId = $_SESSION['oauth_user']['user']['id'];
$username = $_SESSION['oauth_user']['user']['username'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selectedCharacter = $_POST['selected_character'] ?? '';
    
    if (empty($selectedCharacter)) {
        $error = "Please select a character.";
    } else {
        try {
            $pdo = getConnection();
            
            // Check if user exists in Car Spot database
            $stmt = $pdo->prepare("SELECT * FROM users WHERE forum_id = ?");
            $stmt->execute([$forumId]);
            $existingUser = $stmt->fetch();
            
            if (!$existingUser) {
                // Create new user
                $insert = $pdo->prepare("INSERT INTO users (forum_id, username, discord_id, email, created_at) VALUES (?, ?, ?, ?, NOW())");
                $insert->execute([$forumId, $username, $_SESSION['oauth_user']['user']['discord'] ?? null, $_SESSION['oauth_user']['user']['email'] ?? null]);
                $userId = $pdo->lastInsertId();
            } else {
                $userId = $existingUser['id'];
            }
            
            // Set session data
            $_SESSION['user_id'] = $userId;
            $_SESSION['username'] = $username;
            $_SESSION['forum_id'] = $forumId;
            $_SESSION['selected_character'] = $selectedCharacter;
            
            // Check if profile is complete
            if ($existingUser && !empty($existingUser['phone_number']) && !empty($existingUser['routing_number'])) {
                header("Location: https://carspot.site/");
            } else {
                header("Location: https://carspot.site/complete-profile");
            }
            exit;
            
        } catch (Exception $e) {
            error_log("Character selection error: " . $e->getMessage());
            $error = "An error occurred. Please try again.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Select Character - Car Spot</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
            font-family: 'Segoe UI', sans-serif;
            min-height: 100vh;
            padding: 20px;
        }
        
        .character-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .character-card {
            background: rgba(255, 255, 255, 0.1);
            border: 2px solid rgba(255, 255, 255, 0.2);
            border-radius: 15px;
            padding: 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .character-card:hover {
            background: rgba(255, 255, 255, 0.2);
            border-color: rgba(255, 255, 255, 0.4);
            transform: translateY(-5px);
        }
        
        .character-card.selected {
            border-color: #28a745;
            background: rgba(40, 167, 69, 0.2);
        }
        
        .character-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: #6c757d;
            margin: 0 auto 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            color: white;
        }
        
        .btn-continue {
            background: linear-gradient(45deg, #28a745, #20c997);
            border: none;
            padding: 12px 30px;
            border-radius: 25px;
            color: white;
            font-weight: bold;
            font-size: 1.1rem;
            transition: all 0.3s ease;
        }
        
        .btn-continue:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(40, 167, 69, 0.4);
        }
        
        .btn-continue:disabled {
            background: #6c757d;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="text-center mb-5">
            <h1 class="display-4 mb-3">Welcome to Car Spot</h1>
            <p class="lead">Select the character you want to use</p>
            <p class="text-muted">You have <?php echo count($characters); ?> character(s) available</p>
        </div>
        
        <?php if (isset($error)): ?>
            <div class="alert alert-danger text-center" role="alert">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" id="characterForm">
            <div class="character-grid">
                <?php foreach ($characters as $character): ?>
                    <div class="character-card" onclick="selectCharacter('<?php echo htmlspecialchars($character['firstname'] . ' ' . $character['lastname']); ?>')">
                        <div class="character-avatar">
                            <?php echo strtoupper(substr($character['firstname'], 0, 1) . substr($character['lastname'], 0, 1)); ?>
                        </div>
                        <h5><?php echo htmlspecialchars($character['firstname'] . ' ' . $character['lastname']); ?></h5>
                        <p class="text-muted mb-0">Character ID: <?php echo $character['id']; ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <input type="hidden" name="selected_character" id="selectedCharacter" value="">
            
            <div class="text-center mt-4">
                <button type="submit" class="btn btn-continue" id="continueBtn" disabled>
                    Continue with Selected Character
                </button>
            </div>
        </form>
    </div>
    
    <script>
        function selectCharacter(characterName) {
            // Remove previous selection
            document.querySelectorAll('.character-card').forEach(card => {
                card.classList.remove('selected');
            });
            
            // Add selection to clicked card
            event.currentTarget.classList.add('selected');
            
            // Set hidden input value
            document.getElementById('selectedCharacter').value = characterName;
            
            // Enable continue button
            document.getElementById('continueBtn').disabled = false;
        }
    </script>
</body>
</html>
