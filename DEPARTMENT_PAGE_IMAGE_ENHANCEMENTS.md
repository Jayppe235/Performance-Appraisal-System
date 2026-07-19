# Department View Page Image & Visual Enhancements Prompt

## Overview
Enhance the department view page (department_directory.php and department_management.php) with visual elements and imagery to improve the user experience and make the interface more engaging.

## Current State
- Basic dean profile section with optional profile image
- Text-based faculty listings in table format
- Minimal visual hierarchy
- No department branding/imagery

## Enhancement Categories

### 1. Dean Profile Card Enhancements
**Location**: Department Dean section (department_directory.php around line 85+)

**Add the following visual elements:**
- ✨ **Verification badge** - Blue checkmark icon on bottom-right of profile image
- 🏷️ **Role badge** - "Department Dean" label/tag overlay on image
- 🎨 **Department color frame** - Colored circular/square border around image based on department
- 🟢 **Status indicator** - Green online/offline dot in top-right corner of image
- 🌈 **Department gradient overlay** - Subtle gradient overlay behind the image for branded look
- **Hover effects** - Scale/glow animation on profile image hover

**Implementation Details:**
- Use department ID to determine department color scheme
- Add SVG badges for checkmark and status
- Create CSS classes: `.dean-avatar`, `.dean-avatar-badge`, `.dean-status-indicator`
- Update HTML structure in dean profile section

### 2. Department Banner/Header Image
**Location**: Above current Department Overview section

**Add the following:**
- 📸 **Hero banner image** - Department building or campus photo (600x250px)
- 🏛️ **Department emblem/logo** - Positioned on banner (top-left or center)
- 📝 **Department name overlay** - Large text with gradient fade effect
- 📊 **Quick stat badges** - Faculty count, programs, location badges on banner
- 🎨 **Gradient overlay** - Dark overlay for text readability

**Implementation Details:**
- Create new banner section before stat-grid
- Add database field for department_banner_image_path if needed
- Use CSS: `.department-banner`, `.banner-overlay`, `.banner-stats`
- Fallback to department color gradient if no image available
- Add icons from Font Awesome for quick stats

### 3. Faculty Profile Images in Table
**Location**: Faculty Members table (department_directory.php around line 175+)

**Add the following:**
- 👤 **Profile thumbnails** - Small circular images (40x40px) in Name column
- 🔴 **Status indicators** - Color dots indicating:
  - 🟢 Green = Active
  - 🟡 Yellow = On Leave
  - 🔴 Red = Inactive
  - ⚪ Gray = Archived
- 🏷️ **Role badges** - "Full-time", "Part-time", "Visiting" badges
- 🎯 **Program color coding** - Row background color based on program
- **Hover effects** - Expand to show full profile card with details

**Implementation Details:**
- Query faculty profile_image from database
- Create new Name column structure with avatar + status
- Add CSS: `.faculty-avatar`, `.faculty-status-dot`, `.faculty-role-badge`, `.program-{code}-row`
- Implement hover modal showing full faculty profile

### 4. Visual Statistics Sections
**Location**: Evaluation metrics and progress sections

**Add the following:**
- 📊 **Circular progress charts** - Replace text-based percentages with visual circles
- 📈 **Department comparison chart** - Visual bar chart if viewing multiple departments
- 🥧 **Faculty breakdown pie chart** - By program or position type
- 📅 **Timeline visualization** - Evaluation deadlines and milestones
- 📊 **Mini stat cards** - With icons and color-coded backgrounds

**Implementation Details:**
- Use Chart.js or similar library for charts
- Create stat card components with icons
- Color code by status (green=complete, yellow=in-progress, red=overdue)
- Add CSS: `.stat-chart`, `.progress-circle`, `.mini-stat-card`

### 5. Program Cards/Icons
**Location**: Department overview programs section

