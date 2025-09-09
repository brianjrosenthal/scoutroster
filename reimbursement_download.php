<?php
// Deprecated: Filesystem-based reimbursement downloads have been removed.
// This endpoint is intentionally disabled to prevent use of legacy stored_path.
// Use /secure_file_download.php?id=... which streams from DB-backed secure_files with proper authorization.

http_response_code(410);
header('Content-Type: text/plain; charset=utf-8');
echo "410 Gone: reimbursement_download.php has been removed. Use /secure_file_download.php?id=FILE_ROW_ID.";
exit;
