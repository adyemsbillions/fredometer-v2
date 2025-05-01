<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Unimaid Resources AI</title>
  <style>
    body {
      font-family: 'Segoe UI', sans-serif;
      background: #f5f6fa;
      display: flex;
      justify-content: center;
      align-items: center;
      height: 100vh;
      margin: 0;
    }
    #chat-container {
      width: 100%;
      max-width: 400px;
      height: 95vh;
      background: white;
      border-radius: 20px;
      box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
      display: flex;
      flex-direction: column;
      overflow: hidden;
    }
    .chat-header {
      padding: 15px;
      background: #5438e0;
      color: white;
      font-weight: bold;
      font-size: 18px;
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .chat-header img {
      width: 35px;
      height: 35px;
      border-radius: 50%;
    }
    #chat-box {
      flex: 1;
      padding: 20px;
      overflow-y: auto;
      background: #f5f6fa;
      display: flex;
      flex-direction: column;
      gap: 10px;
    }
    .message {
      padding: 12px 16px;
      border-radius: 18px;
      max-width: 80%;
      line-height: 1.5;
      font-size: 14px;
    }
    .user-message {
      align-self: flex-end;
      background: linear-gradient(to right, #5438e0, #357abd);
      color: white;
      border-radius: 18px 18px 4px 18px;
    }
    .bot-message {
      align-self: flex-start;
      background: #e4e7eb;
      color: #2c2f33;
      border-radius: 18px 18px 18px 4px;
    }
    .input-container {
      display: flex;
      padding: 10px;
      border-top: 1px solid #ccc;
    }
    #user-input {
      flex: 1;
      padding: 10px;
      border: 1px solid #ccc;
      border-radius: 20px;
      outline: none;
      font-size: 14px;
    }
    button {
      margin-left: 10px;
      width: 40px;
      height: 40px;
      border-radius: 50%;
      border: none;
      background: #5438e0;
      color: white;
      font-size: 18px;
      cursor: pointer;
    }

    /* Styling parsed markdown */
    .bot-message strong { font-weight: bold; }
    .bot-message em { font-style: italic; }
    .bot-message u { text-decoration: underline; }
    .bot-message del { text-decoration: line-through; }
    .bot-message code {
      background: #eee;
      font-family: monospace;
      padding: 2px 5px;
      border-radius: 4px;
    }
    .bot-message a {
      color: #357abd;
      text-decoration: underline;
    }
  </style>
</head>
<body>
  <div id="chat-container">
    <div class="chat-header">
      <img src="https://via.placeholder.com/40" alt="AI" />
      <span>Unimaid Resources AI</span>
    </div>
    <div id="chat-box"></div>
    <div class="input-container">
      <input type="text" id="user-input" placeholder="Type your message..." />
      <button onclick="sendMessage()">➤</button>
    </div>
  </div>

  <script>
    function formatMessage(message) {
      return message
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/\*\*(.*?)\*\*/g, "<strong>$1</strong>")
        .replace(/\*(.*?)\*/g, "<em>$1</em>")
        .replace(/__(.*?)__/g, "<u>$1</u>")
        .replace(/~~(.*?)~~/g, "<del>$1</del>")
        .replace(/`(.*?)`/g, "<code>$1</code>")
        .replace(/\[(.*?)\]\((https?:\/\/.*?)\)/g, '<a href="$2" target="_blank">$1</a>');
    }

    async function sendMessage() {
      const input = document.getElementById('user-input');
      const chatBox = document.getElementById('chat-box');
      const userText = input.value.trim();
      if (!userText) return;

      // User message
      const userDiv = document.createElement('div');
      userDiv.classList.add('message', 'user-message');
      userDiv.textContent = userText;
      chatBox.appendChild(userDiv);

      input.value = '';
      chatBox.scrollTop = chatBox.scrollHeight;

      try {
        const res = await fetch('chatbot.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ message: userText })
        });
        const data = await res.json();
        const botText = data.response || '🤖 No response.';

        const botDiv = document.createElement('div');
        botDiv.classList.add('message', 'bot-message');
        botDiv.innerHTML = formatMessage(botText); // ✅ Renders HTML!
        chatBox.appendChild(botDiv);
        chatBox.scrollTop = chatBox.scrollHeight;
      } catch (e) {
        const errDiv = document.createElement('div');
        errDiv.classList.add('message', 'bot-message');
        errDiv.innerHTML = '<em>Error talking to AI</em>';
        chatBox.appendChild(errDiv);
      }
    }
  </script>
</body>
</html>