**Add the following:**
- 🎓 **Program icons** - Custom or Font Awesome icons per program
- 🏷️ **Color-coded program badges** - Each program has distinct color
- 📊 **Program stat cards** - Faculty count, evaluations status per program
- 📌 **Program headers** - With icon and color bar
- **Visual program grouping** - In faculty table and section headers

**Implementation Details:**
- Create program_color and program_icon fields in database
- Use CSS: `.program-card`, `.program-icon`, `.program-badge-{code}`
- Add program color system (primary, secondary, accent colors)
- Use icons from Font Awesome or custom SVG

### 6. Interactive Visual Elements
**Location**: Throughout department page

**Add the following:**
- 🖱️ **Hover effects** - Faculty names show quick preview/tooltip
- 📂 **Expandable rows** - In faculty table, expand to show full profile card
- 🖼️ **Image carousel** - Department photos gallery
- 🔍 **Quick info cards** - Department directory card (like CITE example in attachment)
- ⚡ **Loading states** - Skeleton screens while fetching data

**Implementation Details:**
- Add onhover event listeners to faculty rows
- Create modal/tooltip components for quick views
- Use CSS transitions and animations
- Add AJAX loading for dynamic data

## Files to Modify

### Backend (PHP)
- `dashboards/department_directory.php` - Add image fields, enhance queries
- `dashboards/department_management.php` - Add visual enhancements
- `api/departments.php` - Add endpoints for department metadata (colors, icons)

### Frontend (CSS)
- `assets/css/admin.css` or main stylesheet
  - New classes for avatars, badges, progress indicators
  - Department color scheme system
  - Responsive design for mobile

### Database (SQL)
- Add columns to departments table:
  - `department_banner_image_path` VARCHAR(255)
  - `department_color_primary` VARCHAR(7) #123456
  - `department_color_secondary` VARCHAR(7) #654321
  - `department_icon` VARCHAR(50)

## Styling Recommendations

### Color System
- **Primary** - Department main brand color
- **Secondary** - Department accent color
- **Status colors**:
  - Active: `#4caf50` (green)
  - Pending: `#ff9800` (orange)
  - Overdue: `#f44336` (red)
  - Inactive: `#9e9e9e` (gray)

### Icons
- Use Font Awesome 6+ for consistency
- Program icons: `fa-graduation-cap`, `fa-book`, `fa-microscope`, etc.
- Status icons: `fa-circle`, `fa-check-circle`, `fa-user-check`
- Badge icons: `fa-badge-check`, `fa-shield`

### Typography
- Badge text: 11-12px, bold
- Section headers: 18-20px, bold
- Stats: 24px bold for numbers, 12px for labels

## Implementation Priority

1. **Phase 1 (High Priority)**
   - Dean profile badge and status indicator
   - Faculty profile thumbnails in table
   - Department banner with quick stats

2. **Phase 2 (Medium Priority)**
   - Program color coding and icons
   - Visual statistics/progress charts
   - Interactive hover effects

3. **Phase 3 (Low Priority)**
   - Image carousel/gallery
   - Advanced chart visualizations
   - Expandable row details

## Testing Checklist

- [ ] Images load correctly with fallback colors
- [ ] Responsive design on mobile (< 768px)
- [ ] Hover effects work smoothly
- [ ] Status indicators update in real-time
- [ ] Color scheme is readable and accessible (WCAG AA)
- [ ] Performance: Page load time < 2s
- [ ] Cross-browser compatibility (Chrome, Firefox, Safari, Edge)
- [ ] No console errors in developer tools

## Success Criteria

✅ Department page has professional, modern appearance
✅ Visual hierarchy clearly shows important information
✅ All images have fallbacks (gradient, color, initials)
✅ User engagement metrics show increased interaction
✅ No performance degradation
✅ Mobile-friendly and accessible

---

## Reference Examples
- CITE College department card (see attachment) with green gradient background and building pattern
- People management card redesign (May 13, 2026) - use same pattern as evaluation cards
- Similar department cards from other systems (Salesforce, HubSpot)
