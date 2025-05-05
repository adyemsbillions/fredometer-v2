AI Chatbot with PHP, JavaScript & Google Gemini API

This project is an AI-powered chatbot built using PHP, JavaScript, and the Google Gemini API, integrated with a MySQL database (fredometer) containing three tables: baselinedata, pindata, and severitydata. The chatbot processes user queries related to demographic, intervention, and severity data, providing precise, conversational responses with Markdown formatting.

🚀 Features

✅ Real-time AI-powered chatbot with Google Gemini API 🤖

✅ Queries three MySQL tables: baselinedata (general demographics), pindata (sector-specific interventions), and severitydata (severity metrics) 📊

✅ Handles duplicate values (e.g., "Fufore", "Health") across tables, noting their presence and context 🔄

✅ PHP backend for database and API integration 🔧

✅ JavaScript (Fetch API) for dynamic message handling ⚡

✅ Clean UI with HTML, CSS, and Markdown-rendered responses 🎨

✅ Robust error handling for database and API failures 🛠️

✅ Intelligent fallbacks for unrelated queries, suggesting relevant table-related questions 💡

📂 Project Structure

├── index.html # Frontend UI
├── script.js # Frontend script for message handling
├── style.css # Stylesheet for chatbot UI
├── chatbot.php # Backend API for database queries and Gemini API integration
├── error.log # Log file for debugging database/API errors
├── README.md # Project documentation

🛠️ Installation & Setup

Clone the Repository:

git clone <repository-url>

Install XAMPP:

Set up XAMPP or another local server environment to run PHP and MySQL.

Ensure the MySQL service is running.

Set Up the Database:

Create the fredometer database and tables (baselinedata, pindata, severitydata) using the provided SQL:

CREATE DATABASE fredometer;
USE fredometer;

-- baselinedata table
CREATE TABLE baselinedata (
Response_Year INT,
State VARCHAR(512),
State_Pcode VARCHAR(512),
LGA VARCHAR(512),
LGA_Pcode VARCHAR(512),
IDP_Girls INT,
IDP_Boys INT,
IDP_Women INT,
IDP_Men INT,
IDP_Elderly_Women INT,
IDP_Elderly_Men INT,
Returnee_Girls INT,
Returnee_Boys INT,
Returnee_Women INT,
Returnee_Men INT,
Returnee_Elderly_Women INT,
Returnee_Elderly_Men INT,
Host_Community_Girls INT,
Host_Community_Boys INT,
Host_Community_Women INT,
Host_Community_Men INT,
Host_Community_Elderly_Women INT,
Host_Community_Elderly_Men INT
);

-- pindata table
CREATE TABLE pindata (
Response_Year INT,
Sector VARCHAR(512),
State VARCHAR(512),
State_Pcode VARCHAR(512),
LGA VARCHAR(512),
LGA_pCode VARCHAR(512),
IDP_Girls INT,
IDP_Boys INT,
IDP_Women INT,
IDP_Men INT,
IDP_Elderly_Women INT,
IDP_Elderly_Men INT,
Returnee_Girls INT,
Returnee_Boys INT,
Returnee_Women INT,
Returnee_Men INT,
Returnee_Elderly_Women INT,
Returnee_Elderly_Men INT,
Host_Community_Girls INT,
Host_Community_Boys INT,
Host_Community_Women INT,
Host_Community_Men INT,
Host_Community_Elderly_Women INT,
Host_Community_Elderly_Men INT
);

-- severitydata table
CREATE TABLE severitydata (
Response_Year INT,
Sector VARCHAR(512),
State VARCHAR(512),
State_Pcode VARCHAR(512),
LGA VARCHAR(512),
LGA_pCode VARCHAR(512),
IDP INT,
Returnees INT,
Host_Community INT,
Final INT
);

-- Sample data
INSERT INTO baselinedata (Response_Year, State, State_Pcode, LGA, LGA_Pcode, IDP_Girls, IDP_Boys, Returnee_Women, Host_Community_Men)
VALUES (2023, 'Adamawa', 'NG001', 'Fufore', 'NGF001', 400, 500, 300, 200),
(2023, 'Borno', 'NG002', 'Maiduguri', 'NGM001', 500, 600, 400, 300);

