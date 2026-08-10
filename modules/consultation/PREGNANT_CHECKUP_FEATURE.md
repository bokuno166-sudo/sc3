# Pregnant Patient Check-up Type Selection Feature

## Overview

This feature allows hospital staff to streamline the assessment process for pregnant patients by enabling them to select the appropriate type of check-up when the pregnant patient arrives at the queue for assessment. This ensures pregnant patients receive specialized care according to their specific needs.

## Features

### 1. **Pregnant Patient Identification**
- Pregnant patients are automatically highlighted in both the Reception Queue and Triage Assessment views
- Visual indicators include:
  - Orange/Yellow badge with "PREGNANT" label
  - Special row highlighting with orange left border
  - Display of pregnancy weeks and Expected Due Date (EDD)

### 2. **Check-up Type Selection**

When a pregnant patient arrives for assessment, staff can select one of two check-up types:

#### **A. General Consultation (Basic Check)**
- Routine assessment of vital signs
- General health status evaluation
- Basic complaint evaluation
- Follow standard triage procedures
- Includes: Blood Pressure, Heart Rate, Temperature, Pain Assessment, General Symptoms Review
- Route: → Standard Triage Assessment (triage-assess.php) → Consultation

#### **B. Maternity Check-up (Prenatal)**
- Specialized prenatal assessment
- Focus on pregnancy-related health status
- Fetal development monitoring
- Includes: Fetal Heartbeat Monitoring, Fundal Height Measurement, Pregnancy-Specific Assessment
- Route: → Prenatal Check-up Form (maternity/checkup-add.php) → Consultation

## How to Use

### From Reception Queue

1. Go to **Reception → Patient Queue**
2. Look for patients with the **PREGNANT** badge in the "Waiting" section
3. Click **"Select Check-up Type"** button (orange button with heartbeat icon)
4. A modal will appear showing patient information
5. Choose the appropriate check-up type:
   - Click the "General Consultation" card for basic assessment
   - Click the "Maternity Check-up" card for prenatal assessment
6. Click **"Proceed"** button
7. Staff will be directed to the appropriate assessment form

### From Triage Assessment

1. Go to **Triage → Patient Assessment**
2. Pregnant patients appear at the top of the waiting list with **PREGNANT** badge
3. Click **"Select Type"** button for pregnant patients without a selected check-up type
4. Follow the same modal selection process as above
5. After selection:
   - For **General Consultation**: Opens standard triage assessment form
   - For **Maternity Check-up**: Opens prenatal check-up form

### After Check-up Type Selection

**For General Consultation:**
- Staff completes standard triage vital signs assessment
- Patient proceeds to consultation with a doctor
- Normal consultation workflow applies

**For Maternity Check-up:**
- Staff fills in specialized prenatal check-up form including:
  - Gestation age (weeks)
  - Weight
  - Blood pressure
  - Fetal heart rate
  - Fundal height
  - Fetal presentation (cephalic, breech, transverse, oblique)
  - Prescribed vitamins
  - Next appointment date
  - Additional notes
- Patient information is saved with maternity checkup record
- Visit status is updated to "in-consultation"
- Staff returns to triage list after saving

## Database Changes

The system automatically creates/updates the following:

### New Column in `patient_visits` Table
- `checkup_type` (VARCHAR 50, NULL) - Stores the selected check-up type ('consultation' or 'maternity')

### Existing Maternity Table
- `maternity_checkups` table stores detailed prenatal check-up records

## Key Workflows

### Workflow 1: Pregnant Patient → Consultation
```
Queue (Select "General Consultation")
  ↓
Triage Assessment Form
  ↓
Standard Vital Signs Entry
  ↓
Doctor Consultation
```

### Workflow 2: Pregnant Patient → Maternity Check-up
```
Queue (Select "Maternity Check-up")
  ↓
Prenatal Check-up Form
  ↓
Specialized Pregnancy-Related Assessment
  ↓
Doctor Consultation (if needed)
```

## User Roles

The following roles can access this feature:
- **Admin**
- **Staff**
- **Reception**
- **Nurse**
- **Doctor**

## Technical Details

### Files Modified/Created

1. **triage-checkup-type.php** (NEW)
   - AJAX endpoint that processes check-up type selection
   - Updates visit record with selected type
   - Returns appropriate redirect URL

2. **triage.php** (MODIFIED)
   - Displays pregnant patient indicator
   - Shows "Select Type" button for pregnant patients
   - Includes checkup type modal and JavaScript

3. **checkup-add.php** (MODIFIED)
   - Now accepts `visit_id` parameter from triage workflow
   - Updates visit status upon checkup entry
   - Redirects back to triage list after saving

4. **queue.php** (MODIFIED)
   - Shows pregnant patient indicator
   - Displays "Select Check-up Type" button
   - Includes checkup type modal and JavaScript

### JavaScript Functionality

- Modal selection interface for choosing check-up type
- AJAX submission to process selection
- Automatic redirect to appropriate assessment form
- Visual feedback during processing

## Benefits

1. **Improved Workflow**: Streamlined process for handling pregnant patients
2. **Specialized Care**: Pregnant patients get appropriate assessment based on needs
3. **Better Record Keeping**: Visit type is tracked for audit and reporting
4. **Reduced Manual Errors**: Guided process ensures correct routing
5. **Enhanced User Experience**: Clear, intuitive interface for staff selection

## Troubleshooting

### Issue: Pregnant indicator not showing
- Ensure patient is marked as "is_pregnant = 1" in patients table
- Check that weeks_of_pregnancy is set (can be 0 but not NULL)

### Issue: Select Type button not appearing
- Verify user role has access to triage/reception modules
- Ensure checkup_type column exists in patient_visits table
- Check browser console for JavaScript errors

### Issue: Modal not opening
- Ensure Bootstrap is loaded (jQuery and Bootstrap CSS/JS)
- Check browser console for errors
- Verify patient ID and visit ID are correctly passed

## Future Enhancements

1. Add more check-up type options (e.g., "Labor Assessment", "Post-Natal Check")
2. Custom assessment forms based on pregnancy stage
3. Automated alerts for high-risk pregnancies
4. Integration with maternity ward bed management
5. Statistical reporting on check-up type distribution

## Support

For issues or questions about this feature, contact:
- **System Administrator**
- **Maternity Department Lead**
- **Information Technology Support**

---

**Feature Version**: 1.0  
**Last Updated**: 2026-07-22  
**Compatibility**: PHP 7.4+, MySQL 5.7+
