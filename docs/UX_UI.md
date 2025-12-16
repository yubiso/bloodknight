# UX/UI Design Guidelines - BloodKnight

## Design Philosophy

**Theme**: Tactical/Medical Command Center
**Motto**: "Knights Saving Lives"
**Aesthetic**: Modern, clean, professional with military-inspired elements

## Color Palette

### Primary Colors
- **Red (Blood)**: `#dc2626` (rgb(220, 38, 38))
  - Primary actions, blood-related elements
  - Buttons, accents, markers
- **Slate (Neutral)**: `#1e293b` to `#f8fafc`
  - Backgrounds, text, borders
  - Gray scale for hierarchy

### Accent Colors
- **Green**: `#10b981` (Success, eligible status)
- **Orange**: `#f97316` (Warning, cooldown period)
- **Yellow**: `#f59e0b` (Alerts, pending status)
- **Blue**: `#3b82f6` (Information, links)

## Typography

### Font Families
- **Headings**: `Rajdhani` (Tactical font)
  - Weight: 500, 600, 700
  - Usage: Titles, section headers, tactical elements
- **Body**: `Inter`
  - Weight: 400, 600
  - Usage: Content, descriptions, forms

### Font Sizes
- **Hero/Page Title**: `text-3xl` (30px)
- **Section Headers**: `text-2xl` (24px)
- **Card Titles**: `text-xl` (20px)
- **Body Text**: `text-base` (16px)
- **Small Text**: `text-sm` (14px)
- **Tiny Text**: `text-xs` (12px)

## Component Design

### Buttons

#### Primary Button
```html
<button class="bg-slate-900 hover:bg-slate-800 text-white py-3 px-6 rounded-xl font-bold uppercase tracking-wider transition-all shadow-lg">
```

#### Action Button (Red)
```html
<button class="bg-red-600 hover:bg-red-700 text-white py-3 px-6 rounded-xl font-bold transition-all shadow-lg shadow-red-200">
```

#### Secondary Button
```html
<button class="bg-slate-200 hover:bg-slate-300 text-slate-700 py-2 px-4 rounded-lg transition-colors">
```

### Cards

#### Standard Card
```html
<div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6">
```

#### Stat Card
```html
<div class="bg-white p-3 rounded-xl border border-slate-200 shadow-sm">
```

### Forms

#### Input Field
```html
<input class="w-full bg-slate-50 border border-slate-200 rounded-lg p-3 text-slate-900 outline-none focus:border-red-500 transition-all">
```

#### Select Dropdown
```html
<select class="w-full bg-slate-50 border border-slate-200 rounded-lg p-3 outline-none focus:border-red-500">
```

## Layout Patterns

### Dashboard Layout
- **Sidebar Navigation**: Fixed left sidebar (desktop)
- **Main Content**: Scrollable right area
- **Mobile**: Collapsible menu, full-width content

### Grid Systems
- **Stats Grid**: `grid-cols-2 md:grid-cols-3 lg:grid-cols-5`
- **Card Grid**: `grid-cols-1 md:grid-cols-2 lg:grid-cols-3`
- **Form Grid**: `grid-cols-1 md:grid-cols-2`

## Interactive Elements

### Map Markers
- **Blood Drive**: Red pulsing circle with calendar icon
- **Hospital**: Red circle with hospital icon
- **Custom Icons**: DivIcon with HTML/CSS

### Loading States
- **Spinner**: `<i class="fas fa-spinner fa-spin"></i>`
- **Skeleton**: Gray placeholder boxes
- **Progress**: Circular progress indicators

### Status Indicators
- **Eligible**: Green checkmark, green text
- **Recovering**: Orange hourglass, orange text
- **Pending**: Yellow clock icon
- **Confirmed**: Green calendar check
- **Rejected**: Red X icon

## User Flows

