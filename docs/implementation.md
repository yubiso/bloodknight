# Implementation Guide - BloodKnight

## Technology Stack

### Frontend
- **HTML5/CSS3**: Structure and styling
- **JavaScript (Vanilla)**: Client-side logic
- **TailwindCSS**: Utility-first CSS framework
- **Leaflet.js**: Interactive maps (OpenStreetMap)
- **Chart.js**: Data visualization
- **Font Awesome**: Icons
- **Google Fonts**: Typography (Rajdhani, Inter)

### Backend
- **PHP 7.4+**: Server-side logic
- **MySQL/MariaDB**: Database
- **PHPMailer**: Email functionality
- **Session Management**: User authentication

### Database
- **MySQL**: Relational database
- **Tables**: 
  - `donor_user`
  - `hospital`
  - `blood_drive`
  - `appointment`
  - `blood_report`
  - `notification`

## Architecture

### Client-Server Communication
```
Frontend (HTML/JS) 
    ↓ (AJAX/Fetch)
Backend (PHP)
    ↓ (SQL Queries)
Database (MySQL)
```

### Authentication Flow
1. User submits credentials
2. Backend validates against database
3. Session created with user_id/hospital_id
4. Session stored in PHP $_SESSION
5. Frontend checks session on each request

### Map Implementation

#### Admin Dashboard (Create Mission)
- **Library**: Leaflet.js with Control Geocoder
- **Features**:
  - Click-to-pin location
  - Search for places
  - Reverse geocoding (coordinates → address)
  - Auto-populate form fields
- **API**: Nominatim (OpenStreetMap)
- **Rate Limiting**: 1 request/second (debounced)

#### Donor Dashboard (Find Mission)
- **Library**: Leaflet.js
- **Features**:
  - Display blood drive locations
  - Custom red pulsing markers
  - Detailed popups with booking button
  - Auto-fit to show all markers

## Key Features Implementation

### 1. Blood Drive Creation with Map
**File**: `admin_dashboard.html`
**Function**: `initMissionMap()`, `placeMarkerAndGetAddress()`

```javascript
// Map initialization
missionMap = L.map('mission-map').setView([5.9804, 116.0735], 9);

// Click handler
missionMap.on('click', function(e) {
    placeMarkerAndGetAddress(e.latlng);
});

// Reverse geocoding
async function reverseGeocode(latlng) {
    const url = `https://nominatim.openstreetmap.org/reverse?...`;
    // Fetch address from coordinates
}
```

**Backend**: `admin_controller.php` → `create_drive` action
- Checks for location columns existence
- Stores coordinates and address data

### 2. Appointment Booking System
**File**: `dashboard.html`
**Function**: `openBookingModal()`, `handleBookingConfirmation()`

**Flow**:
1. User selects drive
2. Fetch available time slots
3. User selects slot
4. Submit booking request
5. Update appointment status
6. Send confirmation email

### 3. Blood Report Management
**File**: `dashboard.html`, `admin_dashboard.html`
**Features**:
- Tab-based report viewing
- Multiple reports open simultaneously
- Detailed medical metrics display

### 4. Analytics Dashboard
**File**: `admin_dashboard.html`
**Library**: Chart.js
**Charts**:
- Blood type distribution (Doughnut)
- Donation trends (Line)
- Campaign performance (Bar)
- Hemoglobin trends (Line)
- Location distribution (Bar)

## Database Schema

### Key Tables

#### `blood_drive`
```sql
- drive_id (PK)
- hospital_id (FK)
- drive_date
- start_time
- end_time
- location_name
- latitude (NEW)
- longitude (NEW)
- full_address (NEW)
- city (NEW)
- postal_code (NEW)
- country (NEW)
- status
```

#### `appointment`
```sql
- appt_id (PK)
- user_id (FK)
- drive_id (FK)
- selected_time
- status (Pending/Confirmed/Cancelled/Completed)
- source (Online/Walk-in)
- volume_ml
- notes
```

#### `blood_report`
```sql
- report_id (PK)
- user_id (FK)
- appt_id (FK)
- report_date
- hemoglobin
- hematocrit
- platelet_count
- volume_ml
- notes
```

## API Endpoints

### Donor Endpoints (`bloodknight.php`)
- `get_dashboard_data`: User stats and eligibility
- `get_drives`: List upcoming blood drives
- `get_slots`: Available time slots for a drive
- `book_appointment`: Create new appointment
- `cancel_appointment`: Cancel existing appointment
- `get_my_appointments`: User's appointment list
- `get_my_blood_reports`: User's blood reports
- `update_profile`: Update user information

### Admin Endpoints (`admin_controller.php`)
- `get_stats`: Dashboard statistics
- `get_analytics`: Chart data
- `create_drive`: Create new blood drive
- `get_appointments`: Pending appointments
- `confirm_appt`: Approve appointment
- `reject_appt`: Reject appointment
- `process_donation`: Record completed donation
- `send_gmail_alert`: Broadcast urgent alerts
- `get_all_blood_reports`: All blood reports

## Security Measures

1. **Session Management**
   - HTTP-only cookies
   - Session timeout (7 days)
   - CSRF protection

2. **Input Validation**
   - Prepared statements (SQL injection prevention)
   - Email validation
   - XSS prevention (output escaping)

3. **Authentication**
   - Password hashing (bcrypt)
   - Session verification on each request
   - Role-based access control

## Error Handling

### Frontend
- Try-catch blocks for async operations
- User-friendly error messages
- Fallback to localStorage when offline
- Network status detection

### Backend
- Try-catch blocks for database operations
- JSON error responses
- Logging for debugging
- Graceful degradation

## Performance Optimizations

1. **Database**
   - Indexed columns (user_id, drive_id, etc.)
   - Efficient JOIN queries
   - Pagination for large datasets

2. **Frontend**
   - Lazy loading for maps
   - Debounced API calls
   - Cached data in localStorage
   - Optimized image loading

3. **API**
   - Response caching where appropriate
   - Batch operations
   - Rate limiting for external APIs

## Deployment Considerations

### Requirements
- PHP 7.4+
- MySQL 5.7+
- Apache/Nginx web server
- SSL certificate (for production)

### Environment Variables
- Database credentials
- Email SMTP settings
- API keys (if needed)

### Migration Steps
1. Run database migration scripts
2. Update configuration files
3. Set proper file permissions
4. Configure email settings
5. Test all functionality

