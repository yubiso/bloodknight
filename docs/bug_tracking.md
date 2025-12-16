# Bug Tracking - BloodKnight

## Active Bugs

### High Priority

| ID | Title | Status | Severity | Description | Steps to Reproduce | Expected Behavior | Actual Behavior | Assigned To | Date Reported |
|----|-------|--------|----------|-------------|-------------------|-------------------|-----------------|-------------|---------------|
| BUG-001 | Database connection error on Find Mission | Fixed | High | Error message appears when accessing Find Mission tab | 1. Login as donor<br>2. Navigate to Find Mission tab | Should load blood drives | Shows "Cannot connect to database" error | Dev Team | 2024-12-20 |
| BUG-002 | Missing location columns in database | Fixed | High | SQL query fails when location columns don't exist | 1. Access Find Mission without running migration | Should gracefully handle missing columns | SQL error crashes page | Dev Team | 2024-12-20 |

### Medium Priority

| ID | Title | Status | Severity | Description | Steps to Reproduce | Expected Behavior | Actual Behavior | Assigned To | Date Reported |
|----|-------|--------|----------|-------------|-------------------|-------------------|-----------------|-------------|---------------|
| BUG-003 | Map markers not showing for legacy drives | Open | Medium | Blood drives created before map feature don't appear on map | 1. Create drive without coordinates<br>2. View on donor map | Should show at hospital location or hide gracefully | No marker appears | Dev Team | 2024-12-20 |

### Low Priority

| ID | Title | Status | Severity | Description | Steps to Reproduce | Expected Behavior | Actual Behavior | Assigned To | Date Reported |
|----|-------|--------|----------|-------------|-------------------|-------------------|-----------------|-------------|---------------|
| BUG-004 | Geocoding rate limiting | Open | Low | Nominatim API has 1 request/second limit | Rapid map interactions | Should debounce requests | May fail on rapid clicks | Dev Team | 2024-12-20 |

## Resolved Bugs

### 2024-12-20
- **BUG-001**: Fixed database connection error by adding column existence checks
- **BUG-002**: Added fallback query for missing location columns

## Bug Categories

### Authentication & Session
- Session persistence issues
- Login/logout errors
- Password reset failures

### Database
- Connection errors
- Query failures
- Missing column errors

### Map & Location
- Geocoding failures
- Marker display issues
- Coordinate validation

### UI/UX
- Responsive design issues
- Mobile view problems
- Loading states

## Bug Reporting Template

```markdown
**Bug ID**: BUG-XXX
**Title**: [Brief description]
**Severity**: [High/Medium/Low]
**Status**: [Open/In Progress/Fixed/Closed]
**Environment**: [Browser, OS, Device]
**Steps to Reproduce**:
1. 
2. 
3. 

**Expected Behavior**:
**Actual Behavior**:
**Screenshots**: [if applicable]
**Console Errors**: [if applicable]
**Date Reported**: YYYY-MM-DD
**Date Fixed**: YYYY-MM-DD
```

## Known Issues

1. **Offline Mode**: Limited functionality when database is unavailable
2. **Browser Compatibility**: Some features may not work in older browsers
3. **Mobile Performance**: Map rendering may be slow on low-end devices

## Testing Checklist

- [ ] Login/Logout functionality
- [ ] Dashboard data loading
- [ ] Blood drive creation with map
- [ ] Appointment booking
- [ ] Blood report viewing
- [ ] Profile updates
- [ ] Map marker display
- [ ] Search functionality
- [ ] Responsive design
- [ ] Error handling