### Donor Journey
1. **Landing** → Login/Register
2. **Dashboard** → View eligibility, stats
3. **Find Mission** → Browse drives, view map
4. **Book Appointment** → Select time slot
5. **Confirmation** → Receive email, see in dashboard
6. **Donation Day** → Complete donation
7. **View Report** → See blood test results

### Admin Journey
1. **Login** → Hospital credentials
2. **Overview** → Check statistics
3. **Create Mission** → Pin location on map, set date/time
4. **Pending Approvals** → Review and approve/reject
5. **Process Donation** → Record completed donations
6. **Analytics** → View trends and reports

## Responsive Design

### Breakpoints
- **Mobile**: < 768px (md)
- **Tablet**: 768px - 1024px
- **Desktop**: > 1024px (lg)

### Mobile Adaptations
- Collapsible sidebar
- Stacked grid layouts
- Full-width forms
- Touch-friendly buttons (min 44px)
- Simplified navigation

## Accessibility

### WCAG Compliance
- **Color Contrast**: Minimum 4.5:1 for text
- **Keyboard Navigation**: Tab order, focus states
- **Screen Readers**: ARIA labels, semantic HTML
- **Alt Text**: Images have descriptive alt attributes

### Focus States
```css
focus:border-red-500
focus:ring-2 focus:ring-red-100
outline-none
```

## Animation & Transitions

### Transitions
- **Hover**: `transition-colors`, `transition-all`
- **Duration**: Default 200-300ms
- **Easing**: Default ease-in-out

### Animations
- **Pulse**: Map markers (2s infinite)
- **Drop**: Blood drop icon (3s infinite)
- **Blink**: Typing cursor (1s infinite)
- **Spin**: Loading spinners

## Icon Usage

### Font Awesome Icons
- **Navigation**: `fa-th-large`, `fa-map-marker-alt`, `fa-history`
- **Status**: `fa-check-circle`, `fa-clock`, `fa-times-circle`
- **Actions**: `fa-calendar-check`, `fa-user-cog`, `fa-sign-out-alt`
- **Medical**: `fa-tint`, `fa-heartbeat`, `fa-file-medical`
- **System**: `fa-spinner`, `fa-exclamation-triangle`, `fa-info-circle`

## Error States

### Error Messages
- **Background**: `bg-red-50`
- **Border**: `border-red-200`
- **Text**: `text-red-700`
- **Icon**: Red exclamation triangle

### Success Messages
- **Background**: `bg-green-50`
- **Border**: `border-green-200`
- **Text**: `text-green-700`
- **Icon**: Green checkmark

### Warning Messages
- **Background**: `bg-yellow-50`
- **Border**: `border-yellow-200`
- **Text**: `text-yellow-800`
- **Icon**: Yellow warning triangle

## Map Design

### Map Container
- **Height**: 450-500px
- **Border Radius**: 12px
- **Border**: 2px solid slate-200
- **Shadow**: Subtle shadow for depth

### Map Controls
- **Zoom Controls**: Default Leaflet style
- **Search Box**: Custom styled geocoder
- **Attribution**: Bottom-right corner

### Marker Design
- **Size**: 40-50px diameter
- **Color**: Red (#dc2626)
- **Border**: White 4px
- **Shadow**: Red glow effect
- **Animation**: Pulse on hover

## Data Visualization

### Charts (Chart.js)
- **Colors**: Red gradient for primary data
- **Background**: White cards
- **Legends**: Right-aligned, compact
- **Tooltips**: Enabled, formatted

### Progress Indicators
- **Circular**: SVG with stroke-dashoffset
- **Linear**: Gradient bars
- **Colors**: Green (complete), Orange (in progress)

## Best Practices

1. **Consistency**: Use design system components
2. **Feedback**: Always show loading/error states
3. **Clarity**: Clear labels and instructions
4. **Efficiency**: Minimize clicks to complete tasks
5. **Forgiveness**: Allow undo/cancel actions
6. **Progressive Disclosure**: Show details on demand

