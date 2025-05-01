<?php
// Connect to DB
$servername = "localhost";
$username = "unimaid9_chatbot";
$password = "#adyems123AD";
$dbname = "unimaid9_chatbot";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Handle form submission
$message = "";
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $question = trim($_POST["question"]);
    $answer = trim($_POST["answer"]);

    if (!empty($question) && !empty($answer)) {
        $stmt = $conn->prepare("INSERT INTO faq (question, answer) VALUES (?, ?)");
        $stmt->bind_param("ss", $question, $answer);
        if ($stmt->execute()) {
            $message = "✅ Question and answer saved successfully!";
        } else {
            $message = "❌ Error: " . $stmt->error;
        }
        $stmt->close();
    } else {
        $message = "⚠️ Please fill in both fields.";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>FAQ Manager</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            padding: 40px;
            background: #f2f2f2;
        }
        .container {
            max-width: 600px;
            margin: auto;
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        h2 { text-align: center; }
        label {
            display: block;
            margin-top: 20px;
            font-weight: bold;
        }
        textarea, input[type="text"] {
            width: 100%;
            padding: 12px;
            margin-top: 5px;
            border: 1px solid #ccc;
            border-radius: 6px;
        }
        button {
            margin-top: 25px;
            width: 100%;
            padding: 15px;
            background: #007bff;
            color: white;
            font-size: 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
        }
        button:hover {
            background: #0056b3;
        }
        .message {
            margin-top: 20px;
            text-align: center;
            color: green;
        }
    </style>
</head>
<body>
<div class="container">
    <h2>Add FAQ</h2>
    <?php if ($message): ?>
        <div class="message"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    <form method="POST">
        <label for="question">Question:</label>
        <input type="text" id="question" name="question" required>

        <label for="answer">Answer:</label>
        <textarea id="answer" name="answer" rows="5" required></textarea>

        <button type="submit">Save FAQ</button>
    </form>
</div>
</body>
</html>
