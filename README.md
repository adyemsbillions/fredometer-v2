AI Chatbot with PHP, JavaScript & Google Gemini API
This project is an AI-powered chatbot built using PHP, JavaScript, and the Google Gemini API, integrated with a MySQL database containing three tables for demographic, intervention, and severity data. The chatbot processes user queries related to populations requiring assistance, general demographics, and severity metrics, as well as general questions (e.g., public or internet-related queries), providing precise, conversational responses with Markdown formatting. The UI is responsive for both mobile and desktop devices.
🚀 Features
✅ Real-time AI-powered chatbot using Google Gemini API 🤖✅ Queries a MySQL database with three datasets:  

General demographics (e.g., counts of internally displaced persons, returnees, host community members).  
People in Need Data (sector-specific interventions, e.g., Health, Education, for populations requiring assistance).  
Severity metrics (e.g., final severity scores for interventions).✅ Special handling for "in need" queries, focusing exclusively on People in Need Data (e.g., "Women in need in Borno").✅ Sums numerical queries (e.g., "Number of IDP Women in Fufore") across relevant data, providing detailed breakdowns only when "in detailed" is specified.✅ Avoids multiplication unless explicitly requested (e.g., "Multiply IDP Women by 2").✅ Hides database and table names in responses, presenting data conversationally without technical details.✅ Handles duplicate values (e.g., "Fufore", "Health") across datasets, noting their presence and context (demographics, interventions, severity) 🔄✅ General question handling for non-database queries (e.g., "What is the capital of France?") 💡✅ PHP backend for database queries and Gemini API integration 🔧✅ JavaScript (Fetch API) for dynamic message handling ⚡✅ Responsive UI with HTML, CSS, and Markdown-rendered responses for mobile and desktop 🎨✅ Robust error handling for database and API failures 🛠️✅ Intelligent fallbacks for unrelated queries, suggesting relevant database questions 💡

📂 Project Structure
fredometer-v2/
├── index.html        # Frontend UI with inline JavaScript and CSS
├── chatbot.php       # Backend API for database queries and Gemini API integration
├── README.md         # Project documentation
├── .gitignore        # Ignores logs and sensitive files
├── LICENSE           # MIT License
├── error.log         # Log file for debugging database/API errors

🛠️ Installation & Setup

Clone the Repository  
git clone https://github.com/adyemsbillions/fredometer-v2.git
cd fredometer-v2


Install XAMPP  

Download and install XAMPP or another local server environment to run PHP and MySQL.  
Start the Apache and MySQL services in the XAMPP Control Panel.


Set Up the Database  

Create the database and tables using the following SQL in MySQL (e.g., via phpMyAdmin or MySQL Workbench):  CREATE DATABASE fredometer;
USE fredometer;

-- General demographics table
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

-- People in Need Data table (interventions)
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

-- Severity metrics table
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
VALUES 
    (2023, 'Adamawa', 'NG001', 'Fufore', 'NGF001', 400, 500, 300, 200),
    (2023, 'Borno', 'NG002', 'Maiduguri', 'NGM001', 500, 600, 400, 300);

INSERT INTO pindata (Response_Year, Sector, State, State_Pcode, LGA, LGA_pCode, IDP_Girls, IDP_Boys, Returnee_Women, Host_Community_Men)
VALUES 
    (2023, 'Health', 'Adamawa', 'NG001', 'Fufore', 'NGF001', 200, 250, 150, 100),
    (2023, 'Education', 'Borno', 'NG002', 'Maiduguri', 'NGM001', 300, 350, 200, 150);

INSERT INTO severitydata (Response_Year, Sector, State, State_Pcode, LGA, LGA_pCode, IDP, Returnees, Host_Community, Final)
VALUES 
    (2023, 'Health', 'Adamawa', 'NG001', 'Fufore', 'NGF001', 1000, 500, 800, 5),
    (2023, 'Education', 'Borno', 'NG002', 'Maiduguri', 'NGM001', 1200, 600, 900, 4);




Set Up Google Gemini API Key  

Obtain your API key from Google AI Studio.  
Add it to chatbot.php:  $api_key = "your-api-key";


For security, use environment variables in production:  $api_key = getenv('GEMINI_API_KEY') ?: 'your-api-key';


Set GEMINI_API_KEY in your server environment (e.g., .env file or XAMPP configuration).




Start the Server  

Place the project files in XAMPP’s htdocs directory (e.g., C:\xampp\htdocs\fredometer-v2).  
Start Apache and MySQL in XAMPP.  
Open index.html in your browser: http://localhost/fredometer-v2/index.html.



🔥 Usage

Open the chatbot UI in your browser.  
Type a query:  
Database-related: "Women in need in Borno", "Number of IDP Girls in Fufore", "Final score in Fufore Health", "IDP Girls in Fufore in detailed".  
General: "What is the capital of France?", "Who is Elon Musk?".


Click the Send button or press Enter.

The chatbot:  

Queries the database for relevant demographic, intervention, or severity data.  
Uses the Gemini API to generate a conversational response with Markdown formatting.  
For "in need" queries, focuses exclusively on People in Need Data (e.g., summing women requiring assistance).  
Sums numerical queries (e.g., total IDP Girls) unless "in detailed" is specified, then provides a breakdown.  
Notes duplicate values (e.g., "Fufore" in multiple datasets) and clarifies context (demographics, interventions, severity).  
Answers general questions concisely and suggests relevant database queries if applicable.  
Hides database and table names, presenting data conversationally (e.g., "In Borno, there are 49,728 women requiring assistance" instead of "From pindata").

Example Queries

"Women in need in Borno": Returns "In Borno in 2023, there are 49,728 women requiring assistance across sectors like Health and Education."  
"Number of IDP Girls in Fufore": Returns "In Fufore, there are 150 IDP Girls across years like 2023."  
"IDP Girls in Fufore in detailed": Returns "In Fufore, detailed demographic data:  
Year: 2023, Location: Fufore, IDP Girls: 100, ...  
Year: 2023, Location: Fufore, IDP Girls: 50, ..."


"Final score in Fufore Health": Returns "In Fufore for Health, the total Final metric is 5 in 2023."  
"What is the capital of France?": Returns "The capital of France is Paris."  
"Who is Elon Musk?": Returns "Elon Musk is the CEO of Tesla, SpaceX, and xAI, known for innovations in electric vehicles, space travel, and AI."

🐞 Debugging
If you encounter "Error talking to AI":  

Check error.log in the project directory (e.g., C:\xampp\htdocs\fredometer-v2\error.log):  [2025-05-06 10:00:00] DB connection failed: Access denied for user 'root'@'localhost'


Verify Database:  SELECT * FROM baselinedata WHERE LGA = 'Fufore';
SELECT * FROM pindata WHERE Sector = 'Health';
SELECT * FROM severitydata WHERE LGA = 'Fufore';


Test API Key with Postman:  
URL: POST https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=your_api_key  
Body: {"contents":[{"role":"user","parts":[{"text":"Test"}]}]}


Inspect Network (F12 > Network) for POST request errors to chatbot.php.  
Check MySQL Connection:  
Ensure MySQL is running in XAMPP.  
Verify credentials in chatbot.php:  $servername = "localhost";
$username = "root";
$password = "";
$dbname = "fredometer";





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

Optimize MySQL configuration (in my.cnf or my.ini):  
[mysqld]
max_connections = 500
table_definition_cache = 2000

📌 License
This project is open-source and available under the MIT License.
💡 Like this project? Don't forget to ⭐ star the repo!
