<?php
/**
 * note-delete.php
 * Deletes a single progress note. Only the note owner (nurse_id / doctor_id)
 * or an admin may delete.  Accepts both GET (with confirmation redirect) and
 * POST (direct deletion, e.g. from a JS fetch).
 */
require_once __DIR__ . '/../../config/config.php';
requireRole(['admin', 'nurse', 'doctor']);

$conn          = getDBConnection();
$noteId        = isset($_REQUEST['id'])           ? (int)$_REQUEST['id']           : 0;
$admissionId   = isset($_REQUEST['admission_id']) ? (int)$_REQUEST['admission_id'] : 0;
$currentUserId = isset($_SESSION['user_id'])      ? (int)$_SESSION['user_id']      : 0;

if ($noteId <= 0) {
    setFlashMessage('error', 'Invalid note ID.');
    $conn->close();
    redirect('modules/admission/admissions.php');
}

// Fetch owner info
$stmt = $conn->prepare("SELECT nurse_id, doctor_id, admission_id FROM progress_notes WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $noteId);
$stmt->execute();
$res = $stmt->get_result();
if (!$res || $res->num_rows === 0) {
    setFlashMessage('error', 'Note not found.');
    $stmt->close(); $conn->close();
    redirect($admissionId > 0 ? 'modules/admission/admission-view.php?id=' . $admissionId : 'modules/admission/admissions.php');
}
$noteRow     = $res->fetch_assoc();
$stmt->close();

// Resolve admission_id from note if not supplied via query
if ($admissionId <= 0) {
    $admissionId = (int)$noteRow['admission_id'];
}

$ownerId = (int)($noteRow['nurse_id'] ?? $noteRow['doctor_id'] ?? 0);

// Permission check
if (!hasRole(['admin']) && $ownerId !== $currentUserId) {
    setFlashMessage('error', 'You can only delete your own notes.');
    $conn->close();
    redirect('modules/admission/admission-view.php?id=' . $admissionId);
}

// Perform delete
$del = $conn->prepare("DELETE FROM progress_notes WHERE id = ?");
$del->bind_param('i', $noteId);
if ($del->execute()) {
    logActivity('delete', 'progress_notes', $noteId);
    setFlashMessage('success', 'Progress note deleted.');
} else {
    setFlashMessage('error', 'Failed to delete note: ' . $del->error);
}
$del->close();
$conn->close();

redirect('modules/admission/admission-view.php?id=' . $admissionId);
