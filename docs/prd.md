# Product Requirements Document - BloodKnight

## 1. Executive Summary

### Product Vision
BloodKnight is a comprehensive blood donation management system that connects donors with hospitals, streamlines appointment booking, and tracks donation history. The platform uses a "tactical mission" theme to gamify the donation experience and encourage regular participation.

### Target Users
- **Primary**: Blood donors (ages 18+)
- **Secondary**: Hospital administrators
- **Tertiary**: Medical staff processing donations

### Key Value Propositions
1. **For Donors**: Easy appointment booking, donation tracking, eligibility management
2. **For Hospitals**: Efficient drive management, donor communication, analytics
3. **For System**: Increased donation rates, better inventory management

## 2. Product Overview

### Problem Statement
- Difficult to find blood donation opportunities
- Lack of centralized donation history
- Manual appointment management
- Poor communication between donors and hospitals
- No visibility into blood supply levels

### Solution
A web-based platform that:
- Maps blood drive locations with interactive maps
- Automates appointment booking and management
- Tracks donation history and eligibility
- Provides real-time supply level visibility
- Enables targeted donor communication

## 3. User Personas

### Persona 1: Regular Donor (Sarah, 28)
- **Goals**: Donate regularly, track impact, find convenient locations
- **Pain Points**: Forgetting eligibility dates, finding drives
- **Needs**: Easy booking, reminders, impact visualization

### Persona 2: Hospital Admin (Dr. Ahmad, 45)
- **Goals**: Organize drives, manage appointments, track inventory
- **Pain Points**: Manual scheduling, poor donor communication
- **Needs**: Automated workflows, analytics, communication tools

### Persona 3: First-Time Donor (John, 22)
- **Goals**: Learn about donation, find first opportunity
- **Pain Points**: Unfamiliar with process, eligibility questions
- **Needs**: Education, guidance, easy first booking

## 4. Features & Requirements

### 4.1 Donor Features

#### 4.1.1 Dashboard
**Priority**: P0 (Must Have)
**Description**: Central hub showing eligibility, stats, appointments
**Requirements**:
- Display eligibility status with countdown timer
- Show donation statistics (count, volume, lives saved)
- Display upcoming appointments
- Show blood type and rank
- Real-time blood supply levels
- Hospital location map

**Acceptance Criteria**:
- [ ] Eligibility calculated correctly (90-day rule)
- [ ] Countdown timer updates in real-time
- [ ] Stats load from database
- [ ] Appointments display with status
- [ ] Map shows all hospitals

#### 4.1.2 Find Mission (Blood Drives)
**Priority**: P0
**Description**: Browse and book blood drive appointments
**Requirements**:
- List upcoming blood drives
- Search by location
- Interactive map with drive locations
- View available time slots
- Book appointment with one click
- See drive details (date, time, location, hospital)

**Acceptance Criteria**:
- [ ] Drives filtered by date (upcoming only)
- [ ] Search works for location/hospital name
- [ ] Map displays drives with coordinates
- [ ] Time slots show availability
- [ ] Booking creates appointment record
- [ ] Email confirmation sent

#### 4.1.3 Appointment Management
**Priority**: P0
**Description**: View and manage appointments
**Requirements**:
- View all appointments (pending, confirmed, completed)
- Cancel appointments with reason
- See appointment details
- Track appointment status

**Acceptance Criteria**:
- [ ] All appointment statuses display correctly
- [ ] Cancellation requires reason selection
- [ ] Status updates reflect in real-time
- [ ] Cannot cancel confirmed/completed appointments

#### 4.1.4 Donation History
**Priority**: P0
**Description**: Complete record of donations
**Requirements**:
- List all past donations
- Show date, location, volume
- Calculate lives saved (volume/150ml)
- Display impact metrics

**Acceptance Criteria**:
- [ ] History loads from database
- [ ] Dates formatted correctly
- [ ] Lives saved calculated accurately
- [ ] Sorted by most recent first

#### 4.1.5 Blood Reports
**Priority**: P1 (Should Have)
**Description**: View medical blood test reports
**Requirements**:
- Display all blood reports
- Tab-based interface for multiple reports
- Show medical metrics (hemoglobin, hematocrit, etc.)
- Filter by date range
- Download/print capability (future)

**Acceptance Criteria**:
- [ ] Reports load from database
- [ ] Tabs work correctly
- [ ] All metrics display properly
- [ ] Date filtering works

#### 4.1.6 Profile Management
**Priority**: P0
**Description**: Update user information
**Requirements**:
- Edit personal information
- Upload profile picture
- Update contact details
- Change blood type (with restrictions)
- View account information

**Acceptance Criteria**:
- [ ] Profile updates save to database
- [ ] Profile picture uploads work
- [ ] Validation prevents invalid data
- [ ] Changes reflect immediately

### 4.2 Admin Features

#### 4.2.1 Command Dashboard
**Priority**: P0
**Description**: Hospital admin control center
**Requirements**:
- Overview statistics
- Pending appointments list
- Active roster
- Recent donations
- Quick actions

**Acceptance Criteria**:
- [ ] Stats calculate correctly
- [ ] Lists update in real-time
- [ ] Filters work properly

#### 4.2.2 Create Mission (Blood Drive)
**Priority**: P0
**Description**: Create new blood drives with map
**Requirements**:
- Interactive map for location selection
- Click to pin location
- Search for places
- Auto-populate address from coordinates
- Set date and time
- Edit location name manually
- Store coordinates and full address

