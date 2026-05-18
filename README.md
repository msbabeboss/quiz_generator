# 🧠 QuizGen — Smart Real-Time Quiz Generator

A PHP-based quiz platform for teachers and students with AI-powered question generation, real-time live submissions, classroom management, and flashcard study mode.

---

## ✨ Features

| Feature | Description |
|---|---|
| **AI Quiz Generator** | Upload a PDF, DOCX, PPTX, or TXT — AI auto-detects question types and generates MCQ / True-False questions |
| **Manual Quiz Builder** | Create quizzes with 5 question types: MCQ, True/False, Identification, Fill in the Blank, Enumeration |
| **Live Submissions** | Real-time leaderboard and submission feed via Pusher WebSockets |
| **Classrooms** | Room-code based enrollment — students join a room and access all assigned quizzes |
| **Access Codes** | Per-section exam codes — share a code with a specific class group |
| **Flashcards** | Students can study quiz questions in flashcard mode |
| **Results & Analytics** | Per-quiz leaderboard, per-student answer breakdown |
| **Role System** | Teacher and Student roles with separate dashboards |
| **Dark UI** | Fully dark-themed responsive interface |

---

## 🛠 Tech Stack

- **Backend:** PHP 8.1+, PDO (MySQL)
- **Frontend:** Bootstrap 5.3, vanilla JS
- **Real-time:** Pusher (WebSockets)
- **AI:** Groq API (Llama 3) — free tier available
- **Email:** PHPMailer + Gmail SMTP
- **File parsing:** PHPWord, smalot/pdfparser
- **Server:** Apache (XAMPP recommended for local dev)

---

## ⚙️ Requirements

- PHP 8.1 or higher
- MySQL 5.7+ or MariaDB 10.4+
- Apache with `mod_rewrite` enabled
- Composer
- A free [Pusher](https://pusher.com) account
- A free [Groq](https://console.groq.com) API key (for AI generation)

---

## 🚀 Installation

### 1. Clone the repository

```bash
git clone https://github.com/YOUR_USERNAME/quiz-generator.git
cd quiz-generator
```

### 2. Install PHP dependencies

```bash
composer install
```

### 3. Set up the database

1. Open **phpMyAdmin** (or any MySQL client)
2. Create a database named `quiz_db`
3. Import `database.sql`:
   ```bash
   mysql -u root -p quiz_db < database.sql
   ```

### 4. Configure environment

```bash
cp .env.example .env
```

Edit `.env` and fill in your values:

```env
DB_HOST=localhost
DB_PORT=3306
DB_NAME=quiz_db
DB_USER=root
DB_PASS=your_password

PUSHER_KEY=...
PUSHER_SECRET=...
PUSHER_APP_ID=...
PUSHER_CLUSTER=ap1

GROQ_API_KEY=...

MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your@gmail.com
MAIL_PASSWORD=your_app_password
```

### 5. Set up Apache virtual host (XAMPP)

Place the project in `C:\xampp\htdocs\quiz_generator\` and access it at:

```
http://localhost/quiz_generator/
```

Make sure `mod_rewrite` is enabled in `httpd.conf`:
```apache
LoadModule rewrite_module modules/mod_rewrite.so
```

And `AllowOverride All` is set for your htdocs directory.

### 6. Register accounts

- Go to `/register.php` to create a **Student** account
- Go to `/teacher/register.php` to create a **Teacher** account  
  (requires `TEACHER_INVITE_CODE` from your `.env`)

---

## 📁 Project Structure

```
quiz_generator/
├── api/                    # AJAX endpoints (submit answer, auto-submit, etc.)
├── assets/
│   ├── css/                # Stylesheets
│   ├── js/                 # Client-side scripts (timer, realtime, darkmode)
│   └── sounds/             # Notification sounds
├── config/                 # Core services (DB, Auth, GeminiService, QuizManager…)
├── includes/               # Shared partials (header, teacher-nav)
├── student/                # Student-facing pages
├── teacher/                # Teacher-facing pages
├── uploads/lessons/        # Temporary file uploads (gitignored)
├── .env.example            # Environment variable template
├── .htaccess               # Apache security rules
├── composer.json           # PHP dependencies
├── database.sql            # Full DB schema with triggers and stored procedures
└── index.php               # Entry point / landing page
```

---

## 🔑 Environment Variables

| Variable | Description |
|---|---|
| `DB_HOST` | MySQL host |
| `DB_PORT` | MySQL port (default `3306`) |
| `DB_NAME` | Database name |
| `DB_USER` | Database user |
| `DB_PASS` | Database password |
| `PUSHER_KEY` | Pusher app key |
| `PUSHER_SECRET` | Pusher app secret |
| `PUSHER_APP_ID` | Pusher app ID |
| `PUSHER_CLUSTER` | Pusher cluster (e.g. `ap1`) |
| `TEACHER_INVITE_CODE` | Code required to register as a teacher |
| `ADMIN_INVITE_CODE` | Code required to register as an admin |
| `MAIL_HOST` | SMTP host |
| `MAIL_PORT` | SMTP port |
| `MAIL_USERNAME` | SMTP username |
| `MAIL_PASSWORD` | SMTP password (use Gmail App Password) |
| `MAIL_FROM` | From email address |
| `MAIL_FROM_NAME` | From display name |
| `GROQ_API_KEY` | Groq API key for AI question generation |
| `GEMINI_API_KEY` | Google Gemini API key (optional) |
| `OPENAI_API_KEY` | OpenAI API key (optional) |

---

## 🤖 AI Question Generation

The AI generator supports two modes:

- **Auto-detect** — Upload any file; the AI reads it and decides the best question types. If your PDF already contains MCQ or True/False questions, it extracts them directly.
- **Manual** — You specify exactly how many MCQ and True/False questions to generate.

Supported file formats: `.pdf`, `.docx`, `.pptx`, `.xlsx`, `.txt` (up to 100 MB)

The default AI model is **Llama 3.1** via [Groq](https://console.groq.com) (free tier, no credit card required).

---

## 🔒 Security Notes

- `.env` is blocked by `.htaccess` and `.gitignore` — never commit it
- All DB queries use PDO prepared statements
- CSRF tokens on every form
- Passwords hashed with bcrypt (cost 12)
- Session fixation prevention on login
- `config/` and `vendor/` directories are blocked from direct web access

---

## 📄 License

MIT License — free to use, modify, and distribute.
