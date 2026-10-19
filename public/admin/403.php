<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
http_response_code(403);
require dirname(__DIR__, 2) . '/app/views/admin/error-403.php';