**Acceptance Criteria**:
- [ ] Map loads correctly
- [ ] Click-to-pin works
- [ ] Geocoding fetches address
- [ ] Form validation prevents invalid data
- [ ] Drive saves with all location data

#### 4.2.3 Appointment Approval
**Priority**: P0
**Description**: Review and approve/reject appointments
**Requirements**:
- View pending appointments
- See donor information
- Approve or reject with one click
- Send email notifications
- Update appointment status

**Acceptance Criteria**:
- [ ] Pending list shows correctly
- [ ] Approval sends email
- [ ] Rejection sends email
- [ ] Status updates immediately
- [ ] Timer resets on approval

#### 4.2.4 Process Donation
**Priority**: P0
**Description**: Record completed donations
**Requirements**:
- Select active appointment
- Enter donation volume
- Add notes
- Mark appointment as completed
- Update donor eligibility
- Send confirmation email

**Acceptance Criteria**:
- [ ] Only shows today's appointments
- [ ] Volume validation (max 500ml)
- [ ] Updates appointment status
- [ ] Resets eligibility timer
- [ ] Email sent to donor

#### 4.2.5 Analytics & Reports
**Priority**: P1
**Description**: Data visualization and insights
**Requirements**:
- Blood type distribution chart
- Donation trends over time
- Campaign performance
- Hemoglobin trends
- Location-based statistics
- Export capabilities (future)

**Acceptance Criteria**:
- [ ] Charts render correctly
- [ ] Data accurate
- [ ] Filters work
- [ ] Responsive design

#### 4.2.6 Gmail Alerts
**Priority**: P1
**Description**: Broadcast urgent blood requests
**Requirements**:
- Select target blood type
- Set urgency level
- Compose message
- Test mode for testing
- Send to all matching donors
- Track notification history

**Acceptance Criteria**:
- [ ] Filters donors by blood type
- [ ] Email sends successfully
- [ ] Test mode works
- [ ] Notification saved to database

### 4.3 System Features

#### 4.3.1 Authentication
**Priority**: P0
**Description**: Secure user access
**Requirements**:
- Login for donors and admins
- Password hashing
- Session management
- Password reset functionality
- Remember me option
- Logout functionality

**Acceptance Criteria**:
- [ ] Secure password storage
- [ ] Sessions persist correctly
- [ ] Password reset works
- [ ] Unauthorized access blocked

#### 4.3.2 Map Integration
**Priority**: P0
**Description**: Interactive maps for locations
**Requirements**:
- Leaflet.js integration
- OpenStreetMap tiles
- Custom markers
- Geocoding (Nominatim)
- Search functionality
- Responsive design

**Acceptance Criteria**:
- [ ] Maps load correctly
- [ ] Markers display properly
- [ ] Geocoding works
- [ ] Search finds locations
- [ ] Mobile responsive

## 5. Technical Requirements

### 5.1 Performance
- Page load time: < 3 seconds
- API response time: < 500ms
- Map rendering: < 2 seconds
- Database queries: Optimized with indexes

### 5.2 Security
- Password hashing (bcrypt)
- SQL injection prevention (prepared statements)
- XSS prevention (output escaping)
- Session security (HTTP-only cookies)
- CSRF protection

### 5.3 Compatibility
- **Browsers**: Chrome, Firefox, Safari, Edge (latest 2 versions)
- **Devices**: Desktop, tablet, mobile
- **Screen Sizes**: 320px - 2560px width

### 5.4 Scalability
- Support 1000+ concurrent users
- Handle 10,000+ donor records
- Support 100+ blood drives
- Efficient database queries

## 6. Success Metrics

### Key Performance Indicators (KPIs)
1. **User Engagement**
   - Daily active users
   - Appointment booking rate
   - Profile completion rate

2. **Donation Impact**
   - Total donations processed
   - Blood volume collected
   - Lives saved calculation

3. **System Performance**
   - Uptime percentage
   - Average response time
   - Error rate

4. **User Satisfaction**
   - Task completion rate
   - Time to book appointment
   - Error frequency

## 7. Future Enhancements

### Phase 2 Features
- Mobile app (iOS/Android)
- Push notifications
- Donor rewards program
- Social sharing
- Advanced analytics
- Multi-language support
- Integration with hospital systems

### Phase 3 Features
- AI-powered donor matching
- Predictive inventory management
- Automated scheduling
- Donor community features
- Gamification enhancements

## 8. Constraints & Assumptions

### Constraints
- Limited to Sabah, Malaysia region
- Requires internet connection
- Dependent on external geocoding API
- Email delivery depends on SMTP configuration

### Assumptions
- Users have basic computer literacy
- Hospitals have internet access
- Database is properly maintained
- Email service is configured

## 9. Risks & Mitigation

### Technical Risks
- **Database failure**: Regular backups, monitoring
- **API rate limits**: Debouncing, caching
- **Browser compatibility**: Progressive enhancement

### Business Risks
- **Low adoption**: Marketing, user education
- **Data privacy**: Compliance with regulations
- **Scalability**: Cloud hosting, optimization

## 10. Timeline & Milestones

### Phase 1 (Completed)
- ✅ Core authentication
- ✅ Donor dashboard
- ✅ Admin dashboard
- ✅ Appointment booking
- ✅ Map integration

### Phase 2 (In Progress)
- 🔄 Enhanced analytics
- 🔄 Blood report management
- 🔄 Email notifications
- 🔄 Mobile optimization

### Phase 3 (Planned)
- ⏳ Mobile app
- ⏳ Advanced features
- ⏳ Third-party integrations