INSERT INTO pindata (Response_Year, Sector, State, State_Pcode, LGA, LGA_pCode, IDP_Girls, IDP_Boys, Returnee_Women, Host_Community_Men)
VALUES (2023, 'Health', 'Adamawa', 'NG001', 'Fufore', 'NGF001', 200, 250, 150, 100),
(2023, 'Education', 'Borno', 'NG002', 'Maiduguri', 'NGM001', 300, 350, 200, 150);

INSERT INTO severitydata (Response_Year, Sector, State, State_Pcode, LGA, LGA_pCode, IDP, Returnees, Host_Community, Final)
VALUES (2023, 'Health', 'Adamawa', 'NG001', 'Fufore', 'NGF001', 1000, 500, 800, 5),
(2023, 'Education', 'Borno', 'NG002', 'Maiduguri', 'NGM001', 1200, 600, 900, 4);

Set Up Google Gemini API Key:

Obtain your API key from Google AI Studio.

Add it to chatbot.php:

$api_key = "your-api-key";

For security, consider using environment variables (e.g., getenv('GEMINI_API_KEY')) in production.

Start the Server:

Place the project files in XAMPP’s htdocs directory (e.g., htdocs/fredometer-chatbot).

Start Apache and MySQL in XAMPP.

Open index.html in your browser (e.g., http://localhost/fredometer-chatbot/index.html).

🔥 Usage

Open the chatbot UI in your browser.

Type a query related to the database (e.g., “Fufore”, “Health”, “Final score in Fufore Health”, “NG002”).

Click Send or press Enter.

The chatbot queries the fredometer database, fetches relevant data from baselinedata, pindata, and severitydata, and responds via the Gemini API.

For duplicate values (e.g., “Fufore” in multiple tables), the response notes their presence and clarifies table contexts (demographics, interventions, severity).

Unrelated queries receive a friendly fallback suggesting relevant questions.

Example Queries:

“Fufore”: Returns data from all tables, noting “Fufore” appears in baselinedata (demographics), pindata (interventions), and severitydata (severity).

“Health”: Returns pindata and severitydata results, noting “Health” as a sector in both.

“Final score in Fufore Health”: Returns the Final score from severitydata.

🐞 Debugging

If you encounter “Error talking to AI”:

Check error.log in the project directory for MySQL or API errors:

[2025-05-03 19:53:45] DB connection failed: Access denied for user 'root'@'localhost'

Verify Database:

SELECT _ FROM baselinedata WHERE LGA = 'Fufore';
SELECT _ FROM pindata WHERE Sector = 'Health';
SELECT \* FROM severitydata WHERE LGA = 'Fufore';

Test API Key with Postman:

URL: POST https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=your_api_key

Body: {"contents":[{"role":"user","parts":[{"text":"Test"}]}]}

Inspect Network (F12 > Network) for POST request errors to chatbot.php.

Optimize MySQL:

SET GLOBAL max_connections = 500;
SET GLOBAL table_definition_cache = 2000;

📈 Performance Optimization

Add indexes to improve query performance:

CREATE INDEX idx_state ON baselinedata(State);
CREATE INDEX idx_lga ON baselinedata(LGA);
CREATE INDEX idx_state_pcode ON baselinedata(State_Pcode);
CREATE INDEX idx_lga_pcode ON baselinedata(LGA_Pcode);
CREATE INDEX idx_pindata_state ON pindata(State);
CREATE INDEX idx_pindata_lga ON pindata(LGA);
CREATE INDEX idx_pindata_sector ON pindata(Sector);
CREATE INDEX idx_severitydata_state ON severitydata(State);
CREATE INDEX idx_severitydata_lga ON severitydata(LGA);
CREATE INDEX idx_severitydata_sector ON severitydata(Sector);

📌 License

This project is open-source and available under the MIT License.

💡 Like this project? Don't forget to ⭐ star the repo!
