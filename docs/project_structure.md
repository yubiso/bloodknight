# Project Structure - BloodKnight

## Directory Structure

```
bloodknight/
├── assets/                          # Static assets
│   ├── mission-images/              # Mission card images
│   ├── *.png                        # Icons and graphics
│   └── chatbot-icon.png             # AI assistant icon
│
├── images/                          # Image assets
│   └── *.jpg, *.png, *.webp        # Various images
│
├── uploads/                         # User-uploaded files
│   └── profile_*.jpg               # Profile pictures
│
├── PHPMailer/                       # Email library
│   ├── src/                         # PHPMailer source code
│   ├── language/                    # Translation files
│   └── composer.json                # Dependencies
│
├── Core Application Files
│   ├── index.html                   # Landing/Login page
│   ├── dashboard.html               # Donor dashboard
│   ├── admin_dashboard.html         # Hospital admin dashboard
│   ├── bloodknight.php              # Donor backend API
│   ├── admin_controller.php         # Admin backend API
│   └── db_connect.php               # Database connection
│
├── Authentication Pages
│   ├── forgot_password.html         # Password reset request
│   ├── reset_password.html          # Password reset form
│   ├── admin_forgot_password.html   # Admin password reset
│   └── admin_reset_password.html    # Admin reset form
│
├── Database Files
│   ├── bloodknight_db.sql           # Database schema
│   ├── bloodknight_db_data.sql      # Sample data
│   ├── migrate_add_location_columns.sql  # Location migration
│   └── bloodknight_db_and_data.zip # Complete database backup
│
├── Utility Scripts
│   ├── setup.php                    # Initial setup
│   ├── test_connection.php           # DB connection test
│   ├── test_db_connection.php       # DB test endpoint
│   ├── generate_admin_hash.php       # Password hash generator
│   └── update_db.php                # Database updater
│
└── Legacy/Test Files
    ├── admin.php                    # Old admin page
    ├── bk_admin_controller.php      # Old admin controller
    ├── test_admin_login.php         # Admin login test
    └── test_bk_admin.php            # Admin test
```

## File Descriptions

### Frontend Files

#### `index.html`
- **Purpose**: Landing page and login interface
- **Features**: User/hospital login, registration
- **Dependencies**: TailwindCSS, Font Awesome

#### `dashboard.html`
- **Purpose**: Main donor interface
- **Sections**:
  - Overview (eligibility, stats, appointments)
  - Find Mission (blood drives, map)
  - History (donation records)
  - Blood Reports (medical reports)
  - Profile (user settings)
- **Key Features**:
  - Interactive map with Leaflet
  - Appointment booking
  - AI chatbot integration
  - Real-time supply levels
- **Dependencies**: Leaflet, Chart.js, TailwindCSS

#### `admin_dashboard.html`
- **Purpose**: Hospital admin command center
- **Sections**:
  - Overview (statistics, roster)
  - Mission Reports (analytics)
  - Create Mission (map-based drive creation)
  - Pending Approvals
  - Active Appointments
  - Walk-in Booking
  - Process Donation
  - Gmail Alerts
  - Blood Reports
- **Key Features**:
  - Interactive map for location pinning
  - Analytics dashboard with charts
  - Email broadcasting
- **Dependencies**: Leaflet, Chart.js, TailwindCSS

### Backend Files

#### `bloodknight.php`
- **Purpose**: Donor-facing API endpoints
- **Key Actions**:
  - `login`: User authentication
  - `get_dashboard_data`: User stats
  - `get_drives`: Blood drive listings
  - `book_appointment`: Create appointment
  - `cancel_appointment`: Cancel appointment
  - `get_my_blood_reports`: User reports
  - `update_profile`: Profile updates
- **Security**: Session-based authentication

#### `admin_controller.php`
- **Purpose**: Admin-facing API endpoints
- **Key Actions**:
  - `get_stats`: Dashboard statistics
  - `get_analytics`: Chart data
  - `create_drive`: Create blood drive
  - `confirm_appt`: Approve appointment
  - `process_donation`: Record donation
  - `send_gmail_alert`: Broadcast alerts
- **Security**: Hospital session authentication

#### `db_connect.php`
- **Purpose**: Database connection configuration
- **Returns**: `$conn` MySQL connection object
- **Credentials**: Configurable (default: localhost/root)

### Database Files

#### `bloodknight_db.sql`
- **Purpose**: Database schema definition
- **Contains**: Table structures, indexes, constraints

#### `migrate_add_location_columns.sql`
- **Purpose**: Add location fields to blood_drive table
- **Columns Added**:
  - latitude, longitude
  - full_address, city, postal_code, country

### Configuration Files

#### `.gitignore` (if exists)
- Excludes sensitive files
- Database credentials
- Upload directories
- Environment-specific configs

## Module Organization

### Authentication Module
**Files**: `index.html`, `forgot_password.html`, `reset_password.html`
**Backend**: `bloodknight.php` (login, logout actions)
**Features**: Login, registration, password reset

### Dashboard Module
**Files**: `dashboard.html`
**Backend**: `bloodknight.php`
**Features**: Stats, appointments, eligibility tracking

### Mission/Drive Module
**Files**: `dashboard.html`, `admin_dashboard.html`
**Backend**: `bloodknight.php`, `admin_controller.php`
**Features**: Drive listing, booking, creation with map

### Reports Module
**Files**: `dashboard.html`, `admin_dashboard.html`
**Backend**: `bloodknight.php`, `admin_controller.php`
**Features**: Blood report viewing, creation, analytics

### Map Module
**Files**: `dashboard.html`, `admin_dashboard.html`
**Libraries**: Leaflet.js, Leaflet Control Geocoder
**Features**: Location pinning, drive markers, geocoding

### Analytics Module
**Files**: `admin_dashboard.html`
**Libraries**: Chart.js
**Features**: Data visualization, trends, statistics

## Asset Organization

### Images
- **Profile Pictures**: `uploads/profile_*.jpg`
- **Mission Images**: `assets/mission-images/`
- **Icons**: `assets/*.png`, `images/*.png`

### Styles
- **Inline Styles**: Component-specific in HTML files
- **TailwindCSS**: CDN-based utility classes
- **Custom CSS**: Embedded in `<style>` tags

### Scripts
- **External Libraries**: CDN links
- **Custom JavaScript**: Embedded in `<script>` tags
- **No build process**: Direct browser execution

## Dependencies

### External CDN Resources
- TailwindCSS (styling)
- Font Awesome (icons)
- Google Fonts (typography)
- Leaflet.js (maps)
- Chart.js (charts)

### Local Dependencies
- PHPMailer (email)
- MySQL/MariaDB (database)

## File Naming Conventions

- **HTML Files**: lowercase with underscores (`admin_dashboard.html`)
- **PHP Files**: lowercase with underscores (`admin_controller.php`)
- **Database Files**: lowercase with underscores (`bloodknight_db.sql`)
- **Image Files**: lowercase with hyphens or underscores
- **JavaScript Functions**: camelCase (`loadMissionMap()`)
- **CSS Classes**: Tailwind utility classes

## Code Organization Patterns

### Frontend
- **Single Page Applications**: Multiple sections in one HTML file
- **Tab-based Navigation**: Show/hide sections
- **Event-driven**: onClick, onsubmit handlers
- **Async/Await**: Modern JavaScript for API calls

### Backend
- **Action-based Routing**: Single entry point with action parameter
- **JSON Responses**: Consistent API format
- **Session Management**: PHP sessions for authentication
- **Error Handling**: Try-catch with JSON error responses

